<?php

namespace App\Types\Settings;

use App\Interfaces\SettingInterface;
use App\Traits\SettingTrait;

class DeliveryBoySettingType implements SettingInterface
{
    use SettingTrait;

    public string $termsCondition = '';
    public string $privacyPolicy = '';

    // ─── Delivery Boy Refer & Earn Program ────────────────────────────────────
    // Fixed-amount only. Settled immediately on admin verification. One-time per referred DB.
    public bool $deliveryBoyReferEarnStatus = false;
    public string $deliveryBoyReferEarnBonusReferral = '0'; // amount paid to DB-A (referrer)
    public string $deliveryBoyReferEarnBonusReferee = '0'; // amount paid to DB-B (new delivery boy)

    protected static function getValidationRules(): array
    {
        return [
            'termsCondition' => 'nullable|string',
            'privacyPolicy' => 'nullable|string',
            'deliveryBoyReferEarnStatus' => ['nullable', 'boolean'],
            'deliveryBoyReferEarnBonusReferral' => ['nullable', 'numeric', 'min:0'],
            'deliveryBoyReferEarnBonusReferee' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
