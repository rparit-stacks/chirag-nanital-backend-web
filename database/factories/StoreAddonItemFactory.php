<?php

namespace Database\Factories;

use App\Models\StoreAddonItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory for StoreAddonItem.
 *
 * Callers must supply `store_id` and `addon_item_id` — no Store/AddonItem
 * factories exist yet in this repo, so we don't auto-create parents.
 */
class StoreAddonItemFactory extends Factory
{
    protected $model = StoreAddonItem::class;

    public function definition(): array
    {
        return [
            'uuid'         => (string) Str::uuid(),
            'price'        => fake()->randomFloat(2, 1, 50),
            'cost'         => fake()->randomFloat(2, 0, 25),
            'stock'        => fake()->numberBetween(0, 200),
            'is_available' => true,
            'metadata'     => null,
        ];
    }

    public function outOfStock(): self
    {
        return $this->state(fn () => ['stock' => 0]);
    }

    public function unavailable(): self
    {
        return $this->state(fn () => ['is_available' => false]);
    }
}
