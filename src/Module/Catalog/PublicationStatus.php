<?php

namespace App\Module\Catalog;

enum PublicationStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
