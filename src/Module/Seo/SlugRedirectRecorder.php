<?php

declare(strict_types=1);

namespace App\Module\Seo;

use App\Entity\Seo\SeoResourceType;
use App\Entity\Seo\SlugRedirect;
use App\Repository\Seo\SlugRedirectRepository;

/**
 * The one writer of redirect history.
 *
 * Callers pass facts — "this was published, this was its old slug, this is its new one" — and
 * this service decides whether that history is worth keeping. Deciding it here rather than in
 * each admin controller is what stops the catalogue, the CMS and a future provider adapter
 * from each answering the question slightly differently, which is how a published URL gets
 * renamed with nothing recording it.
 *
 * It records nothing for a draft, and nothing when the slug did not actually change: a draft
 * was never at a public address, so an entry for it would be a redirect to a URL that never
 * existed.
 */
final readonly class SlugRedirectRecorder
{
    public function __construct(
        private SlugRedirectRepository $redirects,
    ) {
    }

    public function record(
        SeoResourceType $resourceType,
        int $resourceId,
        bool $wasPublished,
        string $oldSlug,
        string $newSlug,
    ): ?SlugRedirect {
        $oldSlug = mb_strtolower(trim($oldSlug));
        $newSlug = mb_strtolower(trim($newSlug));
        if (!$wasPublished || '' === $oldSlug || $oldSlug === $newSlug) {
            return null;
        }

        $existing = $this->redirects->findRetired($resourceType, $oldSlug);
        if (null !== $existing) {
            // The same address was retired before and has been handed out again. Only the most
            // recent owner can answer for it, so the history follows the slug rather than
            // keeping an entry that points at a record which no longer has this address.
            $existing->retire($resourceType, $resourceId, $oldSlug);

            return $existing;
        }

        $redirect = new SlugRedirect($resourceType, $resourceId, $oldSlug);
        $this->redirects->save($redirect);

        return $redirect;
    }
}
