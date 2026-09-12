<?php

namespace App\Module\Catalog;

enum ProductIdentifierType: string
{
    case Manufacturer = 'manufacturer';
    case Oem = 'oem';
    case Reference = 'reference';
}
