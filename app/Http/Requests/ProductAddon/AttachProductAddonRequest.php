<?php

namespace App\Http\Requests\ProductAddon;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Single (variant × group) attachment submission.
 *
 * Mapping-only: per-item pricing/cost/stock/availability are managed on the
 * "Addon Inventory" screen against `store_addon_items`, not here.
 */
class AttachProductAddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization enforced in the controller via policy.
    }

    public function rules(): array
    {
        return [
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'addon_group_id'     => ['required', 'integer', 'exists:addon_groups,id'],

            'stores'                          => ['required', 'array', 'min:1'],
            'stores.*.store_id'               => ['required', 'integer', 'exists:stores,id'],
            'stores.*.apply'                  => ['nullable', 'boolean'],
            'stores.*.items'                  => ['nullable', 'array'],
            'stores.*.items.*.addon_item_id'  => ['required_with:stores.*.items', 'integer', 'exists:addon_items,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'product_variant_id'             => __('labels.variant'),
            'addon_group_id'                 => __('labels.addon_group'),
            'stores'                         => __('labels.stores'),
            'stores.*.items.*.addon_item_id' => __('labels.addon_item_title'),
        ];
    }

    protected function prepareForValidation(): void
    {
        // Normalise "apply" booleans coming from checkbox form controls.
        $stores = collect($this->input('stores', []))->map(function ($store) {
            $store['apply'] = filter_var($store['apply'] ?? false, FILTER_VALIDATE_BOOLEAN);
            return $store;
        })->all();

        $this->merge(['stores' => $stores]);
    }
}
