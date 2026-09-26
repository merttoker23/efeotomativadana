<?php

declare(strict_types=1);

namespace App\Module\Payment\Gateway;

use App\Module\Payment\PaymentState;
use App\Module\Payment\SanitizedFailure;

/**
 * What the gateway says happened when a payment was started.
 *
 * The four shapes cover hosted redirect, hosted form, tokenized/SDK collection and an
 * immediate failure, so a concrete adapter in PHASE_15 maps its own vocabulary onto these
 * without the checkout controller learning anything vendor-specific.
 */
final readonly class GatewayInitiationOutcome
{
    /**
     * @param array<string, string> $hostedFormFields
     */
    private function __construct(
        private string $kind,
        private ?string $redirectUrl,
        private ?string $hostedFormActionUrl,
        private array $hostedFormFields,
        private ?string $providerReference,
        private PaymentState $state,
        private ?SanitizedFailure $failure,
    ) {
    }

    /** The customer must be sent to the provider's hosted page. */
    public static function redirect(string $redirectUrl, ?string $providerReference = null): self
    {
        return new self('redirect', self::httpsUrl($redirectUrl, 'redirect'), null, [], self::reference($providerReference), PaymentState::RequiresAction, null);
    }

    /** The customer must post the given fields to a provider-hosted form. */
    /** @param array<string, string> $fields */
    public static function hostedForm(string $actionUrl, array $fields, ?string $providerReference = null): self
    {
        if ([] === $fields) {
            throw new \InvalidArgumentException('Gateway hosted form requires at least one field.');
        }
        foreach ($fields as $name => $value) {
            if ('' === trim($name)) {
                throw new \InvalidArgumentException('Gateway hosted form fields must be named.');
            }
        }

        return new self('hosted_form', null, self::httpsUrl($actionUrl, 'hosted form'), array_map(strval(...), $fields), self::reference($providerReference), PaymentState::RequiresAction, null);
    }

    /** The provider accepted the request and will report the outcome through a callback. */
    public static function awaitingCallback(?string $providerReference = null): self
    {
        return new self('awaiting_callback', null, null, [], self::reference($providerReference), PaymentState::Pending, null);
    }

    public static function failed(SanitizedFailure $failure): self
    {
        return new self('failed', null, null, [], null, PaymentState::Failed, $failure);
    }

    public function isRedirect(): bool { return 'redirect' === $this->kind; }

    public function isHostedForm(): bool { return 'hosted_form' === $this->kind; }

    public function isAwaitingCallback(): bool { return 'awaiting_callback' === $this->kind; }

    public function isFailed(): bool { return 'failed' === $this->kind; }

    public function requiresCustomerRedirect(): bool { return $this->isRedirect() || $this->isHostedForm(); }

    public function redirectUrl(): ?string { return $this->redirectUrl; }

    public function hostedFormActionUrl(): ?string { return $this->hostedFormActionUrl; }

    /** @return array<string, string> */
    public function hostedFormFields(): array { return $this->hostedFormFields; }

    public function providerReference(): ?string { return $this->providerReference; }

    public function state(): PaymentState { return $this->state; }

    public function failure(): ?SanitizedFailure { return $this->failure; }

    private static function httpsUrl(string $url, string $role): string
    {
        $url = trim($url);
        if (false === filter_var($url, FILTER_VALIDATE_URL) || 'https' !== parse_url($url, PHP_URL_SCHEME)) {
            throw new \InvalidArgumentException(sprintf('Gateway %s URL must be an absolute https URL.', $role));
        }

        return $url;
    }

    private static function reference(?string $providerReference): ?string
    {
        if (null === $providerReference) {
            return null;
        }
        $providerReference = trim($providerReference);
        if ('' === $providerReference) {
            throw new \InvalidArgumentException('Gateway provider reference cannot be blank.');
        }

        return $providerReference;
    }
}
