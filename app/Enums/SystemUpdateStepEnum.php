<?php

namespace App\Enums;

use ArchTech\Enums\InvokableCases;
use ArchTech\Enums\Names;
use ArchTech\Enums\Values;

/**
 * High-level phase the SystemUpdater is in. Used purely for UI display; the terminal
 * state of a run is still carried by {@see SystemUpdateStatusEnum}.
 *
 * @method static QUEUED()
 * @method static EXTRACTING()
 * @method static VERIFYING()
 * @method static APPLYING()
 * @method static MIGRATING()
 * @method static SEEDING()
 * @method static VENDOR()
 * @method static CACHING()
 * @method static FINALIZING()
 * @method static ROLLING_BACK()
 */
enum SystemUpdateStepEnum: string
{
    use InvokableCases, Values, Names;

    case QUEUED       = 'queued';
    case EXTRACTING   = 'extracting';
    case VERIFYING    = 'verifying';
    case APPLYING     = 'applying';
    case MIGRATING    = 'migrating';
    case SEEDING      = 'seeding';
    case VENDOR       = 'vendor';
    case CACHING      = 'caching';
    case FINALIZING   = 'finalizing';
    case ROLLING_BACK = 'rolling_back';
}
