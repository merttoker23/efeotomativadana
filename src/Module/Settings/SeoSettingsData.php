<?php

namespace App\Module\Settings;

use Symfony\Component\Validator\Constraints as Assert;

final class SeoSettingsData
{
    public function __construct(
        public bool $seoIndexingEnabled = true,
        #[Assert\Length(max: 500)]
        public ?string $seoDefaultDescription = null,
    ) {
    }
}
