<?php

namespace Database\Factories;

use App\Enums\Wallet\WalletTypeEnum;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

class WalletFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Wallet::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => WalletTypeEnum::CUSTOMER->value,
            'balance' => fake()->randomFloat(2, 0, 99999999.99),
            'currency_code' => fake()->regexify('[A-Za-z0-9]{3}'),
        ];
    }

    /**
     * Seller payout wallet.
     */
    public function seller(): static
    {
        return $this->state(fn () => ['type' => WalletTypeEnum::SELLER->value]);
    }

    /**
     * Delivery-boy payout wallet.
     */
    public function deliveryBoy(): static
    {
        return $this->state(fn () => ['type' => WalletTypeEnum::DELIVERY_BOY->value]);
    }
}
