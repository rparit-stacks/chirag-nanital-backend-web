<?php

namespace App\Http\Requests\User\Cart;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCartItemQuantityRequest extends FormRequest
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
     * `addons` is optional; when present it replaces the existing addon
     * selections on the cart line (replace-all semantics). Omitting the key
     * entirely leaves the current addons untouched — the client can pass
     * `addons: []` to explicitly clear them.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'quantity'                 => 'required|integer|min:1|max:999',
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
            'quantity.required'               => __('validation.quantity_required'),
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
     * Humanised labels for nested addon errors.
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
