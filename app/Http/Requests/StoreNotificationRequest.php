<?php

namespace App\Http\Requests;

use App\Enums\Notification\NotificationAudienceTypeEnum;
use App\Enums\Notification\NotificationTargetTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // You can add permission logic here if needed
        return true;
    }

    /**
     * Convert incoming data before validation
     */
    protected function prepareForValidation(): void
    {
        // Convert user_ids from "1,2,3" → [1,2,3]
        if (is_string($this->user_ids)) {
            $this->merge([
                'user_ids' => array_filter(
                    array_map('trim', explode(',', $this->user_ids))
                ),
            ]);
        }

        // Optional: same for zone_ids if needed
        if (is_string($this->zone_ids)) {
            $this->merge([
                'zone_ids' => array_filter(
                    array_map('trim', explode(',', $this->zone_ids))
                ),
            ]);
        }

        if ($this->input('audience_type') !== NotificationAudienceTypeEnum::CUSTOMER()) {
            $this->merge([
                'target_type' => null,
                'target_id' => null,
            ]);
        }
    }

    /**
     * Validation rules
     */
    public function rules(): array
    {
        return [
            'audience_type' => ['required', new Enum(NotificationAudienceTypeEnum::class)],
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],

            // Optional image for app notification using Spatie Media Library
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:1024'],

            'target_type' => [
                'nullable',
                new Enum(NotificationTargetTypeEnum::class),
            ],

            'target_id' => [
                'nullable',
                'required_with:target_type',
                'integer',
                'min:1',
            ],

            'zone_ids' => ['nullable', 'array'],
            'zone_ids.*' => ['integer', 'exists:delivery_zones,id'],

            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * Optional: Custom messages
     */
    public function messages(): array
    {
        return [
            'user_ids.*.integer' => __('labels.user_id_integer_error'),
            'zone_ids.*.exists' => __('labels.user_id_exist_error'),
        ];
    }
}
