<?php

namespace App\Enums\Referral;

use ArchTech\Enums\InvokableCases;
use ArchTech\Enums\Names;
use ArchTech\Enums\Values;

/**
 * @method static PENDING()
 * @method static ACTIVE()
 * @method static COMPLETED()
 */
enum ReferralStatusEnum: string
{
    use InvokableCases, Names, Values;

    case PENDING = 'pending';
    case ACTIVE = 'active';
    case COMPLETED = 'completed';
}
