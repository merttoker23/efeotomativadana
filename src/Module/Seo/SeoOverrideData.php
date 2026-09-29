<?php

declare(strict_types=1);

namespace App\Module\Seo;

use App\Entity\Seo\SeoOverride;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The merchant's corrections, as a form.
 *
 * All three fields are optional and every one of them is independent: fixing one awkward
 * product title must not oblige a merchant to also write a description and decide an index
 * flag. A blank submission carries no information and therefore stores nothing, which is what
 * keeps the storefront's own fallback reachable instead of replaced by an empty string.
 */
final class SeoOverrideData
{
    #[Assert\Length(max: SeoOverride::TITLE_LIMIT)]
    public ?string $metaTitle = null;

    #[Assert\Length(max: SeoOverride::DESCRIPTION_LIMIT)]
    public ?string $metaDescription = null;

    public bool $noIndex = false;

    public static function fromOverride(SeoOverride $override): self
    {
        $data = new self();
        $data->metaTitle = $override->title();
        $data->metaDescription = $override->description();
        $data->noIndex = $override->noIndex();

        return $data;
    }

    public function isEmpty(): bool
    {
        return null === self::clean($this->metaTitle)
            && null === self::clean($this->metaDescription)
            && false === $this->noIndex;
    }

    public function applyTo(SeoOverride $override): SeoOverride
    {
        $override->retitle($this->metaTitle);
        $override->redescribe($this->metaDescription);
        $override->hideFromIndex($this->noIndex);

        return $override;
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
