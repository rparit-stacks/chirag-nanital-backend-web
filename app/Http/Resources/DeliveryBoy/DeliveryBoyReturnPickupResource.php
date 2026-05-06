<?php

namespace App\Http\Resources\DeliveryBoy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryBoyReturnPickupResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order_item_id' => $this->order_item_id,
            'user_id' => $this->user_id,
            'seller_id' => $this->seller_id,
            'store_id' => $this->store_id,
            'delivery_boy_id' => $this->delivery_boy_id,
            'reason' => $this->reason,
            'refund_amount' => $this->refund_amount,
            'seller_comment' => $this->seller_comment,
            'pickup_status' => $this->pickup_status,
            'return_status' => $this->return_status,
            'seller_approved_at' => optional($this->seller_approved_at)->format('Y-m-d H:i:s'),
            'picked_up_at' => optional($this->picked_up_at)->format('Y-m-d H:i:s'),
            'received_at' => optional($this->received_at)->format('Y-m-d H:i:s'),
            'refund_processed_at' => optional($this->refund_processed_at)->format('Y-m-d H:i:s'),
            'images' => $this->images,

            // route and earning info (set by controller)
            'delivery_route' => $this->when(isset($this->delivery_route), fn () => $this->delivery_route),
            'earnings' => $this->when(isset($this->earnings), fn () => $this->earnings),
            'delivery_zone' => $this->when(isset($this->delivery_zone), fn () => $this->delivery_zone),

            // related data
            'order' => $this->whenLoaded('order', function () {
                return [
                    'id' => $this->order->id,
                    'uuid' => $this->order->uuid,
                    'shipping_name' => $this->order->shipping_name,
                    'shipping_phone' => $this->order->shipping_phone,
                    'shipping_address_1' => $this->order->shipping_address_1,
                    'shipping_address_2' => $this->order->shipping_address_2,
                    'shipping_landmark' => $this->order->shipping_landmark,
                    'shipping_city' => $this->order->shipping_city,
                    'shipping_state' => $this->order->shipping_state,
                    'shipping_zip' => $this->order->shipping_zip,
                    'shipping_country' => $this->order->shipping_country,
                    'shipping_latitude' => $this->order->shipping_latitude,
                    'shipping_longitude' => $this->order->shipping_longitude,
                ];
            }),
            'order_item' => $this->whenLoaded('orderItem', function () {
                return [
                    'id' => $this->orderItem->id,
                    'quantity' => (int) $this->orderItem->quantity,
                    'product' => $this->when($this->orderItem->relationLoaded('product'), function () {
                        return [
                            'id' => $this->orderItem->product->id ?? '',
                            'name' => $this->orderItem->product->title ?? '',
                        ];
                    }),
                    'variant' => $this->when($this->orderItem->relationLoaded('variant') && $this->orderItem->variant, function () {
                        return [
                            'id' => $this->orderItem->variant->id,
                            'sku' => $this->orderItem->variant->sku,
                            'title' => $this->orderItem->variant->title,
                        ];
                    }),
                    // Addons snapshot so the courier sees exactly what was
                    // delivered (and must therefore be collected on return).
                    // Same shape as `CartItemResource`/`OrderItemResource`
                    // addon payloads — group + item + per-unit price.
                    'addons' => $this->formatOrderItemAddons(),
                    'addons_total' => $this->sumOrderItemAddonsLineTotal(),
                ];
            }),
            'store' => $this->whenLoaded('store', function () {
                return [
                    'id' => $this->store->id,
                    'name' => $this->store->name,
                    'address' => $this->store->address,
                    'city' => $this->store->city,
                    'state' => $this->store->state,
                    'zipcode' => $this->store->zipcode,
                    'country' => $this->store->country,
                    'latitude' => $this->store->latitude,
                    'longitude' => $this->store->longitude,
                ];
            }),
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'phone' => $this->user->mobile_number,
                    'email' => $this->user->email,
                ];
            }),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Flatten the `order_item_addons` rows on the parent OrderItem into a
     * scalar-friendly list — same shape used by the customer-side resources
     * so mobile clients can share a single addon renderer across screens.
     *
     * Falls back to a lazy query when the `addons` relation isn't eager-
     * loaded. Returns [] when the item has no addons.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function formatOrderItemAddons(): array
    {
        $orderItem = $this->orderItem ?? null;
        if (!$orderItem) {
            return [];
        }

        $addons = $orderItem->relationLoaded('addons')
            ? $orderItem->getRelation('addons')
            : $orderItem->addons()->with(['addonGroup', 'addonItem'])->get();

        if (!$addons || $addons->isEmpty()) {
            return [];
        }

        return $addons->map(function ($addon) {
            $group = $addon->addonGroup;
            $item  = $addon->addonItem;

            return [
                'id'              => $addon->id,
                'uuid'            => $addon->uuid,
                'addon_group_id'  => $addon->addon_group_id,
                'addon_item_id'   => $addon->addon_item_id,
                'price'           => (float) $addon->price,
                'group' => $group ? [
                    'id'             => $group->id,
                    'title'          => $group->title,
                    'selection_type' => $group->selection_type?->value,
                    'is_required'    => (bool) $group->is_required,
                ] : null,
                'item' => $item ? [
                    'id'        => $item->id,
                    'title'     => $item->title,
                    'indicator' => $item->indicator?->value,
                ] : null,
            ];
        })->values()->all();
    }

    /**
     * Line-total addon contribution for the underlying OrderItem:
     * `quantity × sum(addon.price)`. Matches the customer-facing
     * `OrderItemResource::resolveAddonsTotal()` formula.
     */
    protected function sumOrderItemAddonsLineTotal(): float
    {
        $orderItem = $this->orderItem ?? null;
        if (!$orderItem) {
            return 0.0;
        }

        $addons = $orderItem->relationLoaded('addons')
            ? $orderItem->getRelation('addons')
            : $orderItem->addons()->get();

        if (!$addons || $addons->isEmpty()) {
            return 0.0;
        }

        return round(((int) $orderItem->quantity) * (float) $addons->sum(fn ($a) => (float) $a->price), 2);
    }
}
