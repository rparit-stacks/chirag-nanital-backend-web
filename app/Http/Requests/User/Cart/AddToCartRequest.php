<?php

namespace App\Http\Requests\User\Cart;

use Illuminate\Foundation\Http\FormRequest;

class AddToCartRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `addons` is optional; when present it is a flat list of
     * `{addon_group_id, addon_item_id}` tuples describing the user's
     * selection. Structural checks (exists, integer) happen here; the
     * semantic checks (is this item attached to the variant at this store?
     * is the group required? is the selection_type honoured?) live in
     * `CartService::addToCart` since they require the resolved store/variant.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'product_variant_id'       => 'required|integer|exists:product_variants,id',
            'store_id'                 => 'required|integer|exists:stores,id',
            'quantity'                 => 'sometimes|integer|min:1|max:999',
            'replace_quantity'         => 'nullable|boolean',
            'addons'                   => 'sometimes|array',
            'addons.*.addon_group_id'  => 'required_with:addons|integer|exists:addon_groups,id',
            'addons.*.addon_item_id'   => 'required_with:addons|integer|exists:addon_items,id',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.required'             => __('validation.product_id_required'),
            'product_id.exists'               => __('validation.product_id_exists'),
            'product_variant_id.required'     => __('validation.product_variant_id_required'),
            'product_variant_id.exists'       => __('validation.product_variant_id_exists'),
            'store_id.required'               => __('validation.store_id_required'),
            'store_id.exists'                 => __('validation.store_id_exists'),
            'quantity.integer'                => __('validation.quantity_integer'),
            'quantity.min'                    => __('validation.quantity_min'),
            'quantity.max'                    => __('validation.quantity_max'),
            'addons.array'                    => __('validation.addons_array'),
            'addons.*.addon_group_id.required_with' => __('validation.addon_group_id_required'),
            'addons.*.addon_group_id.integer' => __('validation.addon_group_id_integer'),
            'addons.*.addon_group_id.exists'  => __('validation.addon_group_id_exists'),
            'addons.*.addon_item_id.required_with'  => __('validation.addon_item_id_required'),
            'addons.*.addon_item_id.integer'  => __('validation.addon_item_id_integer'),
            'addons.*.addon_item_id.exists'   => __('validation.addon_item_id_exists'),
        ];
    }

    /**
     * Humanised labels for nested addon errors (validation.*.* needs them for readable output).
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'addons'                   => __('validation.attributes.addons'),
            'addons.*.addon_group_id'  => __('validation.attributes.addon_group_id'),
            'addons.*.addon_item_id'   => __('validation.attributes.addon_item_id'),
        ];
    }
}
