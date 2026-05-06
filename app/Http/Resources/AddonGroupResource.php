<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AddonGroupResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'uuid'           => $this->uuid,
            'title'          => $this->title,
            'slug'           => $this->slug,
            'selection_type' => $this->selection_type?->value,
            'is_required'    => (bool) $this->is_required,
            'sort_order'     => (int) $this->sort_order,
            'status'         => $this->status?->value,
            'items_count'    => $this->whenLoaded('items', fn () => $this->items->count()),
            'items'          => AddonItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
