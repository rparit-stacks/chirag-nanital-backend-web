<?php

use App\Enums\Addon\AddonGroupSelectionTypeEnum;
use App\Enums\Addon\AddonGroupStatusEnum;
use App\Enums\DefaultSystemRolesEnum;
use App\Enums\GuardNameEnum;
use App\Models\AddonGroup;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    // User + seller with the "seller" guard role so policies pass the role-based bypass.
    $this->user   = User::factory()->create(['access_panel' => GuardNameEnum::SELLER->value]);
    $this->seller = Seller::factory()->create(['user_id' => $this->user->id]);

    Role::findOrCreate(DefaultSystemRolesEnum::SELLER->value, GuardNameEnum::SELLER->value);
    $this->user->assignRole(DefaultSystemRolesEnum::SELLER->value);

    Sanctum::actingAs($this->user);
});

it('lists addon groups owned by the seller', function () {
    AddonGroup::create([
        'seller_id'      => $this->seller->id,
        'title'          => 'Toppings',
        'selection_type' => AddonGroupSelectionTypeEnum::MULTIPLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    $this->getJson('/api/seller/addon-groups')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.title', 'Toppings');
});

it('creates an addon group with items via the API', function () {
    $response = $this->postJson('/api/seller/addon-groups', [
        'title'          => 'Sauces',
        'selection_type' => AddonGroupSelectionTypeEnum::SINGLE->value,
        'is_required'    => false,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
        'items' => [
            ['title' => 'Garlic', 'price' => 0.5, 'status' => AddonGroupStatusEnum::ACTIVE->value],
            ['title' => 'Ranch',  'price' => 0.5, 'status' => AddonGroupStatusEnum::ACTIVE->value],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.title', 'Sauces')
        ->assertJsonCount(2, 'data.items');

    expect(AddonGroup::where('seller_id', $this->seller->id)->count())->toBe(1);
});

it('rejects updating an addon group owned by a different seller', function () {
    $otherSeller = Seller::factory()->create();
    $foreign = AddonGroup::create([
        'seller_id'      => $otherSeller->id,
        'title'          => 'Foreign Group',
        'selection_type' => AddonGroupSelectionTypeEnum::MULTIPLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    $this->postJson("/api/seller/addon-groups/{$foreign->id}", [
        'title'          => 'Hacked',
        'selection_type' => AddonGroupSelectionTypeEnum::MULTIPLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
        'items' => [
            ['title' => 'Tampered', 'price' => 1, 'status' => AddonGroupStatusEnum::ACTIVE->value],
        ],
    ])->assertStatus(404);
});

it('soft deletes an owned addon group', function () {
    $group = AddonGroup::create([
        'seller_id'      => $this->seller->id,
        'title'          => 'Sides',
        'selection_type' => AddonGroupSelectionTypeEnum::MULTIPLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    $this->deleteJson("/api/seller/addon-groups/{$group->id}")
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(AddonGroup::find($group->id))->toBeNull()
        ->and(AddonGroup::withTrashed()->find($group->id))->not->toBeNull();
});

it('returns the enums payload for building the form on clients', function () {
    $this->getJson('/api/seller/addon-groups/enums')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['statuses', 'selection_types', 'indicators']]);
});
