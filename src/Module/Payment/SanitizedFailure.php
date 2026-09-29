<?php

declare(strict_types=1);

namespace App\Module\Payment;

use App\Shared\Logging\SecretRedactor;

/**
 * Provider failure metadata reduced to what is safe to persist and display.
 *
 * Payment providers echo request material back in error strings. Nothing that reaches
 * this class is persisted before redaction, so a misbehaving provider cannot write a PAN,
 * CVC, password or bearer token into the database, an admin screen or a log line.
 *
 * The redaction itself belongs to {@see SecretRedactor}: the same policy decides what is a
 * secret for persistence and for logging, so the two can never drift apart.
 */
final readonly class SanitizedFailure
{
    public const int MAX_CODE_LENGTH = 80;
    public const int MAX_MESSAGE_LENGTH = 500;

    private const string UNKNOWN_CODE = 'unknown_error';

    /** @var list<string> */
    private const array RETRYABLE_CODES = [
        'connection_error',
        'gateway_timeout',
        'internal_error',
        'rate_limited',
        'service_unavailable',
        'timeout',
        'temporarily_unavailable',
        'try_again',
    ];

    private function __construct(
        private string $code,
        private string $message,
    ) {
    }

    public static function fromProvider(?string $code, ?string $message, ?string $metadata = null): self
    {
        // Codes are compared against the retryable taxonomy, so they are case-folded, and they
        // are redacted like messages: a provider that puts a card number in its error code must
        // not be able to reach the database or an admin screen through that field either.
        $code = mb_strtolower(SecretRedactor::text(self::normalize($code)));
        $code = '' === $code ? self::UNKNOWN_CODE : self::truncate($code, self::MAX_CODE_LENGTH, keepTail: true);

        $message = SecretRedactor::text(self::normalize($message));
        $metadata = SecretRedactor::text(self::normalize($metadata));
        if ('' === $message) {
            $message = $metadata;
        }

        return new self($code, self::truncate($message, self::MAX_MESSAGE_LENGTH));
    }

    public function code(): string
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function isRetryable(): bool
    {
        return in_array($this->code, self::RETRYABLE_CODES, true);
    }

    private static function normalize(?string $value): string
    {
        $value = (string) $value;
        $value = str_replace(["\r\n", "\r", "\n"], '', $value);
        $value = (string) preg_replace('/[[:cntrl:]]/u', ' ', $value);
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    private static function truncate(string $value, int $limit, bool $keepTail = false): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        if ($keepTail) {
            return self::UNKNOWN_CODE === $value ? $value : '...' . mb_substr($value, -($limit - 3));
        }

        return mb_substr($value, 0, $limit);
    }
}
