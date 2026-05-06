<?php

namespace App\Types\Settings;

use App\Interfaces\SettingInterface;
use App\Traits\SettingTrait;

class SystemUpdateSettingType implements SettingInterface
{
    use SettingTrait;

    public string $customerForceUpdateMessage = '';
    public string $customerSoftUpdateMessage = '';

    public string $riderForceUpdateMessage = '';
    public string $riderSoftUpdateMessage = '';

    public string $sellerForceUpdateMessage = '';
    public string $sellerSoftUpdateMessage = '';
    public string $webForceUpdateMessage = '';
    public string $webSoftUpdateMessage = '';

    protected static function getValidationRules(): array
    {
        return [
            'customerForceUpdateMessage' => 'nullable|string|max:500',
            'customerSoftUpdateMessage'  => 'nullable|string|max:500',
            'riderForceUpdateMessage'    => 'nullable|string|max:500',
            'riderSoftUpdateMessage'     => 'nullable|string|max:500',
            'sellerForceUpdateMessage'   => 'nullable|string|max:500',
            'sellerSoftUpdateMessage'    => 'nullable|string|max:500',
            'webForceUpdateMessage'      => 'nullable|string|max:500',
            'webSoftUpdateMessage'       => 'nullable|string|max:500',
        ];
    }
}
