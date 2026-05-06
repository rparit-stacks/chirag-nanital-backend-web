<?php

namespace App\Enums;

use ArchTech\Enums\InvokableCases;
use ArchTech\Enums\Names;
use ArchTech\Enums\Values;

/**
 * Enum values for active and inactive status.
 * @method static PENDING()
 * @method static APPLIED()
 * @method static FAILED()
 */
enum SystemUpdateStatusEnum: string
{
    use InvokableCases, Values, Names;


    case PENDING = 'pending';
    case APPLIED = 'applied';
    case FAILED = 'failed';
}
