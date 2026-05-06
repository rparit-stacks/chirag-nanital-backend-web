<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Models\User;
use App\Services\OtpService;
use App\Services\ProfileService;
use App\Services\WalletService;
use App\Services\SettingService;
use App\Enums\SettingTypeEnum;
use App\Types\Api\ApiResponseType;
use App\Http\Resources\User\UserResource;
use App\Events\Auth\UserLoggedIn;
use App\Events\Auth\UserRegistered;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

#[Group('Auth')]
class OtpApiController extends Controller
{
    public function __construct(
        protected OtpService     $otpService,
        protected SettingService $settingService,
        protected ProfileService $profileService,
    )
    {
    }

    /**
     * Send OTP to mobile number
     */
    public function sendOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'mobile' => 'required|string',
        ]);

        if ($validator->fails()) {
            return ApiResponseType::sendJsonResponse(
                false,
                'Validation failed',
                ['errors' => $validator->errors()]
            );
        }

        $mobile = $request->input('mobile');

        // Send OTP
        $result = $this->otpService->sendOtp($mobile);

        Log::info('Send OTP Response', [
            'input_mobile' => $mobile,
            'result' => $result
        ]);

        if ($result['success']) {
            Log::info('Send OTP Success Response', [
                'mobile' => $mobile,
                'expires_in' => $result['expires_in'],
                'message' => $result['message']
            ]);

            return ApiResponseType::sendJsonResponse(
                true,
                $result['message'],
                [
                    'mobile' => $mobile,
                    'expires_in' => $result['expires_in']
                ]
            );
        }

        Log::error('Send OTP Failed Response', [
            'mobile' => $mobile,
            'error_message' => $result['message'],
            'debug_info' => $result['debug'] ?? []
        ]);

        return ApiResponseType::sendJsonResponse(
            false,
            $result['message'],
            $result['debug'] ?? []
        );
    }

    /**
     * Verify a mobile OTP and authenticate the caller.
     *
     * Three outcomes:
     *  - Sanctum bearer present: attach + verify mobile on the authed user
     *    (no new token). Optional name / friends_code are applied.
     *  - No bearer, existing mobile: log the matching user in, mint a token.
     *  - No bearer, unknown mobile: if name + password are provided create
     *    the user and mint a token; otherwise return a `new_user` hint so
     *    the client can collect the missing registration fields.
     *
     * @param VerifyOtpRequest $request
     * @return JsonResponse
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $sanitizedMobile = $this->otpService->sanitizeMobile($validated['mobile']);
            $authedUser = auth('sanctum')->user();

            if (!$authedUser) {
                $existingUser = User::withTrashed()->where('mobile', $sanitizedMobile)->first();

                if (!$existingUser && (empty($validated['name']) || empty($validated['password']))) {
                    return ApiResponseType::sendJsonResponse(
                        true,
                        'labels.verified_successfully',
                        [
                            'new_user' => true,
                            'mobile' => $sanitizedMobile,
                            'otp_verified' => false,
                        ]
                    );
                }
            }

            $verificationResult = $this->otpService->verifyOtp($sanitizedMobile, $validated['otp']);
            if (!$verificationResult['success']) {
                return ApiResponseType::sendJsonResponse(
                    false,
                    $verificationResult['message'],
                    $verificationResult
                );
            }

            // Authed branch: attach + verify the mobile on the current user.
            if ($authedUser) {
                $attach = $this->profileService->attachVerifiedMobile(
                    $authedUser,
                    $sanitizedMobile,
                    [
                        'name' => $validated['name'] ?? null,
                        'friends_code' => $validated['friends_code'] ?? null,
                    ]
                );

                return ApiResponseType::sendJsonResponse(
                    $attach['success'],
                    $attach['message'],
                    $attach['success']
                        ? new UserResource($attach['data'])
                        : $attach['data']
                );
            }

            // Anon branch: log in existing user, or create a new one (pending
            // is already handled above before OTP verification).
            $resolved = $this->otpService->resolveMobileUser($sanitizedMobile, $validated);

            $messageKey = $resolved['status'] === 'created'
                ? 'labels.registration_successful'
                : 'labels.verified_successfully';

            return $this->tokenResponse($resolved['user'], $messageKey, $sanitizedMobile);
        } catch (\Throwable $e) {
            Log::error('verifyOtp failed: ' . $e->getMessage());

            return ApiResponseType::sendJsonResponse(
                false,
                'labels.something_went_wrong',
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Build the standard token-bearing JSON response used by the anon
     * login/register path. Kept separate from `ApiResponseType` because
     * the top-level `access_token` / `token_type` shape is part of the
     * historical client contract.
     */
    protected function tokenResponse(User $user, string $messageKey, string $tokenName): JsonResponse
    {
        $token = $user->createToken($tokenName)->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => __($messageKey),
            'access_token' => $token,
            'token_type' => 'Bearer',
            'data' => new UserResource($user),
        ]);
    }

    /**
     * Web Registration: Capture details first, then send OTP
     */
    public function webRegister(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'mobile' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
            'country' => 'nullable|string|max:255',
            'iso_2' => 'nullable|string|max:2',
        ]);

        if ($validator->fails()) {
            return ApiResponseType::sendJsonResponse(
                false,
                'Validation failed',
                ['errors' => $validator->errors()]
            );
        }

        // Sanitize mobile number for consistent lookup
        $mobile = $this->otpService->sanitizeMobile($request->input('mobile'));
        $email = $request->input('email');

        // Check if user exists with this mobile
        $user = User::where('mobile', $mobile)->withTrashed()->first();

        if ($user) {
            if ($user->trashed()) {
                return ApiResponseType::sendJsonResponse(
                    false,
                    'Account has been deleted. Please contact support.',
                    []
                );
            }
            // If user exists and mobile is verified, they should login instead
            if ($user->mobile_verified_at) {
                return ApiResponseType::sendJsonResponse(
                    false,
                    'User already registered and verified. Please login.',
                    []
                );
            }

            // User exists but not verified - update details
            $user->update([
                'name' => $request->input('name'),
                'email' => $email,
                'password' => Hash::make($request->input('password')),
                'country' => $request->input('country'),
                'iso_2' => $request->input('iso_2'),
            ]);
        } else {
            // Check if email is taken by another user
            $emailUser = User::where('email', $email)->first();
            if ($emailUser) {
                return ApiResponseType::sendJsonResponse(
                    false,
                    'Email is already in use by another account.',
                    []
                );
            }

            // Create new inactive user
            $user = User::create([
                'name' => $request->input('name'),
                'email' => $email,
                'mobile' => $mobile,
                'password' => Hash::make($request->input('password')),
                'country' => $request->input('country'),
                'iso_2' => $request->input('iso_2'),
                'status' => 0, // Inactive until OTP verified
                'mobile_verified_at' => null,
            ]);
        }

        // Send OTP
        $result = $this->otpService->sendOtp($mobile);

        if ($result['success']) {
            return ApiResponseType::sendJsonResponse(
                true,
                'Registration details saved. ' . $result['message'],
                [
                    'mobile' => $mobile,
                    'expires_in' => $result['expires_in']
                ]
            );
        }

        return ApiResponseType::sendJsonResponse(
            false,
            'Failed to send OTP: ' . $result['message'],
            $result['debug'] ?? []
        );
    }

    /**
     * Web Verify OTP: Verify and Activate User
     */
    public function webVerifyOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'mobile' => 'required|string',
            'otp' => 'required|string',
        ]);

        if ($validator->fails()) {
            return ApiResponseType::sendJsonResponse(
                false,
                'Validation failed',
                ['errors' => $validator->errors()]
            );
        }

        $mobile = $this->otpService->sanitizeMobile($request->input('mobile'));
        $otp = $request->input('otp');

        // Verify OTP via service
        $verificationResult = $this->otpService->verifyOtp($mobile, $otp);

        if (!$verificationResult['success']) {
            return ApiResponseType::sendJsonResponse(
                false,
                $verificationResult['message'],
                $verificationResult
            );
        }

        // OTP Valid - Activate User
        try {
            $user = User::where('mobile', $mobile)->first();

            if (!$user) {
                return ApiResponseType::sendJsonResponse(
                    false,
                    'User not found.',
                    []
                );
            }

            // Activate user if not already active
            if ($user->status == 0 || $user->mobile_verified_at == null) {
                $user->update([
                    'status' => 1,
                    'mobile_verified_at' => now(),
                ]);

                // Grant welcome wallet balance if configured (First time verification)
                try {
                    $systemSettingsResource = $this->settingService->getSettingByVariable(SettingTypeEnum::SYSTEM());
                    $systemSettings = $systemSettingsResource?->toArray($request)['value'] ?? [];
                    $welcomeAmount = (float)($systemSettings['welcomeWalletBalanceAmount'] ?? 0);

                    if ($welcomeAmount > 0) {
                        $walletService = app(WalletService::class);
                        // Check if wallet balance already exists to avoid double credit if they retry
                        if ($user->wallet && $user->wallet->balance == 0) {
                            $walletService->addBalance($user->id, [
                                'amount' => $welcomeAmount,
                                'payment_method' => 'system',
                                'description' => __('labels.welcome_wallet_bonus') ?? 'Welcome bonus added to wallet',
                            ]);
                        }
                    }
                } catch (\Throwable $th) {
                    Log::error('Welcome wallet credit failed for user ' . $user->id . ': ' . $th->getMessage());
                }

                event(new UserRegistered($user));
            }

            // Login
            $token = $user->createToken($mobile)->plainTextToken;
            event(new UserLoggedIn($user));

            return response()->json([
                'success' => true,
                'message' => __('labels.registration_successful'),
                'access_token' => $token,
                'token_type' => 'Bearer',
                'data' => new UserResource($user)
            ]);

        } catch (\Exception $e) {
            Log::error('Web OTP verification failed', [
                'mobile' => $mobile,
                'error' => $e->getMessage()
            ]);

            return ApiResponseType::sendJsonResponse(
                false,
                'Authentication failed: ' . $e->getMessage(),
                []
            );
        }
    }
}
