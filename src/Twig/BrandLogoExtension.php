<?php

declare(strict_types=1);

namespace App\Twig;

use App\Module\Catalog\BrandLogoStorage;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class BrandLogoExtension extends AbstractExtension
{
    public function __construct(private readonly BrandLogoStorage $logos, private readonly StorefrontMediaExtension $media)
    {
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [new TwigFunction('brand_logo_url', $this->logoUrl(...))];
    }

    public function logoUrl(int $brandId): string
    {
        $uploaded = $this->logos->uploadedPath($brandId);

        return null === $uploaded ? '/img/ureticiler/'.$brandId.'.jpg' : $this->media->mediaUrl($uploaded);
    }
}
