<?php

namespace App\Http\Requests\User\Cart;

use Illuminate\Foundation\Http\FormRequest;

class CartSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Each item mirrors the AddToCart payload, including an optional
     * `addons` array of `{addon_group_id, addon_item_id}` tuples. Structural
     * checks (integer, exists) happen here; the semantic validations
     * (attachment to the variant at this store, required / single-selection
     * rules, availability, per-store stock) live in `CartService::addToCart`
     * so sync and add-to-cart share one code path.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1',

            'items.*.store_id' => 'required|integer|exists:stores,id',
            'items.*.product_variant_id' => 'required|integer|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1|max:999',

            'items.*.addons'                   => 'sometimes|array',
            'items.*.addons.*.addon_group_id'  => 'required_with:items.*.addons|integer|exists:addon_groups,id',
            'items.*.addons.*.addon_item_id'   => 'required_with:items.*.addons|integer|exists:addon_items,id',
        ];
    }

    /**
     * Humanised labels for nested addon errors so messages read cleanly.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'items.*.addons'                  => __('validation.attributes.addons'),
            'items.*.addons.*.addon_group_id' => __('validation.attributes.addon_group_id'),
            'items.*.addons.*.addon_item_id'  => __('validation.attributes.addon_item_id'),
        ];
    }
}
