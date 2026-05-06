<?php

namespace App\Enums\Wallet;

use ArchTech\Enums\InvokableCases;
use ArchTech\Enums\Names;
use ArchTech\Enums\Values;

/**
 * Wallet "panel" ownership.
 *
 * A given users.id can own up to three wallets — one per type:
 *  - CUSTOMER      → used for customer/marketplace checkout (every user has this)
 *  - SELLER        → seller payouts / earnings (only when the user has a seller row)
 *  - DELIVERY_BOY  → rider payouts / earnings (only when the user has a delivery_boys row)
 *
 * Controllers/services MUST pass the type explicitly when resolving a wallet;
 * defaulting to CUSTOMER keeps customer checkout isolated from seller/rider funds.
 *
 * @method static CUSTOMER()
 * @method static SELLER()
 * @method static DELIVERY_BOY()
 */
enum WalletTypeEnum: string
{
    use InvokableCases, Values, Names;

    case CUSTOMER = 'customer';
    case SELLER = 'seller';
    case DELIVERY_BOY = 'delivery_boy';
}
