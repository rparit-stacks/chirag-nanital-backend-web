<?php

namespace App\Enums;

use ArchTech\Enums\InvokableCases;
use ArchTech\Enums\Names;
use ArchTech\Enums\Values;

/**
 * Tracks how a user authenticated — the provider of the most recent
 * (or original) sign-in. `PLATFORM` = native email/mobile + password
 * signup; `GOOGLE` / `APPLE` = Firebase-backed social signin.
 *
 * @method static GOOGLE()
 * @method static APPLE()
 * @method static PLATFORM()
 */
enum UserLoginTypeEnum: string
{
    use InvokableCases, Values, Names;

    case GOOGLE   = 'google';
    case APPLE    = 'apple';
    case PLATFORM = 'platform';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
