<?php

namespace App\Module\Integration\B2b;

enum B2bResourceType: string
{
    case Product = 'product';
    case Category = 'category';
    case Brand = 'brand';
    case ProductImage = 'product_image';
}
