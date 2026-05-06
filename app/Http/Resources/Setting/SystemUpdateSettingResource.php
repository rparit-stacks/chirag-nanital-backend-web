<?php

namespace App\Http\Resources\Setting;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemUpdateSettingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'variable' => $this->variable,
            'value' => [
                'customerForceUpdateMessage' => $this->value['customerForceUpdateMessage'] ?? __('labels.customer_force_update_default'),
                'customerSoftUpdateMessage'  => $this->value['customerSoftUpdateMessage'] ?? __('labels.customer_soft_update_default'),
                'riderForceUpdateMessage'    => $this->value['riderForceUpdateMessage'] ?? __('labels.rider_force_update_default'),
                'riderSoftUpdateMessage'     => $this->value['riderSoftUpdateMessage'] ?? __('labels.rider_soft_update_default'),
                'sellerForceUpdateMessage'   => $this->value['sellerForceUpdateMessage'] ?? __('labels.seller_force_update_default'),
                'sellerSoftUpdateMessage'    => $this->value['sellerSoftUpdateMessage'] ?? __('labels.seller_soft_update_default'),
                'webForceUpdateMessage'      => $this->value['webForceUpdateMessage'] ?? __('labels.customer_web_force_update_default'),
                'webSoftUpdateMessage'       => $this->value['webSoftUpdateMessage'] ?? __('labels.customer_web_soft_update_default'),
            ]
        ];
    }
}
