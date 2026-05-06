<?php

namespace App\Enums\Referral;

use ArchTech\Enums\InvokableCases;
use ArchTech\Enums\Names;
use ArchTech\Enums\Values;

/**
 * @method static REFERRER()
 * @method static REFEREE()
 */
enum ReferralBeneficiaryTypeEnum: string
{
    use InvokableCases, Names, Values;

    case REFERRER = 'referrer';
    case REFEREE = 'referee';
}
