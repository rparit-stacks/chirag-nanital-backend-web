<?php

namespace App\Services;

use App\Enums\SpatieMediaCollectionName;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProfileService
{
    /**
     * Update user profile
     *
     * @param User $user
     * @param array $validatedData
     * @param Request|null $request
     * @return User
     * @throws \Exception
     */
    public function updateProfile(User $user, array $validatedData, Request $request = null): User
    {
        try {
            DB::beginTransaction();

            // Update user basic information
            $user->update($validatedData);

            // Handle profile image update if provided
            if ($request && !empty($validatedData['profile_image'])) {
                SpatieMediaService::update($request, $user, SpatieMediaCollectionName::PROFILE_IMAGE());
            }

            DB::commit();

            return $user->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Attach a freshly-OTP-verified mobile number to an already-authenticated
     * user. Used when a social-signup user (no mobile yet) or any logged-in
     * user wants to add/change their mobile — the OTP/Firebase layer has
     * already proven they control the number.
     *
     * Optional extras finish the profile off in the same request:
     *   - `country_code` — E.164 dialing code with a leading '+' (e.g. "+91").
     *     Persisted when provided so we can split calling code from NSN.
     *   - `name`         — overwrite the display name (always applied when set)
     *   - `friends_code` — referrer's code. Only applied when the user has
     *     no friends_code yet; self-referral and code-conflict both reject.
     *     On first application we also call ReferralService::handleRegistration.
     *
     * Returns the project's success/failure envelope so controllers can pass
     * it straight through `ApiResponseType::sendJsonResponse`.
     *
     * @param  array{country_code?: string|null, name?: string|null, friends_code?: string|null}  $extras
     * @return array{success: bool, message: string, data: mixed}
     */
    public function attachVerifiedMobile(User $user, string $mobile, array $extras = []): array
    {
        // Store digits-only per convention — callers may pass E.164 (+15551…)
        // or local formats. Strip everything non-numeric so the column stays
        // canonical.
        $mobile = (string) preg_replace('/\D+/', '', $mobile);

        if ($mobile === '') {
            return [
                'success' => false,
                'message' => 'labels.something_went_wrong',
                'data'    => ['mobile' => $mobile],
            ];
        }

        // Keep the leading '+' on the calling code when present so it matches
        // the format the rest of the app (orders, addresses, sellers) uses.
        $countryCode = isset($extras['country_code']) ? trim((string) $extras['country_code']) : null;
        if ($countryCode !== null && $countryCode !== '' && ! str_starts_with($countryCode, '+')) {
            $countryCode = '+' . preg_replace('/\D+/', '', $countryCode);
        }
        if ($countryCode === '') {
            $countryCode = null;
        }

        // Block attachment if any OTHER active user already owns this number.
        $taken = User::query()
            ->where('mobile', $mobile)
            ->where('id', '!=', $user->id)
            ->whereNull('deleted_at')
            ->exists();

        if ($taken) {
            return [
                'success' => false,
                'message' => 'labels.mobile_already_in_use_by_another_user',
                'data'    => ['mobile' => $mobile],
            ];
        }

        $name        = isset($extras['name']) ? trim((string) $extras['name']) : null;
        $friendsCode = isset($extras['friends_code']) ? trim((string) $extras['friends_code']) : null;

        // Friends_code guards run before any writes so the response shape is
        // consistent: either everything succeeds, or nothing changes.
        if ($friendsCode !== null && $friendsCode !== '') {
            if (!empty($user->referral_code) && $friendsCode === $user->referral_code) {
                return [
                    'success' => false,
                    'message' => 'labels.cannot_use_own_referral_code',
                    'data'    => ['friends_code' => $friendsCode],
                ];
            }

            if (!empty($user->friends_code) && $user->friends_code !== $friendsCode) {
                return [
                    'success' => false,
                    'message' => 'labels.referral_code_already_applied',
                    'data'    => ['friends_code' => $user->friends_code],
                ];
            }
        }

        $applyReferral = $friendsCode !== null
            && $friendsCode !== ''
            && empty($user->friends_code);

        $updates = [
            'mobile'             => $mobile,
            'mobile_verified_at' => now(),
        ];
        if ($countryCode !== null) {
            $updates['country_code'] = $countryCode;
        }
        if ($name !== null && $name !== '') {
            $updates['name'] = $name;
        }
        if ($applyReferral) {
            $updates['friends_code'] = $friendsCode;
        }

        $user->forceFill($updates)->save();
        $fresh = $user->fresh();

        if ($applyReferral) {
            try {
                app(ReferralService::class)->handleRegistration($fresh, $friendsCode);
            } catch (\Throwable $th) {
                Log::error('Referral link creation failed for user ' . $fresh->id . ': ' . $th->getMessage());
            }
        }

        return [
            'success' => true,
            'message' => 'labels.mobile_verified_successfully',
            'data'    => $fresh,
        ];
    }

    /**
     * Update the user's email address and dispatch the Laravel email
     * verification notification. The new email is marked unverified until
     * the user clicks the signed link in the mail.
     *
     * @throws \Throwable
     */
    public function updateEmail(User $user, string $newEmail): User
    {
        return DB::transaction(function () use ($user, $newEmail) {
            $user->forceFill([
                'email'             => $newEmail,
                'email_verified_at' => null,
            ])->save();

            // Uses Illuminate\Auth\Notifications\VerifyEmail by default.
            // Fires the `verification.verify` signed URL to the new address.
            $user->sendEmailVerificationNotification();

            return $user->fresh();
        });
    }

    /**
     * Get user profile data
     *
     * @param User $user
     * @return array
     */
    public function getProfileData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'profile_image' => $user->profile_image,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }
}
