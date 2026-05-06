<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AddonItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'id'           => $this->id,
            'uuid'         => $this->uuid,
            'title'        => $this->title,
            'slug'         => $this->slug,
            'price'        => (float) $this->price,
            'cost'         => $this->cost !== null ? (float) $this->cost : null,
            'indicator'    => $this->indicator?->value,
            'is_available' => (bool) $this->is_available,
            'sort_order'   => (int) $this->sort_order,
            'status'       => $this->status?->value,
        ];
    }
}
