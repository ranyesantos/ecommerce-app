<?php

declare(strict_types=1);

namespace App\Features\Catalog\Actions;

use App\Features\Catalog\Models\Product;
use App\Features\Catalog\Support\ProductSkuConflict;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;

final class CreateProduct
{
    /** @param array<string, mixed> $attributes */
    public function handle(array $attributes): Product
    {
        try {
            $product = new Product(Arr::only($attributes, ['sku', 'name', 'description', 'price_cents']));
            $product->forceFill(['is_active' => false])->save();

            return $product;
        } catch (QueryException $queryException) {
            ProductSkuConflict::rethrow($queryException);
        }
    }
}
