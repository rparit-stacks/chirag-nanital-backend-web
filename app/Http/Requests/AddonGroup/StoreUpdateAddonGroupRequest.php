<?php

namespace App\Http\Requests\AddonGroup;

use App\Enums\Addon\AddonGroupSelectionTypeEnum;
use App\Enums\Addon\AddonGroupStatusEnum;
use App\Enums\Addon\AddonItemIndicatorEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreUpdateAddonGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is enforced in the controller via policy.
    }

    public function rules(): array
    {
        $groupId  = $this->route('id');
        $sellerId = auth()->user()?->seller()?->id;

        return [
            'title'          => [
                'required',
                'string',
                'max:255',
                Rule::unique('addon_groups', 'title')->where(function ($q) use ($sellerId, $groupId) {
                    $q->where('seller_id', $sellerId)->whereNull('deleted_at');
                    if ($groupId) {
                        $q->where('id', '!=', $groupId);
                    }
                }),
            ],
            'selection_type' => ['required', new Enum(AddonGroupSelectionTypeEnum::class)],
            'is_required'    => ['nullable', 'boolean'],
            'sort_order'     => ['nullable', 'integer', 'min:0'],
            'status'         => ['required', new Enum(AddonGroupStatusEnum::class)],

            // Items array (one form, nested item rows)
            'items'                  => ['required', 'array', 'min:1'],
            'items.*.id'             => ['nullable', 'integer', 'exists:addon_items,id'],
            'items.*.title'          => ['required', 'string', 'max:255'],
            'items.*.price'          => ['required', 'numeric', 'min:0'],
            'items.*.cost'           => ['nullable', 'numeric', 'min:0'],
            'items.*.indicator'      => ['nullable', new Enum(AddonItemIndicatorEnum::class)],
            'items.*.is_available'   => ['nullable', 'boolean'],
            'items.*.sort_order'     => ['nullable', 'integer', 'min:0'],
            'items.*.status'         => ['required', new Enum(AddonGroupStatusEnum::class)],
        ];
    }

    public function attributes(): array
    {
        return [
            'title'                => __('labels.addon_group_title'),
            'selection_type'       => __('labels.addon_selection_type'),
            'is_required'          => __('labels.addon_is_required'),
            'status'               => __('labels.status'),
            'items'                => __('labels.addon_items'),
            'items.*.title'        => __('labels.addon_item_title'),
            'items.*.price'        => __('labels.price'),
            'items.*.cost'         => __('labels.cost'),
            'items.*.indicator'    => __('labels.addon_item_indicator'),
            'items.*.is_available' => __('labels.addon_item_availability'),
            'items.*.status'       => __('labels.status'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_required' => filter_var($this->input('is_required', false), FILTER_VALIDATE_BOOLEAN),
        ]);
    }
}
