<?php

namespace App\Module\Integration\B2b;

enum B2bErrorType: string
{
    case InvalidItem = 'invalid_item';
    case InvalidPrice = 'invalid_price';
    case InvalidStock = 'invalid_stock';
    case Conflict = 'conflict';
    case Image = 'image';
    case Transport = 'transport';
    case Parser = 'parser';
    case Database = 'database';
    case Infrastructure = 'infrastructure';
}
