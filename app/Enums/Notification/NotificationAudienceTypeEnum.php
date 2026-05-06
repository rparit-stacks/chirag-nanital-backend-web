<?php

namespace App\Enums\Notification;

use ArchTech\Enums\InvokableCases;
use ArchTech\Enums\Names;
use ArchTech\Enums\Values;

/**
 * @method static CUSTOMER()
 * @method static SELLER()
 * @method static RIDER()
 */
enum NotificationAudienceTypeEnum: string
{
    use InvokableCases, Values, Names;

    case CUSTOMER = 'customer';
    case SELLER = 'seller';
    case RIDER = 'rider';
}
