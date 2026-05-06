<?php

namespace App\Enums\Product;

use ArchTech\Enums\InvokableCases;
use ArchTech\Enums\Names;
use ArchTech\Enums\Values;

enum ProductAttachmentModeEnum: string
{
    use InvokableCases, Values, Names;

    case REQUIRED = 'required';
    case OPTIONAL = 'optional';
}