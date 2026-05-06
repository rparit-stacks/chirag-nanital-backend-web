<?php

namespace App\Http\Requests\ProductAddon;

use Illuminate\Foundation\Http\FormRequest;

class ShowProductAddonMatrixRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization enforced in the controller via policy.
    }

    public function rules(): array
    {
        return [
            'pairs'              => ['required', 'array', 'min:1'],
            'pairs.*'            => ['array:variant_id,group_id'],
            'pairs.*.variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'pairs.*.group_id'   => ['required', 'integer', 'exists:addon_groups,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'pairs'              => __('labels.product_addons'),
            'pairs.*.variant_id' => __('labels.variant'),
            'pairs.*.group_id'   => __('labels.addon_group'),
        ];
    }
}
