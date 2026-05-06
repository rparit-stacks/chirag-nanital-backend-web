<?php

namespace App\Listeners\Order;

use App\Events\Order\OrderPlaced;
use App\Services\StockService;
use App\Services\StoreAddonItemService;
use Illuminate\Support\Facades\Log;

class UpdateStockInventory
{
    protected StockService $stockService;
    protected StoreAddonItemService $storeAddonItemService;

    /**
     * Create the event listener.
     */
    public function __construct(StockService $stockService, StoreAddonItemService $storeAddonItemService)
    {
        $this->stockService = $stockService;
        $this->storeAddonItemService = $storeAddonItemService;
    }

    /**
     * Handle the event.
     */
    public function handle(OrderPlaced $event): void
    {
        foreach ($event->orderItem as $item) {
            $this->stockService->removeStock(
                $item->store_id,
                $item->product_variant_id,
                $item->quantity,
                "Moved {$item->quantity} item(s) from stock to Order #{$event->order->id}."
            );

            // Also decrement per-store addon inventory. Each `order_item_addons`
            // row represents "one of this addon per parent order item quantity",
            // so the decrement is `item.quantity` units per addon row. Addons
            // without a `store_addon_items` row (catalog-only) have no stock to
            // decrement — same fallback behaviour as the cart-side check.
            $this->decrementAddonStock($event, $item);
        }
    }

    /**
     * Decrement `store_addon_items.stock` for every addon attached to the
     * order item. Silently skips catalog-only selections and logs (without
     * raising) when a row exists but can't satisfy the quantity — the cart
     * pre-check should have caught that, so reaching here means a race
     * condition. The order has already been placed at this point, so we do
     * not fail the listener.
     */
    protected function decrementAddonStock(OrderPlaced $event, $orderItem): void
    {
        $quantity = (int) $orderItem->quantity;
        if ($quantity <= 0) {
            return;
        }

        $addons = $orderItem->relationLoaded('addons')
            ? $orderItem->getRelation('addons')
            : $orderItem->addons()->get();

        if (!$addons || $addons->isEmpty()) {
            return;
        }

        foreach ($addons as $addon) {
            $inventory = $this->storeAddonItemService->findForCheckout(
                (int) $orderItem->store_id,
                (int) $addon->addon_item_id,
            );

            if (!$inventory) {
                // Catalog-only addon — no per-store stock row to adjust.
                continue;
            }

            $ok = $this->storeAddonItemService->decrementStock($inventory, $quantity);
            if (!$ok) {
                Log::warning('Addon stock decrement failed after order placement', [
                    'order_id'       => $event->order->id,
                    'order_item_id'  => $orderItem->id,
                    'store_id'       => $orderItem->store_id,
                    'addon_item_id'  => $addon->addon_item_id,
                    'quantity'       => $quantity,
                    'current_stock'  => $inventory->fresh()?->stock,
                ]);
            }
        }
    }
}
