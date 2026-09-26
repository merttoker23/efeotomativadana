<?php

declare(strict_types=1);

namespace App\Module\Payment;

/**
 * Provider failure metadata reduced to what is safe to persist and display.
 *
 * Payment providers echo request material back in error strings. Nothing that reaches
 * this class is persisted before redaction, so a misbehaving provider cannot write a PAN,
 * CVC, password or bearer token into the database, an admin screen or a log line.
 */
final readonly class SanitizedFailure
{
    public const int MAX_CODE_LENGTH = 80;
    public const int MAX_MESSAGE_LENGTH = 500;

    private const string UNKNOWN_CODE = 'unknown_error';
    private const string PLACEHOLDER = '[redacted]';

    /**
     * Substrings that mark a secret in free-text provider output, plus the spellings real
     * gateways use. Order matters only for readability; the pattern is applied globally, so
     * a longer key and a shorter one that contains it are both replaced.
     *
     * @var list<string>
     */
    private const array SECRET_KEYS = [
        'api_key',
        'apikey',
        'api-key',
        'authorization',
        'card_holder',
        'cardholder',
        'card_number',
        'cardnumber',
        'cookie',
        'credential',
        'cvv2',
        'expiry',
        'hash',
        'hmac',
        'iban',
        'password',
        'passwd',
        'private_key',
        'secret',
        'session',
        'signature',
        'token',
        'cvc',
        'cvv',
        'pan',
    ];

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
        $code = mb_strtolower(self::redact(self::normalize($code)));
        $code = '' === $code ? self::UNKNOWN_CODE : self::truncate($code, self::MAX_CODE_LENGTH, keepTail: true);

        $message = self::redact(self::normalize($message));
        $metadata = self::redact(self::normalize($metadata));
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

    private static function redact(string $value): string
    {
        if ('' === $value) {
            return '';
        }

        // Scheme-prefixed credentials, where the secret follows the scheme rather than a key.
        $value = (string) preg_replace('/\b(bearer|basic|digest)\s+[A-Za-z0-9\-._~+\/=]+/iu', '$1 ' . self::PLACEHOLDER, $value);

        // Key/value secrets, however the provider spells the separator. The key may be
        // separated by `_`, `-` or nothing at all (`apikey`, `x-api-key`, `pan1234`), so the
        // boundary is a non-word character rather than a word boundary.
        $keys = implode('|', array_map(strval(...), self::SECRET_KEYS));
        $value = (string) preg_replace(
            '/(' . $keys . ')([_-]?)\s*[:=]?\s*("[^"]*"|\'[^\']*\'|[^\s,;]+)/iu',
            '$1$2=' . self::PLACEHOLDER,
            $value,
        );

        // Bare 13-19 digit sequences, grouped or not: these are card numbers, not order numbers.
        // Deliberately not word-anchored, so `pan4111111111111111` is caught too.
        $value = (string) preg_replace('/(?:\d[ -]?){13,19}\b/u', self::PLACEHOLDER, $value);

        return $value;
    }
}
