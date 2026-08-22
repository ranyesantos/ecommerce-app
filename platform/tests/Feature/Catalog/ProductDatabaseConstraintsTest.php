<?php

declare(strict_types=1);

use App\Features\Catalog\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('rejects non-positive prices at the database boundary', function (int $price): void {
    expect(fn () => insertProduct(['price_cents' => $price]))
        ->toThrow(QueryException::class);
})->with([0, -1]);

it('rejects an active soft deleted product at the database boundary', function (): void {
    expect(fn () => insertProduct([
        'is_active' => true,
        'deleted_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('reserves the sku of a soft deleted product', function (): void {
    Product::factory()->trashed()->create(['sku' => 'RESERVED-1']);

    expect(fn () => Product::factory()->create(['sku' => 'RESERVED-1']))
        ->toThrow(QueryException::class);
});

function insertProduct(array $overrides = []): void
{
    DB::table('products')->insert(array_merge([
        'id' => (string) Str::uuid7(),
        'sku' => Str::upper('SKU-'.Str::random(8)),
        'name' => 'Test product',
        'description' => null,
        'price_cents' => 100,
        'is_active' => false,
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => null,
    ], $overrides));
}
