<?php

namespace App\Http\Requests\ProductAddon;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bulk attach: one submission may contain multiple (variant × group) attachment rows.
 *
 * Mapping-only: per-item pricing/cost/stock/availability are managed on the
 * "Addon Inventory" screen against `store_addon_items`, not here.
 */
class BulkAttachProductAddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization enforced in the controller via policy.
    }

    public function rules(): array
    {
        return [
            'attachments'                                           => ['required', 'array', 'min:1'],
            'attachments.*.product_variant_id'                      => ['required', 'integer', 'exists:product_variants,id'],
            'attachments.*.addon_group_id'                          => ['required', 'integer', 'exists:addon_groups,id'],
            'attachments.*.stores'                                  => ['required', 'array', 'min:1'],
            'attachments.*.stores.*.store_id'                       => ['required', 'integer', 'exists:stores,id'],
            'attachments.*.stores.*.apply'                          => ['nullable', 'boolean'],
            'attachments.*.stores.*.items'                          => ['nullable', 'array'],
            'attachments.*.stores.*.items.*.addon_item_id'          => ['required_with:attachments.*.stores.*.items', 'integer', 'exists:addon_items,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'attachments'                                  => __('labels.product_addons'),
            'attachments.*.product_variant_id'             => __('labels.variant'),
            'attachments.*.addon_group_id'                 => __('labels.addon_group'),
            'attachments.*.stores'                         => __('labels.stores'),
            'attachments.*.stores.*.items.*.addon_item_id' => __('labels.addon_item_title'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $attachments = collect($this->input('attachments', []))->map(function ($attachment) {
            $stores = collect($attachment['stores'] ?? [])->map(function ($store) {
                $store['apply'] = filter_var($store['apply'] ?? false, FILTER_VALIDATE_BOOLEAN);
                return $store;
            })->all();

            $attachment['stores'] = $stores;
            return $attachment;
        })->all();

        $this->merge(['attachments' => $attachments]);
    }
}
