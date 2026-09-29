<?php

declare(strict_types=1);

namespace App\Shared\Logging;

/**
 * The one place that decides what a secret looks like.
 *
 * Redaction used to live inside `SanitizedFailure`, which meant it protected exactly one
 * thing: the failure metadata a payment provider hands back. Everything that reached a log
 * line, a Messenger failure or an exception message was written unredacted, so a provider
 * that echoes request material into an error string could put a card number into a log file
 * even though the database and the admin screen were clean. Two consumers now share one
 * policy, so a key that is considered a secret for persistence is also considered a secret
 * for logging.
 *
 * The rule is deliberately value-based as well as key-based. A key is the common case and is
 * cheap, but log context is assembled by many hands, and a policy that only recognised keys would
 * leave the free-text values — a provider's error string, an exception message — to chance. The
 * cost of being value-based is that a policy which is too eager destroys the identifiers an
 * operator needs; two such mistakes were made and fixed while writing the tests, and both are
 * documented where the rules live.
 */
final class SecretRedactor
{
    public const string PLACEHOLDER = '[redacted]';

    /**
     * Substrings that mark a secret, plus the spellings real gateways use. Order matters
     * only for readability; the pattern is applied globally, so a longer key and a shorter
     * one that contains it are both replaced.
     *
     * `merchant_key` and its salt are here because this store holds PayTR merchant credentials
     * and they are the one secret it passes around under its own name rather than under a
     * provider-chosen one.
     *
     * @var list<string>
     */
    private const array SECRET_KEYS = [
        'api_key',
        'api_secret',
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
        'merchant_key',
        'merchant_salt',
        'merchantkey',
        'merchantsalt',
        'password',
        'passwd',
        'paytr_token',
        'paytrtoken',
        'private_key',
        'secret',
        'session',
        'signature',
        'token',
        'cvc',
        'cvv',
        'pan',
    ];

    /**
     * Depth cap for the recursive walk over a log context. Context is arbitrary PHP, and a
     * structure that nests deeper than this is not a hand-written log context — walking it
     * unbounded would turn a redaction pass into the denial of service it is meant to prevent.
     */
    public const int MAX_DEPTH = 8;

    /** Below this length a "secret" cannot be a card number and is left readable. */
    private const int MIN_CREDENTIAL_LENGTH = 8;

    public static function isSecretKey(string $key): bool
    {
        $key = mb_strtolower($key);
        foreach (self::SECRET_KEYS as $secret) {
            if (str_contains($key, $secret)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Redact every secret visible in a free-text value.
     *
     * This is what a provider's error string, an exception message or a free-text log value
     * passes through. It is intentionally the same algorithm `SanitizedFailure` has always
     * used, so persisted failure metadata is unchanged in behaviour.
     */
    public static function text(string $value): string
    {
        if ('' === $value) {
            return '';
        }

        // Scheme-prefixed credentials, where the secret follows the scheme rather than a key.
        $value = (string) preg_replace('/\b(bearer|basic|digest)\s+[A-Za-z0-9\-._~+\/=]+/iu', '$1 '.self::PLACEHOLDER, $value);

        // Key/value secrets, however the provider spells the separator. The key may be separated
        // by `_`, `-`, `=`, `:` or a digit straight after it (`pan4111111111111111`).
        //
        // The `0-9` in that class is why it contains a digit at all, and the class is mandatory
        // rather than optional for a reason this phase found the hard way: with the separator
        // optional, the rule ate the tail of ordinary words that merely *contain* a secret-ish
        // one. This application's own `invalid_credentials` reason code was being rewritten to
        // `invalid_credential=[redacted]` — an audit trail that silently corrupts the identifiers
        // it is supposed to preserve. Requiring a separator means a word containing `credential`,
        // `token` or `session` now survives unless it really is followed by a value.
        $keys = implode('|', array_map(strval(...), self::SECRET_KEYS));
        $value = (string) preg_replace(
            '/('.$keys.')([_\-:=\s0-9])\s*(?:[:=]\s*)?("[^"]*"|\'[^\']*\'|[^\s,;]+)/iu',
            '$1$2='.self::PLACEHOLDER,
            $value,
        );

        // Bare 13-19 digit sequences, grouped or not: these are card numbers, not order numbers.
        // Deliberately not word-anchored, so `pan4111111111111111` is caught too.
        return (string) preg_replace('/(?:\d[ -]?){13,19}\b/u', self::PLACEHOLDER, $value);
    }

    /**
     * Redact a value that was not written under a recognisable key.
     *
     * Only two shapes are hidden: something that reads as a bearer/basic credential, and a
     * long opaque token. A short opaque value — an order number, a state name, a count — is
     * left alone, because a log an operator cannot read is not an audit trail.
     */
    public static function opaque(string $value): string
    {
        if (mb_strlen($value) < self::MIN_CREDENTIAL_LENGTH) {
            return $value;
        }
        if (1 === preg_match('/\b(bearer|basic|digest)\s+\S+/iu', $value)) {
            return self::text($value);
        }
        // A long run of token characters with no spaces reads as a machine-issued secret (a
        // signature, a hash, a merchant key). Human-readable log values contain spaces or
        // punctuation, and order/phone/reference numbers are far shorter than this threshold.
        //
        // The character class deliberately excludes `-`. With it included, this very rule ate
        // `EOA-20260929-ABCDEF012345` — a perfectly good order reference — which is the failure
        // mode of an over-eager redactor: a log nobody can read. Real secrets this application
        // handles are hex, base64 or alphanumeric and contain no separator, so nothing real is
        // lost by excluding it.
        if (1 === preg_match('/^[A-Za-z0-9._~+=\/]{24,}$/u', $value)) {
            return self::PLACEHOLDER;
        }

        return self::text($value);
    }

    /**
     * Redact a whole log context, key by key and value by value.
     *
     * Objects are passed through untouched: a Throwable in a context is normalised by Monolog
     * into its own message and stack trace, and this class does not guess what a message means.
     * The call sites in this application log the exception *class* and a redacted message
     * instead, which is why two of them had to change when this policy became shared.
     *
     * @param array<array-key, mixed> $context
     *
     * @return array<array-key, mixed>
     */
    public static function context(array $context): array
    {
        return self::walk($context, 0);
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private static function walk(array $values, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['truncated' => 'context nesting exceeded the redaction depth limit'];
        }

        $redacted = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && self::isSecretKey($key)) {
                $redacted[$key] = self::PLACEHOLDER;
                continue;
            }
            if (is_array($value)) {
                $redacted[$key] = self::walk($value, $depth + 1);
                continue;
            }
            $redacted[$key] = is_string($value) ? self::opaque($value) : $value;
        }

        return $redacted;
    }
}
