<?php

namespace App\Http\Requests\StoreAddonItem;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUpdateStoreAddonItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is enforced in the controller via policy.
    }

    public function rules(): array
    {
        $rowId    = $this->route('id');
        $sellerId = auth()->user()?->seller()?->id;

        // On update the store_id / addon_item_id are pinned to the existing row;
        // they're still required so the form round-trips cleanly, but the
        // unique (store_id, addon_item_id) pair must ignore the current row.
        return [
            'store_id'      => [
                'required',
                'integer',
                Rule::exists('stores', 'id')->where(fn ($q) => $q->where('seller_id', $sellerId)),
            ],
            'addon_item_id' => [
                'required',
                'integer',
                Rule::exists('addon_items', 'id')->where(function ($q) use ($sellerId) {
                    $q->whereIn('addon_group_id', function ($sub) use ($sellerId) {
                        $sub->select('id')->from('addon_groups')->where('seller_id', $sellerId);
                    });
                }),
                Rule::unique('store_addon_items', 'addon_item_id')->where(function ($q) use ($rowId) {
                    $q->where('store_id', $this->input('store_id'))->whereNull('deleted_at');
                    if ($rowId) {
                        $q->where('id', '!=', $rowId);
                    }
                }),
            ],
            'price'         => ['required', 'numeric', 'min:0'],
            'cost'          => ['nullable', 'numeric', 'min:0'],
            'stock'         => ['nullable', 'integer', 'min:0'],
            'is_available'  => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'store_id'      => __('labels.store'),
            'addon_item_id' => __('labels.addon_item'),
            'price'         => __('labels.price'),
            'cost'          => __('labels.cost'),
            'stock'         => __('labels.stock'),
            'is_available'  => __('labels.is_available'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_available' => filter_var($this->input('is_available', false), FILTER_VALIDATE_BOOLEAN),
        ]);
    }
}
