<?php

namespace App\Enums;

use ArchTech\Enums\InvokableCases;
use ArchTech\Enums\Names;
use ArchTech\Enums\Values;

/**
 * @method static string FORCE_UPDATE()
 * @method static string SOFT_UPDATE()
 */
enum UpdateTypeEnum: string
{
    use InvokableCases, Values, Names;

    case FORCE_UPDATE = 'force_update';
    case SOFT_UPDATE = 'soft_update';
}
