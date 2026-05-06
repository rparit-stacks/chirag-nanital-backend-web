<?php

namespace App\Enums\Referral;

use ArchTech\Enums\InvokableCases;
use ArchTech\Enums\Names;
use ArchTech\Enums\Values;

/**
 * @method static PENDING()
 * @method static SUCCESS()
 * @method static FAILED()
 */
enum ReferralEarningStatusEnum: string
{
    use InvokableCases, Names, Values;

    case PENDING = 'pending';
    case SUCCESS = 'success';
    case FAILED = 'failed';
}
