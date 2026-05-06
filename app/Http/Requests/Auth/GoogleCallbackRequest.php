<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class GoogleCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idToken'      => ['required', 'string'],
            'friends_code' => ['nullable', 'string', 'max:32', 'exists:users,referral_code'],
            'fcm_token'    => ['nullable', 'string'],
            'device_type'  => ['nullable', 'string', 'max:32'],
            'country'      => ['nullable', 'string', 'max:255'],
            'iso_2'        => ['nullable', 'string', 'max:2'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'idToken'      => 'Firebase ID token',
            'friends_code' => 'referral code',
            'fcm_token'    => 'FCM token',
            'device_type'  => 'device type',
            'country'      => 'country',
            'iso_2'        => 'country ISO-2 code',
        ];
    }
}
