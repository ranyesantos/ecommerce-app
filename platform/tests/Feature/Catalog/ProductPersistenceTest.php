<?php

declare(strict_types=1);

use App\Features\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a product inactive and normalizes its text fields', function (): void {
    $product = Product::factory()->create([
        'sku' => '  abc-123  ',
        'name' => '  Café especial  ',
        'description' => '   ',
        'price_cents' => 1990,
    ]);

    expect($product)
        ->sku->toBe('ABC-123')
        ->name->toBe('Café especial')
        ->description->toBeNull()
        ->price_cents->toBe(1990)
        ->is_active->toBeFalse()
        ->deleted_at->toBeNull();
});

it('hides soft deleted products from normal queries', function (): void {
    $product = Product::factory()->create();
    $product->delete();

    expect(Product::query()->find($product->id))->toBeNull()
        ->and(Product::withTrashed()->find($product->id))->not->toBeNull();
});
