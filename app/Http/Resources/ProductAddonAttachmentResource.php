<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Summary resource for a single (product_variant, addon_group) attachment.
 *
 * Mirrors the aggregate row returned by ProductAddonAttachmentService::listAttachments()
 * so it can be used both in listings and in the "show" endpoint's header payload.
 */
class ProductAddonAttachmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'id'                => (int) ($this->id ?? 0),
            'product_variant_id' => (int) ($this->product_variant_id ?? 0),
            'addon_group_id'     => (int) ($this->addon_group_id ?? 0),
            'product_title'      => (string) ($this->productVariant?->product?->title ?? ''),
            'variant_title'      => (string) ($this->productVariant?->title ?? ''),
            'group_title'        => (string) ($this->addonGroup?->title ?? ''),
            'stores_count'       => (int) ($this->stores_count ?? 0),
            'items_count'        => (int) ($this->items_count ?? 0),
            'updated_at'         => $this->updated_at ?? null,
        ];
    }
}
