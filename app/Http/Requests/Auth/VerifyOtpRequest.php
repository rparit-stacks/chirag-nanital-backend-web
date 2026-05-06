<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerifyOtpRequest extends FormRequest
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
            'mobile'       => ['required', 'string'],
            'otp'          => ['required', 'string', 'size:6'],
            'name'         => ['nullable', 'string', 'max:255'],
            'email'        => [
                'nullable',
                'email',
                Rule::unique('users', 'email')->whereNull('deleted_at'),
            ],
            'password'     => ['nullable', 'string', 'min:6', 'confirmed'],
            'country'      => ['nullable', 'string', 'max:255'],
            'iso_2'        => ['nullable', 'string', 'max:2'],
            'friends_code' => ['nullable', 'string', 'max:32', 'exists:users,referral_code'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'mobile'       => 'mobile number',
            'otp'          => 'OTP code',
            'name'         => 'name',
            'email'        => 'email address',
            'password'     => 'password',
            'country'      => 'country',
            'iso_2'        => 'country ISO-2 code',
            'friends_code' => 'referral code',
        ];
    }
}
