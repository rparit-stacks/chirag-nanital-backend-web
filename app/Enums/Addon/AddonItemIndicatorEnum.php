<?php

namespace App\Enums\Addon;

use ArchTech\Enums\InvokableCases;
use ArchTech\Enums\Names;
use ArchTech\Enums\Values;

enum AddonItemIndicatorEnum: string
{
    use InvokableCases, Values, Names;

    case VEG = 'veg';
    case NON_VEG = 'non_veg';
}
