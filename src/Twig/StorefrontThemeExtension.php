<?php

namespace App\Twig;

use App\Module\Settings\StoreConfiguration;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class StorefrontThemeExtension extends AbstractExtension
{
    public function __construct(private readonly StoreConfiguration $configuration)
    {
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('storefront_colors', $this->configuration->storefrontColors(...)),
        ];
    }
}
