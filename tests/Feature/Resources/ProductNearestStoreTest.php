<?php

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Models\Store;
use App\Models\StoreProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * User sits at (0,0). Stores are placed at increasing longitude so distance increases
 * monotonically from the far → mid → near store.
 */
beforeEach(function () {
    $this->seller = Seller::factory()->create();

    $this->farStore = Store::forceCreate([
        'seller_id' => $this->seller->id,
        'name'      => 'Far',
        'latitude'  => 0.0,
        'longitude' => 0.50, // ~55 km
    ]);

    $this->midStore = Store::forceCreate([
        'seller_id' => $this->seller->id,
        'name'      => 'Mid',
        'latitude'  => 0.0,
        'longitude' => 0.10, // ~11 km
    ]);

    $this->nearStore = Store::forceCreate([
        'seller_id' => $this->seller->id,
        'name'      => 'Near',
        'latitude'  => 0.0,
        'longitude' => 0.01, // ~1 km
    ]);

    $this->product = Product::forceCreate([
        'seller_id' => $this->seller->id,
        'title'     => 'Milk',
    ]);

    $this->variant = ProductVariant::forceCreate([
        'product_id' => $this->product->id,
        'title'      => '1L',
    ]);
});

function makeStoreProductVariant(int $variantId, int $storeId, int $stock, string $sku): StoreProductVariant
{
    return StoreProductVariant::forceCreate([
        'product_variant_id' => $variantId,
        'store_id'           => $storeId,
        'sku'                => $sku,
        'price'              => 10,
        'special_price'      => 10,
        'cost'               => 5,
        'stock'              => $stock,
    ]);
}

function loadedProduct(Product $product): Product
{
    return Product::with(['variants.storeProductVariants.store'])->find($product->id);
}

it('picks the nearest in-stock store when several stores carry the product in one zone', function () {
    // All three stores have stock; the near store is the closest to the user.
    makeStoreProductVariant($this->variant->id, $this->farStore->id,  20, 'FAR');
    makeStoreProductVariant($this->variant->id, $this->midStore->id,  10, 'MID');
    makeStoreProductVariant($this->variant->id, $this->nearStore->id, 5,  'NEAR');

    $product = loadedProduct($this->product);
    $product->preferNearestStoreVariants(0.0, 0.0);

    $first = $product->variants->first()->storeProductVariants->first();

    expect($first->sku)->toBe('NEAR')
        ->and((int) $first->store_id)->toBe($this->nearStore->id);
});

it('falls back to the next nearest store when the nearest is out of stock', function () {
    makeStoreProductVariant($this->variant->id, $this->nearStore->id, 0,  'NEAR');
    makeStoreProductVariant($this->variant->id, $this->midStore->id,  8,  'MID');
    makeStoreProductVariant($this->variant->id, $this->farStore->id,  20, 'FAR');

    $product = loadedProduct($this->product);
    $product->preferNearestStoreVariants(0.0, 0.0);

    $first = $product->variants->first()->storeProductVariants->first();

    // In-stock stores rank ahead of out-of-stock ones; among in-stock, mid is nearer than far.
    expect($first->sku)->toBe('MID')
        ->and((int) $first->store_id)->toBe($this->midStore->id);
});

it('returns the nearest store anyway when every store is out of stock', function () {
    makeStoreProductVariant($this->variant->id, $this->farStore->id,  0, 'FAR');
    makeStoreProductVariant($this->variant->id, $this->midStore->id,  0, 'MID');
    makeStoreProductVariant($this->variant->id, $this->nearStore->id, 0, 'NEAR');

    $product = loadedProduct($this->product);
    $product->preferNearestStoreVariants(0.0, 0.0);

    $first = $product->variants->first()->storeProductVariants->first();

    expect($first->sku)->toBe('NEAR');
});

it('is a no-op when variants relation has not been loaded', function () {
    makeStoreProductVariant($this->variant->id, $this->nearStore->id, 5, 'NEAR');

    $product = Product::find($this->product->id);

    // Should not throw and not attempt to access unloaded relations.
    $product->preferNearestStoreVariants(0.0, 0.0);

    expect($product->relationLoaded('variants'))->toBeFalse();
});
