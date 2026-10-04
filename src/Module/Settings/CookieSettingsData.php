<?php

namespace App\Module\Settings;

use Symfony\Component\Validator\Constraints as Assert;

final class CookieSettingsData
{
    public function __construct(
        #[Assert\Length(max: 50000)]
        public ?string $script = null,
    ) {
    }
}
