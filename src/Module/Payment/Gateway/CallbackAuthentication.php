<?php

declare(strict_types=1);

namespace App\Module\Payment\Gateway;

/**
 * The provider's own answer to "was this callback really yours?".
 *
 * A concrete gateway computes its own signature over {@see IncomingCallback::rawBody()};
 * the application never trusts an unauthenticated callback and never re-implements a
 * vendor algorithm.
 */
final readonly class CallbackAuthentication
{
    private function __construct(
        private bool $verified,
        private ?string $reason,
        private ?string $providerReference,
        private ?string $outcome,
        private ?\App\Shared\Money\Money $capturedAmount,
    ) {
    }

    public static function authentic(?string $providerReference = null, ?string $outcome = null, ?\App\Shared\Money\Money $capturedAmount = null): self
    {
        return new self(true, null, self::text($providerReference), self::text($outcome), $capturedAmount);
    }

    public static function rejected(string $reason): self
    {
        $reason = trim($reason);
        if ('' === $reason) {
            throw new \InvalidArgumentException('A rejected callback must state why.');
        }

        return new self(false, $reason, null, null, null);
    }

    public function verified(): bool { return $this->verified; }

    /** Machine-readable rejection reason; null exactly when the callback is authentic. */
    public function reason(): ?string { return $this->reason; }

    public function providerReference(): ?string { return $this->providerReference; }

    /** Provider-neutral outcome token, e.g. 'succeeded', 'failed', 'cancelled'. */
    public function outcome(): ?string { return $this->outcome; }

    public function capturedAmount(): ?\App\Shared\Money\Money { return $this->capturedAmount; }

    private static function text(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
