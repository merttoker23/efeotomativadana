<?php

declare(strict_types=1);

namespace App\Module\Payment;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\Payment;
use App\Entity\Commerce\PaymentAttempt;
use App\Module\Payment\Gateway\GatewayInitiationInstruction;
use App\Module\Payment\Gateway\PaymentGatewayInterface;
use App\Module\Settings\StoreConfiguration;
use App\Repository\Commerce\PaymentRepository;
use App\Shared\Money\Money;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Starts a payment for an order that already exists locally.
 *
 * The local order is created and committed by checkout first; this service only adds the
 * payment aggregate and talks to the gateway. A gateway that is down therefore costs the
 * customer a retry, never their order.
 */
final readonly class PaymentInitiationService
{
    public function __construct(
        private PaymentGatewayRegistry $gateways,
        private PaymentRepository $payments,
        private EntityManagerInterface $entityManager,
        private StoreConfiguration $configuration,
        private PaymentPublicUrlFactory $urls,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Start (or resume) the payment for an order. Reusing the same idempotency key returns
     * the existing attempt instead of creating a second one, so a double-clicked checkout
     * cannot produce two captures.
     */
    public function start(CustomerOrder $order, ?string $providerKey, ?string $idempotencyKey = null): PaymentStartResult
    {
        $gateway = $this->gateways->resolveOrFail($providerKey);
        $idempotencyKey ??= $this->defaultIdempotencyKey($order);

        return $this->entityManager->wrapInTransaction(function () use ($order, $gateway, $idempotencyKey): PaymentStartResult {
            $payment = $this->payments->findOneForUpdate($order) ?? $this->createPayment($order, $gateway);

            if (null !== ($existing = $this->existingAttempt($payment, $idempotencyKey))) {
                return $this->resumeExisting($existing);
            }

            $attempt = $payment->beginAttempt($idempotencyKey, $order->grandTotal());
            $this->payments->save($payment);
            $this->entityManager->flush();

            $outcome = $gateway->initiate($this->instruction($order, $attempt));
            $this->apply($payment, $attempt, $outcome);
            $this->payments->save($payment);
            $this->entityManager->flush();

            return PaymentStartResult::fromOutcome($outcome);
        });
    }

    /**
     * The orchestration entry point for a freshly placed order. The order is already
     * committed when this runs, which is what keeps the order safe from gateway failures.
     */
    public function startAfterPlacingOrder(CustomerOrder $order, ?string $providerKey = null): PaymentStartResult
    {
        return $this->start($order, $providerKey ?? $this->configuredProviderKey());
    }

    /**
     * Retry a payment that has not been captured. The previous attempt keeps its own state
     * and history; nothing is overwritten.
     *
     * A payment that is not retryable is returned untouched rather than re-driven: this is
     * also the safe landing spot for a double-submitted checkout, so a customer can never
     * end up with two live authorizations for one order.
     */
    /**
     * Retry, or start a payment that was never created.
     *
     * An initiation that failed before the gateway answered leaves the order intact but with
     * no payment record, because the whole initiation is one transaction. The customer is told
     * they can retry, so retrying must be able to create the payment rather than failing with
     * "no payment to retry".
     */
    public function retry(CustomerOrder $order, ?string $providerKey = null): PaymentStartResult
    {
        $gateway = $this->gateways->resolveOrFail($providerKey ?? $this->configuredProviderKey());

        return $this->entityManager->wrapInTransaction(function () use ($order, $gateway): PaymentStartResult {
            $payment = $this->payments->findOneForUpdate($order) ?? $this->createPayment($order, $gateway);
            $latest = $payment->latestAttempt();
            if (null !== $latest && !$payment->state()->canBeRetried()) {
                // Nothing was captured, so a restart is not possible; report what already is.
                return PaymentStartResult::fromOutcome(Gateway\GatewayInitiationOutcome::awaitingCallback($latest->providerReference()));
            }

            $attempt = $payment->beginAttempt($this->defaultIdempotencyKey($order, $payment), $order->grandTotal());
            $this->payments->save($payment);
            $this->entityManager->flush();

            $outcome = $gateway->initiate($this->instruction($order, $attempt));
            $this->apply($payment, $attempt, $outcome);
            $this->payments->save($payment);
            $this->entityManager->flush();

            return PaymentStartResult::fromOutcome($outcome);
        });
    }

    public function paymentFor(CustomerOrder $order): ?Payment
    {
        return $this->payments->findOneForOrder($order);
    }

    /** The attempt a callback or cancel URL addresses, or null when the token is unknown. */
    public function attemptForReturnToken(string $returnToken): ?PaymentAttempt
    {
        $returnToken = trim($returnToken);

        return '' === $returnToken ? null : $this->payments->findAttemptByReturnToken($returnToken);
    }

    /**
     * The customer walked away at the provider. The local payment is cancelled so it stops
     * being offered as retryable, while the order itself stays for staff to resolve.
     */
    public function markAbandoned(PaymentAttempt $attempt): void
    {
        $this->entityManager->wrapInTransaction(function () use ($attempt): void {
            $payment = $attempt->payment();
            if (!$payment->state()->canBeRetried()) {
                return;
            }
            $payment->markCancelled($attempt, 'The customer abandoned the payment at the provider.', \DateTimeImmutable::createFromInterface($this->clock->now()));
            $this->payments->save($payment);
            $this->entityManager->flush();
        });
    }

    private function apply(Payment $payment, PaymentAttempt $attempt, Gateway\GatewayInitiationOutcome $outcome): void
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        if (null !== $outcome->providerReference()) {
            $attempt->assignProviderReference($outcome->providerReference());
        }
        if ($outcome->isFailed()) {
            $payment->markFailed($attempt, $outcome->failure() ?? SanitizedFailure::fromProvider('initiation_failed', 'The provider refused to start the payment.', null), $now);

            return;
        }
        if ($outcome->state() === PaymentState::RequiresAction) {
            $payment->markRequiresAction($attempt, $now);
        }
    }

    /**
     * A repeat of an initiation that already happened.
     *
     * The gateway is deliberately not called again. A hosted page URL is single-use and a
     * second authorization request is how one order ends up captured twice, so a repeated
     * call reports the attempt that is already in flight and lets the customer continue from
     * the provider's own page.
     */
    private function resumeExisting(PaymentAttempt $attempt): PaymentStartResult
    {
        return PaymentStartResult::fromOutcome(
            PaymentState::Failed === $attempt->state()
                ? Gateway\GatewayInitiationOutcome::failed($attempt->failure() ?? SanitizedFailure::fromProvider('initiation_failed', 'The provider refused to start the payment.', null))
                : Gateway\GatewayInitiationOutcome::awaitingCallback($attempt->providerReference()),
        );
    }

    /**
     * Only attempts of this payment are considered. A global lookup by idempotency key could
     * return another order's attempt, and reporting its provider reference back to this caller
     * would leak one order's payment state into another's.
     */
    private function existingAttempt(Payment $payment, string $idempotencyKey): ?PaymentAttempt
    {
        foreach ($payment->attempts() as $attempt) {
            if ($attempt->idempotencyKey() === $idempotencyKey) {
                return $attempt;
            }
        }

        return null;
    }

    private function createPayment(CustomerOrder $order, PaymentGatewayInterface $gateway): Payment
    {
        $payment = Payment::start($order, $gateway->key(), $order->grandTotal(), \DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->payments->save($payment);
        $this->entityManager->flush();

        return $payment;
    }

    private function configuredProviderKey(): ?string
    {
        return $this->configuration->paymentProvider();
    }

    private function defaultIdempotencyKey(CustomerOrder $order, ?Payment $payment = null): string
    {
        $attempt = $payment?->latestAttempt();

        return sprintf('order-%s-attempt-%d', $order->orderNumber(), ($attempt?->sequence() ?? 0) + 1);
    }

    /**
     * The order's own facts, so a provider that requires the customer's name, phone, address or a
     * basket receives them without this module knowing any provider's field names.
     */
    private function instruction(CustomerOrder $order, PaymentAttempt $attempt): GatewayInitiationInstruction
    {
        $address = $order->address(\App\Module\Order\OrderAddressRole::Shipping)
            ?? $order->address(\App\Module\Order\OrderAddressRole::Billing);

        $lines = [];
        foreach ($order->items() as $item) {
            $lines[] = [$item->productName(), $this->decimal($item->unitGross()), $item->quantity()];
        }
        // The provider is paid the whole order total, so a basket that stopped at the items would
        // not add up to what is being charged.
        $shipping = $order->shippingTotal();
        if (!$shipping->isZero()) {
            $lines[] = [$order->shippingOptionLabel(), $this->decimal($shipping), 1];
        }

        return new GatewayInitiationInstruction(
            $order->orderNumber(),
            (string) $attempt->sequence(),
            $attempt->returnToken(),
            $order->grandTotal(),
            $this->urls->absolute('storefront_payment_callback', ['token' => $attempt->returnToken()]),
            $this->urls->absolute('storefront_payment_cancel', ['token' => $attempt->returnToken()]),
            $order->customerEmail(),
            'tr',
            $order->customerName(),
            $order->customerPhone(),
            null === $address ? null : $this->addressLine($address),
            $lines,
        );
    }

    /** A dot-decimal string, because that is the form every documented provider expects. */
    private function decimal(Money $money): string
    {
        return sprintf('%d.%02d', intdiv($money->minorAmount(), 100), $money->minorAmount() % 100);
    }

    private function addressLine(\App\Entity\Commerce\OrderAddress $address): string
    {
        return implode(', ', array_filter([
            $address->addressLine1(),
            $address->addressLine2(),
            $address->district(),
            $address->city(),
        ], static fn (?string $part): bool => null !== $part && '' !== trim($part)));
    }
}
