<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AppNotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'audience_type' => $this->audience_type?->value,
            'title' => $this->title,
            'message' => $this->message,
            'notification_image' => $this->notification_image ?? null,
            'target_type' => $this->target_type?->value,
            'metadata' => $this->metadata,
            'created_by' => $this->created_by,
            'creator_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'selected_user_ids' => $this->whenLoaded('userMaps', fn () => $this->userMaps->pluck('user_id')->values()),
            'selected_zone_ids' => $this->whenLoaded('zoneMaps', fn () => $this->zoneMaps->pluck('zone_id')->values()),
            'applies_to_all_users' => $this->whenLoaded('userMaps', fn () => $this->userMaps->isEmpty()),
            'applies_to_all_zones' => $this->whenLoaded('zoneMaps', fn () => $this->zoneMaps->isEmpty()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
