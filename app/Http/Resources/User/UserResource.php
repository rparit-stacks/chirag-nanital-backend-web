<?php

namespace App\Http\Resources\User;

use App\Enums\Wallet\WalletTypeEnum;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Explicit wallet type chosen by the caller. When null, the resource
     * falls back to the request path prefix (delivery-boy / seller / else).
     */
    protected ?WalletTypeEnum $walletType = null;

    /**
     * Opt in to a specific wallet type for the `wallet_balance` /
     * `blocked_balance` / `available_balance` fields. Use this whenever the
     * same User may have multiple wallets and the panel context isn't
     * obvious from the request (e.g. shared trait helpers).
     */
    public function withWalletType(WalletTypeEnum|string $type): self
    {
        $this->walletType = $type instanceof WalletTypeEnum
            ? $type
            : (WalletTypeEnum::tryFrom($type) ?? WalletTypeEnum::CUSTOMER);

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $wallet = $this->resolveWallet($request);

        $balance        = $wallet?->balance ?? 0.00;
        $blockedBalance = $wallet?->blocked_balance ?? 0.00;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'country_code' => $this->country_code,
            'country' => $this->country,
            'iso_2' => $this->iso_2,
            'wallet_type' => $wallet?->type instanceof WalletTypeEnum
                ? $wallet->type->value
                : ($wallet?->type ?? $this->currentWalletType($request)->value),
            'wallet_balance' => $balance,
            'blocked_balance' => $blockedBalance,
            'available_balance' => number_format($balance - $blockedBalance, 2),
            'referral_code' => $this->referral_code,
            'friends_code' => $this->friends_code,
            'reward_points' => $this->reward_points,
            'profile_image' => $this->profile_image,
            'email_verified_at' => $this->email_verified_at?->format('Y-m-d H:i:s'),
            'mobile_verified_at' => $this->mobile_verified_at?->format('Y-m-d H:i:s'),
            'logged_in_type' => $this->logged_in_type?->value,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Resolve which wallet to surface on this response. Priority:
     *   1. Explicit ->withWalletType(...) call from the controller.
     *   2. Auto-detect from the current request path prefix.
     *   3. Fallback: customer wallet (preserves legacy behavior).
     */
    protected function resolveWallet(Request $request): ?Wallet
    {
        $type = $this->currentWalletType($request);

        // Fetch the wallet that matches the resolved type. We look this up
        // directly rather than via $this->wallet so we don't conflict with
        // the User::wallet() customer-scoped relation.
        return Wallet::query()
            ->where('user_id', $this->id)
            ->where('type', $type->value)
            ->first();
    }

    protected function currentWalletType(Request $request): WalletTypeEnum
    {
        if ($this->walletType instanceof WalletTypeEnum) {
            return $this->walletType;
        }

        $path = ltrim($request->path(), '/');

        return match (true) {
            str_starts_with($path, 'api/delivery-boy') => WalletTypeEnum::DELIVERY_BOY,
            str_starts_with($path, 'api/seller')       => WalletTypeEnum::SELLER,
            default                                    => WalletTypeEnum::CUSTOMER,
        };
    }
}
