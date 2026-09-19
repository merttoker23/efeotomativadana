<?php

namespace App\Module\Catalog\Query;

enum CatalogSort: string
{
    case Newest = 'newest';
    case NameAscending = 'name-asc';
    case PriceAscending = 'price-asc';
    case PriceDescending = 'price-desc';
}
