<?php

namespace App\Http\Controllers\Api\User;

use App\Enums\SettingTypeEnum;
use App\Events\Auth\UserLoggedIn;
use App\Events\Auth\UserRegistered;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AppleCallbackRequest;
use App\Http\Requests\Auth\GoogleCallbackRequest;
use App\Http\Requests\User\VerifyUserRequest;
use App\Http\Resources\User\UserResource;
use App\Models\User;
use App\Services\SettingService;
use App\Services\SocialAuthService;
use App\Traits\AuthTrait;
use App\Types\Api\ApiResponseType;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;

#[Group('Auth')]
class AuthApiController extends Controller
{
    use AuthTrait;

    public function __construct(
        protected SettingService $settingService,
        protected SocialAuthService $socialAuthService,
    ) {
    }

    /**
     * Verify if user exists by email or mobile.
     *
     * When `type` is `mobile`, the lookup tries the raw value as well as
     * the country-code + value concatenation (with and without the leading
     * `+`) so the caller can pass either just the national number plus a
     * `country_code`, or a pre-joined E.164 number in `value`.
     *
     * @param VerifyUserRequest $request
     * @return JsonResponse
     */
    public function verifyUser(VerifyUserRequest $request): JsonResponse
    {
        try {
            $type        = $request->input('type');
            $value       = $request->input('value');
            $countryCode = $request->input('country_code');

            $user = null;

            if ($type === 'email') {
                $user = User::where('email', $value)->first();
            } elseif ($type === 'mobile') {
                $candidates = $this->buildMobileCandidates($value, $countryCode);
                $user = User::whereIn('mobile', $candidates)->first();
            }

            $exists = !is_null($user);

            $responseData = [
                'exists'       => $exists,
                'type'         => $type,
                'value'        => $value,
                'country_code' => $countryCode,
            ];

            if ($exists) {
                return ApiResponseType::sendJsonResponse(
                    true,
                    'labels.user_found',
                    $responseData
                );
            } else {
                return ApiResponseType::sendJsonResponse(
                    false,
                    'labels.user_not_found',
                    $responseData
                );
            }

        } catch (\Exception $e) {
            return ApiResponseType::sendJsonResponse(
                false,
                'labels.something_went_wrong',
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Build the set of mobile strings to match against `users.mobile`.
     * Covers: raw value, digits-only, digits-only prefixed with the
     * country code, and the `+countryCode value` form. Duplicates and
     * empty entries are filtered out.
     *
     * @return array<int, string>
     */
    protected function buildMobileCandidates(string $value, ?string $countryCode): array
    {
        $rawDigits = preg_replace('/\D+/', '', $value);

        $candidates = [$value, $rawDigits];

        if (!empty($countryCode)) {
            $codeDigits = preg_replace('/\D+/', '', $countryCode);

            if ($codeDigits !== '' && $rawDigits !== '') {
                $candidates[] = $codeDigits . $rawDigits;
                $candidates[] = '+' . $codeDigits . $rawDigits;
                $candidates[] = '+' . $codeDigits . ' ' . $rawDigits;
            }
        }

        return array_values(array_unique(array_filter($candidates, fn ($c) => $c !== '' && $c !== null)));
    }



    /**
     * Sign in (or register) with a Google Firebase ID token.
     *
     * Single round-trip: verifies the token, looks up the user by email or
     * firebase_uid, creates them on the fly if new. `mobile` and `password`
     * are left null — the app can ask the user to set them later.
     *
     * @param GoogleCallbackRequest $request
     * @return JsonResponse
     */
    public function googleCallback(GoogleCallbackRequest $request): JsonResponse
    {
        try {
            $authSetting = $this->settingService->getSettingByVariable(SettingTypeEnum::AUTHENTICATION());
            $authConfig  = $authSetting?->value ?? [];
            if (empty($authConfig['googleLogin'])) {
                return ApiResponseType::sendJsonResponse(
                    success: false,
                    message: 'labels.google_login_not_enabled',
                    data: [],
                );
            }

            $firebase = $this->resolveFirebaseUser($request->input('idToken'));
            if ($firebase instanceof JsonResponse) {
                return $firebase;
            }

            $result = $this->socialAuthService->loginOrRegisterFromGoogle(
                $firebase['user'],
                $request->input('friends_code'),
                [
                    'country' => $request->input('country'),
                    'iso_2'   => $request->input('iso_2'),
                ],
            );

            return $this->respondWithToken($request, $result['user'], $result['is_new']);
        } catch (AuthenticationException $e) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: 'labels.authentication_error',
                data: ['error' => $e->getMessage()],
            );
        } catch (FailedToVerifyToken $e) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: 'labels.invalid_firebase_token',
                data: ['error' => $e->getMessage()],
            );
        } catch (\Exception $e) {
            Log::error('googleCallback failed: ' . $e->getMessage());
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: 'labels.something_went_wrong',
                data: ['error' => $e->getMessage()],
            );
        }
    }

    /**
     * Sign in (or register) with an Apple Firebase ID token.
     *
     * Apple sometimes withholds the user's email; we therefore key on the
     * Firebase UID first and fall back to email only when it's present. A
     * brand-new Apple-no-email user is created with `firebase_uid` set and
     * `email` / `mobile` / `password` all null.
     *
     * @param AppleCallbackRequest $request
     * @return JsonResponse
     */
    public function appleCallback(AppleCallbackRequest $request): JsonResponse
    {
        try {
            $firebase = $this->resolveFirebaseUser($request->input('idToken'));
            if ($firebase instanceof JsonResponse) {
                return $firebase;
            }

            $result = $this->socialAuthService->loginOrRegisterFromApple(
                $firebase['user'],
                $firebase['claims'],
                $request->input('friends_code'),
                [
                    'country' => $request->input('country'),
                    'iso_2'   => $request->input('iso_2'),
                ],
            );

            return $this->respondWithToken($request, $result['user'], $result['is_new']);
        } catch (AuthenticationException $e) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: 'labels.authentication_error',
                data: ['error' => $e->getMessage()],
            );
        } catch (FailedToVerifyToken $e) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: 'labels.invalid_firebase_token',
                data: ['error' => $e->getMessage()],
            );
        } catch (\Exception $e) {
            Log::error('appleCallback failed: ' . $e->getMessage());
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: 'labels.something_went_wrong',
                data: ['error' => $e->getMessage()],
            );
        }
    }

    /**
     * Bootstrap Firebase Auth, verify the supplied ID token, and resolve the
     * UserRecord + raw claims. Returns a short-circuit JsonResponse when the
     * bootstrap fails — the caller should `return` it directly. Throws
     * FailedToVerifyToken / AuthenticationException, which the caller's
     * try/catch already handles.
     *
     * @return JsonResponse|array{user: UserRecord, claims: array<string, mixed>}
     */
    protected function resolveFirebaseUser(string $idToken): JsonResponse|array
    {
        $firebaseAuth = $this->getFirebaseAuth();
        if ($firebaseAuth['success'] === false) {
            return ApiResponseType::sendJsonResponse(
                $firebaseAuth['success'],
                $firebaseAuth['message'],
                $firebaseAuth['data'],
            );
        }
        $firebaseAuth = $firebaseAuth['data'];

        $verifiedIdToken = $firebaseAuth->verifyIdToken($idToken);
        $uid             = $verifiedIdToken->claims()->get('sub');

        return [
            'user'   => $firebaseAuth->getUser($uid),
            'claims' => $verifiedIdToken->claims()->all(),
        ];
    }

    /**
     * Finalize a social sign-in: store FCM token, fire the right event, and
     * return the Sanctum token + user resource in the standard envelope.
     */
    protected function respondWithToken(Request $request, User $user, bool $isNew): JsonResponse
    {
        $this->storeFcmToken($request, $user);

        event($isNew ? new UserRegistered($user) : new UserLoggedIn($user));

        $tokenName = $user->email ?? $user->firebase_uid ?? 'api-token';

        return response()->json([
            'success'      => true,
            'message'      => __($isNew ? 'labels.registration_successful' : 'labels.login_successful'),
            'access_token' => $user->createToken($tokenName)->plainTextToken,
            'token_type'   => 'Bearer',
            'data'         => new UserResource($user),
        ]);
    }
}
