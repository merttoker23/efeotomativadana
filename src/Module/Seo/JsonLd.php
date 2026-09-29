<?php

declare(strict_types=1);

namespace App\Module\Seo;

/**
 * Encodes a JSON-LD graph for embedding inside a <script type="application/ld+json"> element.
 *
 * The data behind these graphs is catalogue data: a product name arrives from the admin panel
 * and, for a B2B-synchronised product, from a third-party feed. Emitted with plain
 * `json_encode`, a name containing "</script>" would close the element and turn a product
 * description into markup the browser executes. JSON_HEX_TAG turns every angle bracket into
 * its escape sequence, which JSON parses back to the original character, so the payload still
 * round-trips exactly while being unable to terminate the element it sits in.
 *
 * JSON_UNESCAPED_SLASHES is deliberately NOT set: a literal "</script>" is the one sequence
 * that has to stay unreachable in the encoded text even if a future flag change removes the
 * hex escaping.
 */
final class JsonLd
{
    public const CONTEXT = 'https://schema.org';

    /**
     * @param array<string, mixed> $graph
     *
     * @throws \JsonException when the graph is not valid UTF-8 or cannot be encoded
     */
    public static function encode(array $graph): string
    {
        $payload = ['@context' => self::CONTEXT] + $graph;

        return json_encode(
            $payload,
            \JSON_THROW_ON_ERROR
            |\JSON_UNESCAPED_UNICODE
            |\JSON_HEX_TAG
            |\JSON_HEX_AMP
            |\JSON_HEX_APOS
            |\JSON_HEX_QUOT,
        );
    }
}
