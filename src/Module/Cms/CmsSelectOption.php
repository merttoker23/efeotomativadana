<?php

declare(strict_types=1);

namespace App\Module\Cms;

/**
 * One selectable catalog entry for a homepage section form.
 *
 * The section form never receives a slug from the browser. A slug is a technical identifier that
 * only the catalogue may mint, so an administrator picks a labelled row here and the server
 * resolves the slug itself; that is what makes "type the slug by hand" structurally impossible
 * rather than merely discouraged.
 */
final readonly class CmsSelectOption
{
    public function __construct(
        public string $slug,
        public string $label,
        public string $hint = '',
    ) {}
}
