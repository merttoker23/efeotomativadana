<?php

declare(strict_types=1);

namespace App\Module\Shipping;

/**
 * Normalises free text that arrived from outside before anything persists or displays it.
 *
 * A carrier answers with whatever its own system holds: a status string with a newline in the
 * middle, a tracking number padded to four hundred characters, a description carrying an
 * account number. None of it may be trusted to be a single line or a sensible length, so the
 * cleaning happens once, here, rather than in each template that might print it.
 *
 * This is a boundary normaliser, not a sanitiser for HTML. Templates still escape their output;
 * this only guarantees a value is one printable line of bounded length.
 */
final readonly class ShipmentText
{
    /**
     * Longest provider reference. Matches the payment attempt column, so a reference is never
     * truncated into a different reference by the database rather than by this class.
     */
    public const int MAX_REFERENCE_LENGTH = 120;

    /** Longest tracking number shown to a customer or an operator. */
    public const int MAX_TRACKING_LENGTH = 120;

    /** Longest raw provider status kept in the audit trail. */
    public const int MAX_STATUS_LENGTH = 255;

    /** Longest provider description kept as a failure message. */
    public const int MAX_DESCRIPTION_LENGTH = 500;

    /**
     * Zero-width and bidirectional characters, which are invisible in a template and can be used
     * to make one reference look like another.
     */
    private const string INVISIBLE = "\u{200B}-\u{200F}\u{2028}-\u{202E}\u{2060}-\u{2064}\u{FEFF}";

    private function __construct()
    {
    }

    /**
     * Provider identity: a reference, a key, a provider key.
     *
     * Returns null rather than truncating when the value is over-long. A reference is an
     * *identity*: cutting "SHIP-9c1f…" at 120 characters produces a different reference, and every
     * later cancel, status read and label request would then be addressed to a parcel the carrier
     * has never heard of. Refusing is the only safe answer.
     */
    public static function code(?string $value, int $maxLength = self::MAX_REFERENCE_LENGTH): ?string
    {
        $cleaned = self::clean($value, $maxLength);
        if (null === $cleaned) {
            return null;
        }

        return self::overflowed($value, $maxLength) ? null : $cleaned;
    }

    /** A carrier tracking number a human reads. Truncation is safe here: it is display text. */
    public static function tracking(?string $value, int $maxLength = self::MAX_TRACKING_LENGTH): ?string
    {
        return self::clean($value, $maxLength);
    }

    /**
     * A raw provider status, kept for the audit trail.
     *
     * Also refuses rather than truncates. A carrier status is this store's own words once it has
     * been mapped, and a status that has been cut in half still reads as a status — just a
     * different one. It also becomes a cancellation reason on screen, so a plausible-looking
     * fragment is worse than an absent one.
     */
    public static function status(?string $value, int $maxLength = self::MAX_STATUS_LENGTH): ?string
    {
        $cleaned = self::clean($value, $maxLength);
        if (null === $cleaned) {
            return null;
        }

        return self::overflowed($value, $maxLength) ? null : $cleaned;
    }

    /** A provider-supplied description that will be shown to an operator. */
    public static function description(?string $value, int $maxLength = self::MAX_DESCRIPTION_LENGTH): ?string
    {
        return self::clean($value, $maxLength);
    }

    /** Whether the raw value is longer than the bound once whitespace is collapsed. */
    private static function overflowed(?string $value, int $maxLength): bool
    {
        if (null === $value) {
            return false;
        }
        $stripped = (string) preg_replace('/['.self::INVISIBLE.']/u', '', $value);

        return mb_strlen($stripped) > $maxLength;
    }

    private static function clean(?string $value, int $maxLength): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = (string) preg_replace('/[' . self::INVISIBLE.']/u', '', $value);
        $value = (string) preg_replace('/[[:cntrl:]]/u', ' ', $value);
        $value = (string) preg_replace('/\s+/u', ' ', $value);
        $value = trim($value);

        return '' === $value ? null : mb_substr($value, 0, $maxLength);
    }
}
