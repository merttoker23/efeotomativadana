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
            new TwigFunction('storefront_ga4_measurement_id', $this->configuration->ga4MeasurementId(...)),
            new TwigFunction('storefront_cookie_script', $this->configuration->cookieScript(...)),
        ];
    }
}
