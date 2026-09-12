<?php

namespace App\Module\Catalog;

enum CatalogSource: string
{
    case Local = 'local';
    case External = 'external';
}
