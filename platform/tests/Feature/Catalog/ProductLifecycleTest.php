<?php

declare(strict_types=1);

use App\Features\Catalog\Actions\ActivateProduct;
use App\Features\Catalog\Actions\DeactivateProduct;
use App\Features\Catalog\Actions\RestoreProduct;
use App\Features\Catalog\Actions\TrashProduct;
use App\Features\Catalog\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('activates an inactive product', function (): void {
    $product = Product::factory()->create();

    $activated = resolve(ActivateProduct::class)($product);

    expect($activated->is_active)->toBeTrue();
});

it('deactivates an active product', function (): void {
    $product = Product::factory()->active()->create();

    $deactivated = resolve(DeactivateProduct::class)($product);

    expect($deactivated->is_active)->toBeFalse()
        ->and($deactivated->trashed())->toBeFalse();
});

it('rejects activating a trashed product', function (): void {
    $product = Product::factory()->trashed()->create();

    expect(fn () => resolve(ActivateProduct::class)($product))
        ->toThrow(LogicException::class, 'Um produto na lixeira não pode ser ativado.');
});

it('trashes a product as inactive and hides it from normal queries', function (): void {
    $product = Product::factory()->active()->create();

    $trashed = resolve(TrashProduct::class)($product);

    expect($trashed->is_active)->toBeFalse()
        ->and($trashed->trashed())->toBeTrue()
        ->and(Product::query()->find($product->id))->toBeNull()
        ->and(Product::onlyTrashed()->find($product->id))->not->toBeNull();
});

it('restores a product as inactive', function (): void {
    $trashed = resolve(TrashProduct::class)(Product::factory()->active()->create());

    $restored = resolve(RestoreProduct::class)($trashed);

    expect($restored->trashed())->toBeFalse()
        ->and($restored->is_active)->toBeFalse();
});

it('rejects restoring an active product without changing its state', function (): void {
    $product = Product::query()->create([
        'sku' => 'ACTIVE-RESTORE-1',
        'name' => 'Active product',
        'price_cents' => 100,
    ]);
    $product->forceFill(['is_active' => true])->save();

    expect(fn () => resolve(RestoreProduct::class)($product))
        ->toThrow(ModelNotFoundException::class);

    expect($product->fresh()->is_active)->toBeTrue()
        ->and($product->fresh()->trashed())->toBeFalse();
});
