<?php

declare(strict_types=1);

namespace App\Module\Loyalty;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Customer\AdminUser;
use App\Entity\Customer\CustomerUser;
use App\Entity\Loyalty\RewardTransaction;
use App\Module\Audit\AuditAction;
use App\Module\Audit\AuditActorType;
use App\Module\Audit\AuditContext;
use App\Module\Audit\AuditLogger;
use App\Module\Order\OrderState;
use App\Module\Payment\PaymentState;
use App\Module\Settings\StoreConfiguration;
use App\Repository\Commerce\PaymentRepository;
use App\Repository\Loyalty\RewardTransactionRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/** Ledger changes commit with the payment/refund/cancellation that caused them. */
final readonly class RewardService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RewardTransactionRepository $ledger,
        private PaymentRepository $payments,
        private StoreConfiguration $settings,
        private RewardCalculation $calculation,
        private AuditLogger $audit,
        private ClockInterface $clock,
    ) {
    }

    public function balance(CustomerUser $customer): int { return $this->ledger->balance($customer); }
    public function availableBalance(CustomerUser $customer): int { return max(0, $this->balance($customer)); }

    /** Order -> payment -> customer is the shared locking order for commerce hooks. */
    public function synchronize(CustomerOrder $order): void
    {
        $this->entityManager->wrapInTransaction(function () use ($order): void {
            $this->entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE);
            $payment = $this->payments->findOneForUpdate($order);
            $this->lockWriter();
            $customer = $order->customer();
            $this->entityManager->refresh($customer, LockMode::PESSIMISTIC_WRITE);
            $source = 'earn:'.$order->orderNumber();
            $earned = $this->ledger->sourceForUpdate($source);
            if (null === $earned) {
                // A mismatch is real refundable money, but does not settle this order.
                if (!$this->settings->isLoyaltyEnabled() || null === $payment || PaymentState::Succeeded !== $payment->state() || OrderState::Cancelled === $order->state()) {
                    return;
                }
                $eligible = $order->subtotal()->minorAmount(); // Gross merchandise, no shipping or processor interest.
                $rate = $this->settings->loyaltyEarnPercentage();
                $earned = new RewardTransaction($customer, $order, RewardKind::Earn, $this->calculation->earned($eligible, $rate), $source, 'Payment received; merchandise total excluding shipping.', null, $eligible, $rate, $this->now());
                $this->entityManager->persist($earned);
                $this->entityManager->flush();
            }
            $eligible = $earned->eligibleMinor() ?? 0;
            $rate = $earned->earnPercentage() ?? 0;
            // Refund amounts are applied to merchandise first, then shipping/processor interest.
            $target = OrderState::Cancelled === $order->state()
                ? $earned->points()
                : $this->calculation->reversed($eligible, $rate, $payment?->refundedAmount()->minorAmount() ?? 0);
            $delta = min($earned->points(), $target) - $this->ledger->reversedForUpdate($order);
            if ($delta <= 0) {
                return;
            }
            $this->entityManager->persist(new RewardTransaction($customer, $order, RewardKind::Reversal, -$delta, 'reverse:'.$order->orderNumber().':'.$target, 'Cancellation or cumulative completed refund.', null, $eligible, $rate, $this->now()));
            // A reversal may create debt after a prior manual debit. Never block a real refund;
            // available balance is floored at zero and later earning offsets the debt.
            $this->entityManager->flush();
        });
    }

    public function adjust(CustomerUser $customer, int $points, string $reason, AdminUser $actor, string $requestKey): RewardTransaction
    {
        $reason = trim($reason);
        if (null === $actor->id() || null === $customer->id() || 0 === $points || abs($points) > 1_000_000_000 || '' === $reason || mb_strlen($reason) > 255 || 1 !== preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $requestKey)) {
            throw new \InvalidArgumentException('Adjustment requires persisted identities, nonzero bounded points, a reason and a request key.');
        }
        return $this->entityManager->wrapInTransaction(function () use ($customer, $points, $reason, $actor, $requestKey): RewardTransaction {
            $this->lockWriter();
            $this->entityManager->refresh($customer, LockMode::PESSIMISTIC_WRITE);
            $source = 'manual:'.$customer->id().':'.$requestKey;
            $existing = $this->ledger->sourceForUpdate($source);
            if (null !== $existing) {
                if ($existing->points() !== $points || $existing->reason() !== $reason || $existing->actorEmail() !== $actor->getUserIdentifier()) {
                    throw new \DomainException('Adjustment request key was reused with different data.');
                }
                return $existing;
            }
            if ($points < 0 && $this->ledger->balance($customer, true) + $points < 0) {
                throw new \DomainException('Adjustment would make the reward balance negative.');
            }
            $entry = new RewardTransaction($customer, null, RewardKind::ManualAdjustment, $points, $source, $reason, $actor->getUserIdentifier(), null, null, $this->now());
            $this->entityManager->persist($entry);
            $this->audit->record(AuditAction::RewardManuallyAdjusted, (string) $customer->id(), ['points' => $points, 'reason' => $reason, 'source_key' => $source], context: new AuditContext(AuditActorType::Administrator, $actor->getUserIdentifier(), null, null));
            $this->entityManager->flush();
            return $entry;
        });
    }

    private function now(): \DateTimeImmutable { return \DateTimeImmutable::createFromInterface($this->clock->now()); }

    private function lockWriter(): void
    {
        // Customer locks alone do not protect unrelated customers' missing source keys:
        // MySQL repeatable-read gap locks can coexist, then deadlock when both insert.
        // This one-row gate lasts until the outer commerce transaction commits. Reads stay
        // concurrent; writes deliberately serialize without changing global DB isolation.
        if (false === $this->entityManager->getConnection()->fetchOne('SELECT id FROM loyalty_ledger_lock WHERE id = 1 FOR UPDATE')) {
            throw new \LogicException('Reward writer gate is missing; apply database migrations.');
        }
    }
}
