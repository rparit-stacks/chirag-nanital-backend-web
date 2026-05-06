<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StoreAddonItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'uuid'            => $this->uuid,
            'store_id'        => $this->store_id,
            'store_name'      => $this->whenLoaded('store', fn () => $this->store?->name),
            'addon_item_id'   => $this->addon_item_id,
            'addon_item_title' => $this->whenLoaded('addonItem', fn () => $this->addonItem?->title),
            'addon_group_id'   => $this->whenLoaded('addonItem', fn () => $this->addonItem?->addon_group_id),
            'addon_group_title' => $this->whenLoaded('addonItem', fn () => $this->addonItem?->group?->title),
            'price'           => (float) $this->price,
            'cost'            => $this->cost !== null ? (float) $this->cost : null,
            'stock'           => (int) $this->stock,
            'is_available'    => (bool) $this->is_available,
            'metadata'        => $this->metadata,
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
        ];
    }
}
