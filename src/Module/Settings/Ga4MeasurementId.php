<?php

namespace App\Module\Settings;

final class Ga4MeasurementId
{
    public const string PATTERN = '/\AG-[A-Z0-9]{10}\z/';

    public static function isValid(?string $value): bool
    {
        return null !== $value && 1 === preg_match(self::PATTERN, $value);
    }

    /** Invalid input stays intact so the settings validator can reject it. Never execute HTML. */
    public static function normalize(?string $value): ?string
    {
        $value = trim($value ?? '');
        if ('' === $value) {
            return null;
        }
        $id = strtoupper($value);
        if (self::isValid($id)) {
            return $id;
        }

        // Accept the standard Google snippet only when its loader and config agree on one ID.
        if (preg_match('~<script\b[^>]*\bsrc\s*=\s*[\'"]https://www\.googletagmanager\.com/gtag/js\?id=(G-[A-Z0-9]{10})[\'"]~i', $value, $loader)
            && preg_match('~gtag\s*\(\s*[\'"]config[\'"]\s*,\s*[\'"](G-[A-Z0-9]{10})[\'"]\s*[,)]~i', $value, $config)) {
            preg_match_all('/\bG-[A-Z0-9]+\b/i', $value, $ids);
            $unique = array_unique(array_map(strtoupper(...), $ids[0]));
            if (1 === count($unique) && strtoupper($loader[1]) === strtoupper($config[1])) {
                return strtoupper($loader[1]);
            }
        }

        return $value;
    }
}
