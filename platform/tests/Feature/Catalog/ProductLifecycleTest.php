<?php

declare(strict_types=1);

use App\Features\Catalog\Actions\ActivateProduct;
use App\Features\Catalog\Actions\DeactivateProduct;
use App\Features\Catalog\Actions\RestoreProduct;
use App\Features\Catalog\Actions\TrashProduct;
use App\Features\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('activates an inactive product', function (): void {
    $product = Product::factory()->create();

    $activated = app(ActivateProduct::class)($product);

    expect($activated->is_active)->toBeTrue();
});

it('deactivates an active product', function (): void {
    $product = Product::factory()->active()->create();

    $deactivated = app(DeactivateProduct::class)($product);

    expect($deactivated->is_active)->toBeFalse()
        ->and($deactivated->trashed())->toBeFalse();
});

it('rejects activating a trashed product', function (): void {
    $product = Product::factory()->trashed()->create();

    expect(fn () => app(ActivateProduct::class)($product))
        ->toThrow(LogicException::class, 'Um produto na lixeira não pode ser ativado.');
});

it('trashes a product as inactive and hides it from normal queries', function (): void {
    $product = Product::factory()->active()->create();

    $trashed = app(TrashProduct::class)($product);

    expect($trashed->is_active)->toBeFalse()
        ->and($trashed->trashed())->toBeTrue()
        ->and(Product::query()->find($product->id))->toBeNull()
        ->and(Product::onlyTrashed()->find($product->id))->not->toBeNull();
});

it('restores a product as inactive', function (): void {
    $trashed = app(TrashProduct::class)(Product::factory()->active()->create());

    $restored = app(RestoreProduct::class)($trashed);

    expect($restored->trashed())->toBeFalse()
        ->and($restored->is_active)->toBeFalse();
});
