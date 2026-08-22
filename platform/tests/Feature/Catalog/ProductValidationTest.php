<?php

declare(strict_types=1);

use App\Features\Catalog\Http\Requests\StoreProductRequest;
use App\Features\Catalog\Http\Requests\UpdateProductRequest;
use App\Features\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Route::post('/_test/products', function (StoreProductRequest $request) {
        return response()->json($request->validated());
    });

    Route::put('/_test/products/{product}', function (UpdateProductRequest $request) {
        return response()->json($request->validated());
    });
});

it('normalizes product text before validating uniqueness', function (): void {
    $this->postJson('/_test/products', validProductPayload([
        'sku' => '  abc-123  ',
        'description' => '   ',
    ]))->assertOk()->assertExactJson([
        'sku' => 'ABC-123',
        'name' => 'Produto',
        'description' => null,
        'price_cents' => 1990,
    ]);
});

it('rejects a duplicate sku case-insensitively, including trashed products', function (): void {
    Product::factory()->trashed()->create(['sku' => 'ABC-123']);

    $this->postJson('/_test/products', validProductPayload(['sku' => ' abc-123 ']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sku']);
});

it('allows an update to keep its current sku', function (): void {
    $product = Product::factory()->create(['sku' => 'ABC-123']);

    $this->putJson('/_test/products/'.$product->id, validProductPayload(['sku' => ' abc-123 ']))
        ->assertOk();
});

it('rejects an update when another product owns the sku', function (): void {
    $product = Product::factory()->create(['sku' => 'ABC-123']);
    Product::factory()->create(['sku' => 'OTHER-123']);

    $this->putJson('/_test/products/'.$product->id, validProductPayload(['sku' => ' other-123 ']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sku']);
});

it('accepts normalized sku characters', function (): void {
    $this->postJson('/_test/products', validProductPayload(['sku' => ' abc_123-xyz ']))
        ->assertOk();
});

it('rejects invalid sku characters', function (string $sku): void {
    $this->postJson('/_test/products', validProductPayload(['sku' => $sku]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sku']);
})->with(['ABC 123', 'Café-123', 'ABC.123', 'ABC/123']);

it('requires a name and limits it to 255 characters', function (): void {
    $this->postJson('/_test/products', validProductPayload(['name' => '']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $this->postJson('/_test/products', validProductPayload(['name' => str_repeat('a', 256)]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('rejects html in the description', function (): void {
    $this->postJson('/_test/products', validProductPayload(['description' => 'Produto <b>forte</b>']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['description']);
});

it('rejects zero, negative, decimal, and non-numeric prices', function (mixed $price): void {
    $this->postJson('/_test/products', validProductPayload(['price_cents' => $price]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['price_cents']);
})->with([0, -1, 19.90, 'not-a-number']);

it('prohibits submitted is_active input', function (): void {
    $this->postJson('/_test/products', validProductPayload(['is_active' => true]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['is_active']);
});

function validProductPayload(array $overrides = []): array
{
    return array_merge([
        'sku' => 'ABC-123',
        'name' => 'Produto',
        'description' => 'Descrição simples',
        'price_cents' => 1990,
    ], $overrides);
}
