<?php

declare(strict_types=1);

use App\Features\Catalog\Actions\CreateProduct;
use App\Features\Catalog\Actions\UpdateProduct;
use App\Features\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('creates products inactive even when active state is submitted', function (): void {
    $product = resolve(CreateProduct::class)(validProductAttributes(['is_active' => true]));

    expect($product->is_active)->toBeFalse();
});

it('preserves the existing active state when updating a product', function (): void {
    $active = Product::factory()->active()->create();

    $updated = resolve(UpdateProduct::class)($active, validProductAttributes(['name' => 'Novo nome']));

    expect($updated->name)->toBe('Novo nome')
        ->and($updated->is_active)->toBeTrue();
});

it('translates a duplicate product sku into a validation exception', function (): void {
    Product::factory()->create(['sku' => 'DUPLICATE']);

    expect(fn () => resolve(CreateProduct::class)(validProductAttributes(['sku' => 'DUPLICATE'])))
        ->toThrow(ValidationException::class);
});

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

function validProductAttributes(array $overrides = []): array
{
    return array_merge([
        'sku' => 'ABC-123',
        'name' => 'Produto',
        'description' => 'Descrição simples',
        'price_cents' => 1990,
    ], $overrides);
}
