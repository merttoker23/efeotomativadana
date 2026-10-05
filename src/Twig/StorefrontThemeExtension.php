<?php

namespace App\Twig;

use App\Module\Settings\StoreConfiguration;
use App\Shared\StorefrontFooter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class StorefrontThemeExtension extends AbstractExtension
{
    public function __construct(
        private readonly StoreConfiguration $configuration,
        private readonly StorefrontFooter $footer,
    ) {
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('storefront_colors', $this->configuration->storefrontColors(...)),
            new TwigFunction('storefront_ga4_measurement_id', $this->configuration->ga4MeasurementId(...)),
            new TwigFunction('storefront_cookie_script', $this->configuration->cookieScript(...)),
            // Footer her sayfada çizildiği için veri burada, istek başına bir kez okunur.
            new TwigFunction('storefront_footer', $this->footer->read(...)),
        ];
    }
}
