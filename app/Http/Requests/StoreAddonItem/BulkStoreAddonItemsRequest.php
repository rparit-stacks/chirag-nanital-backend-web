<?php

namespace App\Http\Requests\StoreAddonItem;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bulk create store_addon_items: one or more store_ids + a list of
 * (addon_item, price, cost, stock, is_available) rows to be attached in a
 * single submission. The same (price, cost, stock, is_available) values are
 * broadcast to every selected store. Per-store tweaks happen afterwards via
 * the single-row edit modal.
 */
class BulkStoreAddonItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is enforced in the controller via policy.
    }

    public function rules(): array
    {
        $sellerId = auth()->user()?->seller()?->id;

        return [
            'store_ids'                => ['required', 'array', 'min:1'],
            'store_ids.*'              => [
                'integer',
                'distinct',
                Rule::exists('stores', 'id')->where(fn ($q) => $q->where('seller_id', $sellerId)),
            ],
            'items'                    => ['required', 'array', 'min:1'],
            'items.*.addon_item_id'    => [
                'required',
                'integer',
                'distinct',
                Rule::exists('addon_items', 'id')->where(function ($q) use ($sellerId) {
                    $q->whereIn('addon_group_id', function ($sub) use ($sellerId) {
                        $sub->select('id')->from('addon_groups')->where('seller_id', $sellerId);
                    });
                }),
            ],
            'items.*.price'            => ['required', 'numeric', 'min:0'],
            'items.*.cost'             => ['nullable', 'numeric', 'min:0'],
            'items.*.stock'            => ['nullable', 'integer', 'min:0'],
            'items.*.is_available'     => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'store_ids'                => __('labels.stores'),
            'store_ids.*'              => __('labels.store'),
            'items'                    => __('labels.addon_items'),
            'items.*.addon_item_id'    => __('labels.addon_item'),
            'items.*.price'            => __('labels.price'),
            'items.*.cost'             => __('labels.cost'),
            'items.*.stock'            => __('labels.stock'),
            'items.*.is_available'     => __('labels.is_available'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $items = $this->input('items', []);
        if (is_array($items)) {
            foreach ($items as $i => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $items[$i]['is_available'] = filter_var($item['is_available'] ?? false, FILTER_VALIDATE_BOOLEAN);
            }
            $this->merge(['items' => $items]);
        }
    }
}
