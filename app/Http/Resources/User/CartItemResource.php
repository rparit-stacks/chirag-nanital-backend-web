<?php

namespace App\Http\Resources\User;

use App\Enums\Product\ProductAttachmentModeEnum;
use App\Models\Review;
use App\Models\StoreProductVariantAddon;
use App\Services\DeliveryZoneService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $variantPricing = $this->variant->storeProductVariants->where('store_id', $this->store_id)->first();
        $storeVariant = $this->variant->storeProductVariants->where('store_id', $this->store_id)->first();
        $reviews = Review::scopeProductRatingStats($this->product->id);
        if (isset($request->latitude) && isset($request->longitude)) {
            $this->product->user_latitude = $request->latitude;
            $this->product->user_longitude = $request->longitude;
            $this->product->zone_info = DeliveryZoneService::getZonesAtPoint($request->latitude, $request->longitude);
        }

        $addonsTotal = $this->resolveAddonsTotal();
        $quantity = (int)$this->quantity;
        // Product-only line totals (variant price × quantity).
        $productLinePrice = $quantity * (float)($variantPricing?->price ?? 0);
        $productLineSpecialPrice = $quantity * (float)($variantPricing?->special_price ?? 0);

        return [
            'id' => $this->id,
            'cart_id' => $this->cart_id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'store_id' => $this->store_id,
            'quantity' => $this->quantity,
            'save_for_later' => $this->save_for_later,
            'product' => [
                'id' => $this->product->id,
                'name' => $this->product->title,
                'slug' => $this->product->slug,
                'minimum_order_quantity' => $this->product->minimum_order_quantity,
                'quantity_step_size' => $this->product->quantity_step_size,
                'total_allowed_quantity' => $this->product->total_allowed_quantity,
                'is_attachment_required' => $this->product->is_attachment_required == "1" ? true : false,
                'attachment_mode' => $this->product->attachment_mode ?? ProductAttachmentModeEnum::REQUIRED(),
                'image' => $this->product->main_image ?? null,
                'estimated_delivery_time' => $this->product->estimated_delivery_time,
                'image_fit' => $this->product->image_fit,
                'store_status' => $this->product->variants->first()->storeProductVariants->first()->store->checkStoreStatus() ?? [],
                'ratings' => $reviews['average_rating'] ?? 0,
                'rating_count' => $reviews['total_reviews'] ?? 0,
            ],
            'variant' => [
                'id' => $this->variant->id,
                'title' => $this->variant->title,
                'slug' => $this->variant->slug,
                'image' => $this->variant->image ?? null,
                'price' => $variantPricing?->price ?? 0,
                'special_price' => $variantPricing?->special_price ?? 0,
                'stock' => $storeVariant?->stock ?? 0,
                'sku' => $storeVariant?->sku ?? null,
                'is_addons' => StoreProductVariantAddon::where('store_id', $this->store_id)
                    ->where('product_variant_id', $this->product_variant_id)
                    ->exists(),
            ],
            'store' => [
                'id' => $this->store->id,
                'name' => $this->store->name,
                'slug' => $this->store->slug,
                'total_products' => $this->store->product_count ?? 0,
                'status' => optional(
                        $this->store
                    )->checkStoreStatus() ?? [],
            ],
            'addons' => $this->formatAddons(),
            'addons_total' => $addonsTotal,
            'total_item_price' => round($variantPricing?->price + ($addonsTotal/$this->quantity), 2),
            'total_item_special_price' => round($variantPricing?->special_price + ($addonsTotal/$this->quantity), 2),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s')
        ];
    }

    /**
     * Flatten the CartItemAddon rows into a scalar-friendly list.
     *
     * Shape mirrors the catalog/order-side addon payload so clients can render
     * the "as-configured" summary (group title + item title + price) without
     * additional lookups. Uses the `addons` relation which `CartService`
     * eager-loads via `items.addons.addonGroup` / `items.addons.addonItem` —
     * falling back to a lazy load in the unlikely case it wasn't loaded.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function formatAddons(): array
    {
        $addons = $this->resource->relationLoaded('addons')
            ? $this->resource->getRelation('addons')
            : $this->resource->addons()->with(['addonGroup', 'addonItem'])->get();

        if (!$addons || $addons->isEmpty()) {
            return [];
        }

        return $addons->map(function ($addon) {
            $group = $addon->addonGroup;
            $item = $addon->addonItem;

            return [
                'id' => $addon->id,
                'uuid' => $addon->uuid,
                'addon_group_id' => $addon->addon_group_id,
                'addon_item_id' => $addon->addon_item_id,
                'price' => (float)$addon->price,
                'group' => $group ? [
                    'id' => $group->id,
                    'title' => $group->title,
                    'selection_type' => $group->selection_type?->value,
                    'is_required' => (bool)$group->is_required,
                ] : null,
                'item' => $item ? [
                    'id' => $item->id,
                    'title' => $item->title,
                    'indicator' => $item->indicator?->value,
                ] : null,
            ];
        })->values()->all();
    }

    /**
     * Resolve the line-total addon amount for this cart item.
     *
     * `CartService::calculateCartTotals()` writes `addons_total` onto each
     * item when it runs (every time the cart is rendered through `getCart`).
     * If the resource is consumed outside that flow, compute it on the fly
     * from the eager-loaded `addons` relation so the field is always present.
     */
    protected function resolveAddonsTotal(): float
    {
        if (isset($this->resource->addons_total)) {
            return (float)$this->resource->addons_total;
        }

        $addons = $this->resource->relationLoaded('addons')
            ? $this->resource->getRelation('addons')
            : $this->resource->addons()->get();

        if (!$addons || $addons->isEmpty()) {
            return 0.0;
        }

        return (float)((int)$this->quantity) * (float)$addons->sum(fn($a) => (float)$a->price);
    }
}
