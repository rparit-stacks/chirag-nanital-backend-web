<?php

namespace App\Http\Resources\Setting;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppSettingResource extends JsonResource
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
                'customerAppstoreLink' => $this->value['customerAppstoreLink'] ?? '',
                'customerPlaystoreLink' => $this->value['customerPlaystoreLink'] ?? '',
                'customerAppScheme' => $this->value['customerAppScheme'] ?? '',
                'sellerAppstoreLink' => $this->value['sellerAppstoreLink'] ?? '',
                'sellerPlaystoreLink' => $this->value['sellerPlaystoreLink'] ?? '',
                'sellerAppScheme' => $this->value['sellerAppScheme'] ?? '',
                'riderAppstoreLink' => $this->value['riderAppstoreLink'] ?? '',
                'riderPlaystoreLink' => $this->value['riderPlaystoreLink'] ?? '',
            ]
        ];
    }
}
