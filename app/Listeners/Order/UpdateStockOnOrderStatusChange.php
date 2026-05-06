<?php

namespace App\Listeners\Order;

use App\Enums\Order\OrderItemStatusEnum;
use App\Events\Order\OrderStatusUpdated;
use App\Models\OrderItem;
use App\Services\OrderService;
use App\Services\StockService;
use App\Services\StoreAddonItemService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateStockOnOrderStatusChange
{
    protected StockService $stockService;
    protected OrderService $orderService;
    protected StoreAddonItemService $storeAddonItemService;

    /**
     * Create the event listener.
     */
    public function __construct(
        StockService $stockService,
        OrderService $orderService,
        StoreAddonItemService $storeAddonItemService
    ) {
        $this->stockService = $stockService;
        $this->orderService = $orderService;
        $this->storeAddonItemService = $storeAddonItemService;
    }

    /**
     * Handle the event.
     */
    public function handle(OrderStatusUpdated $event): void
    {
        // Only process if the new status requires stock to be returned to inventory
        // This includes REJECTED, CANCELLED, RETURNED, and REFUNDED (for completed returns)
        if (in_array($event->newStatus, [
            OrderItemStatusEnum::REJECTED(),
            OrderItemStatusEnum::CANCELLED(),
            OrderItemStatusEnum::RETURNED(),
            OrderItemStatusEnum::REFUNDED(),
        ], true)) {
            try {
                DB::beginTransaction();

                // Get the order item and its details
                $orderItem = $event->orderItem;
                $quantity = $orderItem->quantity;
                $productVariantId = $orderItem->product_variant_id;
                $storeId = $orderItem->store_id;

                // Add the stock back
                $stockResult = $this->stockService->addStock(
                    $storeId,
                    $productVariantId,
                    $quantity,
                    "Returned {$quantity} item(s) to stock due to {$event->newStatus} of Order Item #{$orderItem->id}."
                );

                if (!$stockResult['success']) {
                    throw new \Exception($stockResult['message']);
                }

                $this->restoreAddonStock($orderItem, $event->newStatus);

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Error updating stock after order item status change', [
                    'order_item_id' => $event->orderItem->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Increment `store_addon_items.stock` for every addon snapshotted on the
     * order item. Each `order_item_addons` row represents "one of this addon
     * per parent order item quantity", so the amount restored per addon is
     * the order item's quantity. Addons without a `store_addon_items` row
     * (catalog-only) have nothing to restore — same fallback semantics as
     * the place-order decrement in {@see UpdateStockInventory}.
     */
    protected function restoreAddonStock(OrderItem $orderItem, string $newStatus): void
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
                // Catalog-only addon — no per-store row to restock.
                continue;
            }

            $ok = $this->storeAddonItemService->incrementStock($inventory, $quantity);
            if (!$ok) {
                // `incrementStock` only returns false for non-positive
                // quantities — we already guarded above, so reaching here
                // means something changed underneath us. Don't fail the
                // transaction for a single addon restock, but surface it.
                Log::warning('Addon stock restore returned false', [
                    'order_item_id' => $orderItem->id,
                    'store_id'      => $orderItem->store_id,
                    'addon_item_id' => $addon->addon_item_id,
                    'quantity'      => $quantity,
                    'new_status'    => $newStatus,
                ]);
            }
        }
    }
}
