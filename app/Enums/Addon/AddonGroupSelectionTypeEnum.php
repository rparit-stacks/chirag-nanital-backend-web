<?php

namespace App\Enums\Addon;

use ArchTech\Enums\InvokableCases;
use ArchTech\Enums\Names;
use ArchTech\Enums\Values;

enum AddonGroupSelectionTypeEnum: string
{
    use InvokableCases, Values, Names;

    case SINGLE = 'single';
    case MULTIPLE = 'multiple';
}
