<?php

declare(strict_types=1);

namespace App\Module\Returns;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\OrderItem;
use App\Entity\Commerce\ReturnRequest;
use App\Entity\Customer\CustomerUser;
use App\Module\Audit\AuditAction;
use App\Module\Audit\AuditLogger;
use App\Module\Notification\Event\ReturnStateChanged;
use App\Module\Order\OrderNotFound;
use App\Module\Order\OrderRepositoryInterface;
use App\Repository\Commerce\ReturnRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Everything a return request may be asked to do, and the only place the rules are applied.
 *
 * The aggregate refuses nonsense about *itself*; this service refuses nonsense about the *world*:
 * that the order is one this customer placed, that it is returnable at all, that the lines belong
 * to it, and that the quantities are not already spoken for.
 *
 * Every write happens inside one transaction against a freshly locked row, so two operators — or an
 * operator and a customer withdrawing at the same moment — are serialised and the second sees the
 * first one's committed state rather than its own stale screen.
 *
 * Every decision is additionally written to the cross-cutting audit trail. The `ReturnEvent`
 * trail beside the aggregate records what the request became; the audit row records who decided,
 * which for an approval or a rejection is the fact a customer will eventually ask about.
 */
final readonly class ReturnService
{
    public function __construct(
        private ReturnRequestRepository $returns,
        private OrderRepositoryInterface $orders,
        private ReturnPolicy $policy,
        private ReturnNumberGenerator $numbers,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private EventDispatcherInterface $events,
        private AuditLogger $audit,
    ) {
    }

    /**
     * Open a return for an order this customer placed.
     *
     * The order is looked up *by number and by customer together*, so a customer who guesses another
     * order's number gets the same answer as one who invents it, and cannot tell the two apart.
     *
     * @param list<ReturnLine> $lines
     */
    public function request(CustomerUser $customer, string $orderNumber, array $lines, string $customerReason): ReturnRequest
    {
        if ([] === $lines) {
            throw new \DomainException('Bir iade talebi en az bir ürün içermelidir.');
        }
        $order = $this->orders->findOneByNumberForCustomer($orderNumber, $customer)
            ?? throw new OrderNotFound(sprintf('Order %s was not found.', $orderNumber));

        return $this->entityManager->wrapInTransaction(function () use ($order, $customer, $lines, $customerReason): ReturnRequest {
            $reason = $this->policy->whyNotReturnable($order, $this->now());
            if (null !== $reason) {
                throw OrderNotReturnable::for($order, $reason);
            }

            // Read the claimed quantities inside the transaction and again after locking, so two
            // requests for the last unit cannot both see it as free.
            $claimed = $this->returns->claimedQuantitiesForOrder($order);
            if (0 === $this->policy->returnableQuantityForOrder($order, $claimed)) {
                throw OrderNotReturnable::for($order, ReturnIneligibility::NothingLeftToReturn);
            }

            $return = ReturnRequest::open($order, $this->numbers->generate(), $this->now(), $customerReason, $customer->getUserIdentifier());

            $added = [];
            foreach ($lines as $line) {
                $item = $this->assertLineBelongsToOrder($order, $line);
                // Checked here rather than left to the aggregate, whose refusal `addItemFromOrder`
                // absorbs: a line submitted twice is a form mistake the customer has to be told
                // about, not something to drop quietly and report as accepted.
                if (isset($added[(int) $item->id()])) {
                    throw ReturnLineDuplicated::forItem((int) $item->id());
                }
                $available = $this->policy->returnableQuantityFor($order, $item, $claimed);
                if ($line->quantity() > $available) {
                    throw ReturnQuantityExceeded::forLine((int) $item->id(), $line->quantity(), $available);
                }
                $return->addItemFromOrder($item, $line->quantity(), $line->reason());
                $added[(int) $item->id()] = true;
                $claimed[(int) $item->id()] = ($claimed[(int) $item->id()] ?? 0) + $line->quantity();
            }

            $this->returns->save($return);
            $this->audit->record(AuditAction::ReturnRequested, $return->returnNumber(), [
                'order_number' => $order->orderNumber(),
                'lines' => count($return->items()),
            ]);
            $this->entityManager->flush();

            // Raised after the transaction, so a subscriber can never read a row that is about to be
            // rolled back. A subscriber that fails here cannot un-place the request.
            $this->events->dispatch(new ReturnStateChanged($return));

            return $return;
        });
    }

    /**
     * Whether an order can be returned right now, and if not, why.
     *
     * The account screen asks this before offering the button, so the customer is told the reason
     * instead of discovering it by being refused.
     *
     * @return array{0: bool, 1: ReturnIneligibility|null}
     */
    public function eligibilityOf(CustomerOrder $order): array
    {
        $reason = $this->policy->whyNotReturnable($order, $this->now());
        if (null !== $reason) {
            return [false, $reason];
        }

        $claimed = $this->returns->claimedQuantitiesForOrder($order);

        return 0 === $this->policy->returnableQuantityForOrder($order, $claimed)
            ? [false, ReturnIneligibility::NothingLeftToReturn]
            : [true, null];
    }

    /** How many units of an order line are still free to be returned. */
    public function returnableQuantityFor(CustomerOrder $order, OrderItem $item): int
    {
        return $this->policy->returnableQuantityFor($order, $item, $this->returns->claimedQuantitiesForOrder($order));
    }

    /**
     * The quantity of each order line an open or approved return already holds.
     *
     * A plain read, deliberately: this runs while a form is being rendered, and taking a row lock
     * to draw a page would fail outright outside a transaction and serialise two customers opening
     * the same order for no reason. The ceiling is re-checked under the lock inside
     * {@see request()}, which is the only place a decision is actually made.
     *
     * @return array<int, int>
     */
    public function claimedQuantities(CustomerOrder $order): array
    {
        return $this->returns->claimedQuantitiesForOrder($order);
    }

    /** A return addressed by its number, scoped to its customer. */
    public function findForCustomer(string $returnNumber, CustomerUser $customer): ?ReturnRequest
    {
        return $this->returns->findOneByNumberForCustomer($returnNumber, $customer);
    }

    /** @return ReturnPage<ReturnRequest> */
    public function pageForCustomer(CustomerUser $customer, int $page, int $perPage = 10): ReturnPage
    {
        return $this->returns->customerPage($customer, $page, $perPage);
    }

    public function approve(ReturnRequest $return, string $staffNote, string $actorEmail): ReturnRequest
    {
        return $this->apply($return, AuditAction::ReturnApproved, ['staff_note' => $staffNote], function (ReturnRequest $locked) use ($staffNote, $actorEmail): void {
            $locked->approve($staffNote, $this->now(), $actorEmail);
        });
    }

    public function reject(ReturnRequest $return, string $staffNote, string $actorEmail): ReturnRequest
    {
        return $this->apply($return, AuditAction::ReturnRejected, ['staff_note' => $staffNote], function (ReturnRequest $locked) use ($staffNote, $actorEmail): void {
            $locked->reject($staffNote, $this->now(), $actorEmail);
        });
    }

    public function markReceived(ReturnRequest $return, string $actorEmail): ReturnRequest
    {
        return $this->apply($return, AuditAction::ReturnReceived, [], function (ReturnRequest $locked) use ($actorEmail): void {
            $locked->markReceived($this->now(), $actorEmail);
        });
    }

    /**
     * Record a refund the payment module already issued.
     *
     * This service never issues one. A return aggregate that could call a payment provider would be
     * a return aggregate that could move money, and "the store said yes to a return" and "the
     * customer's money is back" are different facts that must not be conflated.
     */
    public function recordRefund(ReturnRequest $return, int $amountMinor, string $currency, string $refundReference, string $actorEmail): ReturnRequest
    {
        return $this->apply(
            $return,
            AuditAction::ReturnRefundRecorded,
            ['amount_minor' => $amountMinor, 'currency' => $currency, 'refund_reference' => $refundReference],
            function (ReturnRequest $locked) use ($amountMinor, $currency, $refundReference, $actorEmail): void {
                $locked->markRefunded($amountMinor, $currency, $refundReference, $this->now(), $actorEmail);
            },
        );
    }

    public function withdraw(ReturnRequest $return): ReturnRequest
    {
        return $this->apply($return, AuditAction::ReturnWithdrawn, [], function (ReturnRequest $locked): void {
            $locked->withdraw($this->now(), $locked->customer()->getUserIdentifier());
        });
    }

    /**
     * Re-reads the return under a row lock and applies one change inside a transaction.
     *
     * The caller's copy only says *which* return; every decision is made from the freshly locked
     * row, so an operator's stale screen cannot overwrite a decision someone else already made.
     *
     * @param callable(ReturnRequest): void                $change
     * @param array<string, mixed>                         $payload
     */
    private function apply(ReturnRequest $return, AuditAction $action, array $payload, callable $change): ReturnRequest
    {
        $id = (int) $return->id();

        $applied = $this->entityManager->wrapInTransaction(function () use ($id, $action, $payload, $change): ReturnRequest {
            $locked = $this->returns->findOneForUpdate($id);
            if (null === $locked) {
                throw new \RuntimeException(sprintf('Return %d was not found.', $id));
            }
            $change($locked);
            $this->returns->save($locked);
            $this->audit->record($action, $locked->returnNumber(), $payload);
            $this->entityManager->flush();

            return $locked;
        });

        // After the commit, for the same reason as request(): a subscriber must never be able to
        // fail the state change it is only reporting on.
        $this->events->dispatch(new ReturnStateChanged($applied));

        return $applied;
    }

    /**
     * The submitted line's order item, but only if it belongs to this order.
     *
     * Resolved through the order rather than trusted, so a hand-posted `order_item_id` naming
     * another order's line is refused instead of quietly returning somebody else's goods.
     */
    private function assertLineBelongsToOrder(CustomerOrder $order, ReturnLine $line): OrderItem
    {
        $submitted = $line->orderItem();
        foreach ($order->items() as $item) {
            if ($item === $submitted) {
                return $item;
            }
        }

        throw ReturnLineNotInOrder::forItem((int) $submitted->id());
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
