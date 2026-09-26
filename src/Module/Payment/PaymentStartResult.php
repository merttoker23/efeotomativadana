<?php

declare(strict_types=1);

namespace App\Module\Payment;

use App\Module\Payment\Gateway\GatewayInitiationOutcome;

/**
 * What the store should do next after an initiation attempt: send the customer to a URL,
 * post a hosted form, wait for a callback, or show that the attempt already failed.
 *
 * It carries the gateway's own outcome plus the store-local URLs the customer comes back to,
 * so no controller has to assemble a return URL itself.
 */
final readonly class PaymentStartResult
{
    private function __construct(
        private GatewayInitiationOutcome $outcome,
        private ?string $redirectUrl,
    ) {
    }

    public static function fromOutcome(GatewayInitiationOutcome $outcome): self
    {
        $redirectUrl = match (true) {
            $outcome->isRedirect() => $outcome->redirectUrl(),
            $outcome->isHostedForm() => $outcome->hostedFormActionUrl(),
            default => null,
        };

        return new self($outcome, $redirectUrl);
    }

    public function outcome(): GatewayInitiationOutcome { return $this->outcome; }

    public function requiresRedirect(): bool { return $this->outcome->requiresCustomerRedirect(); }

    public function isRedirect(): bool { return $this->outcome->isRedirect(); }

    public function isHostedForm(): bool { return $this->outcome->isHostedForm(); }

    public function isFailed(): bool { return $this->outcome->isFailed(); }

    /** The absolute https URL the customer's browser must be sent to, for either form. */
    public function redirectUrl(): ?string { return $this->redirectUrl; }

    /** @return array<string, string> */
    public function hostedFormFields(): array { return $this->outcome->hostedFormFields(); }

    public function providerReference(): ?string { return $this->outcome->providerReference(); }
}
