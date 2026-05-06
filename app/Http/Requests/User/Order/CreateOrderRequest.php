<?php

namespace App\Http\Requests\User\Order;

use App\Enums\Payment\PaymentTypeEnum;
use App\Enums\Product\ProductAttachmentModeEnum;
use App\Models\Cart;
use App\Models\Product;
use App\Services\CartService;
use App\Types\Api\ApiResponseType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request. Orders
     * require a fully verified customer (both email and mobile) so delivery
     * ops can reach them on either channel.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        // Auth middleware already blocks unauthenticated requests; if we
        // somehow got here without a user, defer to the controller's own
        // null-check so the error message stays user-facing.
        if (!$user) {
            return true;
        }

        return $user->isFullyVerified();
    }

    /**
     * Return the project's standard envelope on authorization failure so
     * clients get a clear, structured "which channel is missing" payload.
     */
    protected function failedAuthorization(): void
    {
        $user = $this->user();

        throw new HttpResponseException(
            ApiResponseType::sendJsonResponse(
                false,
                'labels.account_verification_required',
                [
                    'email_verified'  => $user?->email_verified_at !== null,
                    'mobile_verified' => $user?->mobile_verified_at !== null,
                ]
            )
        );
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'payment_type' => ['required', Rule::in(PaymentTypeEnum::values())],
            'promo_code' => ['nullable', 'string', 'max:50'],
            'gift_card' => ['nullable', 'string', 'max:50'],
            'address_id' => ['required', 'numeric', 'exists:addresses,id'],
            'rush_delivery' => ['boolean', 'nullable'],
            'use_wallet' => ['boolean', 'nullable'],
            'order_note' => ['nullable', 'string', 'max:500'],
            'redirect_url' => ['nullable'],

            // Attachments structure: attchment[productId][] or attachments[productId][]
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['array'],
            'attachments.*.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx'],
        ];

        if (in_array($this->input('payment_type'), [PaymentTypeEnum::STRIPE(), PaymentTypeEnum::RAZORPAY(), PaymentTypeEnum::PAYSTACK()])) {
            $rules['transaction_id'] = ['required', 'string'];
        }
        if (!empty($this->input('redirect_url'))) {
            $rules['redirect_url'] = ['required', 'url'];
        }
        if ($this->input('payment_type') === PaymentTypeEnum::RAZORPAY()) {
            $rules['razorpay_order_id'] = ['required', 'string'];
            $rules['razorpay_signature'] = ['required', 'string'];
        }

        return $rules;
    }

    /**
     * Configure the validator instance to enforce required attachments for products that require them.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $user = $this->user();
            if (!$user) {
                return; // Authorization handled elsewhere
            }

            // Load user's cart with products to check requirement
            $cart = CartService::getUserCart($user);
            if (!$cart) {
                return;
            }

            $attachments = $this->file('attachments', []);
            $attachmentsAlt = $this->file('attchment', []); // alternate key

            foreach ($cart->items as $item) {
                $product = $item->product;
                if (!$product) {
                    continue;
                }
                $requires = (string) $product->is_attachment_required === '1' || $product->is_attachment_required === 1 || $product->is_attachment_required === true;
                if ($requires) {
                    $productId = (string) $product->id;
                    $files = [];
                    if (isset($attachments[$productId])) {
                        $files = (array)$attachments[$productId];
                    }
                    elseif (isset($attachmentsAlt[$productId])) {
                        $files = (array)$attachmentsAlt[$productId];
                    }

                    $attachmentMode = $product->attachment_mode ?? ProductAttachmentModeEnum::REQUIRED();

                    if (empty($files) && $attachmentMode === ProductAttachmentModeEnum::REQUIRED()) {
                        $validator->errors()->add('attachments.' . $productId, __('validation.required', ['attribute' => 'attachment for product ' . $product->title]));
                    }
                }
            }
        });
    }
}