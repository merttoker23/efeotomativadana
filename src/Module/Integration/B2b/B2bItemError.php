<?php

namespace App\Module\Integration\B2b;

final readonly class B2bItemError
{
    private B2bErrorType $errorType;
    private string $message;
    private ?string $externalId;
    /** @var array<string, bool|int|string|null> */
    private array $context;
    private bool $retryable;

    /** @param array<string, bool|int|string|null> $context */
    public function __construct(
        B2bErrorType $errorType,
        string $message,
        ?string $externalId = null,
        array $context = [],
        bool $retryable = false,
    ) {
        $message = trim($message);
        if ('' === $message) {
            throw new \InvalidArgumentException('B2B item error message must not be empty.');
        }
        $externalId = null === $externalId ? null : trim($externalId);
        if (null !== $externalId && ('' === $externalId || mb_strlen($externalId) > 191)) {
            throw new \InvalidArgumentException('B2B item external ID must contain between 1 and 191 characters.');
        }
        $this->errorType = $errorType;
        $this->message = mb_substr($message, 0, 1000);
        $this->externalId = $externalId;
        $this->context = $context;
        $this->retryable = $retryable;
    }

    public function errorType(): B2bErrorType { return $this->errorType; }
    public function message(): string { return $this->message; }
    public function externalId(): ?string { return $this->externalId; }
    /** @return array<string, bool|int|string|null> */
    public function context(): array { return $this->context; }
    public function retryable(): bool { return $this->retryable; }
}
