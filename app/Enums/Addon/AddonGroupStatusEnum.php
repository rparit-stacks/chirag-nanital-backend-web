<?php

namespace App\Enums\Addon;

use ArchTech\Enums\InvokableCases;
use ArchTech\Enums\Names;
use ArchTech\Enums\Values;

enum AddonGroupStatusEnum: string
{
    use InvokableCases, Values, Names;

    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}
