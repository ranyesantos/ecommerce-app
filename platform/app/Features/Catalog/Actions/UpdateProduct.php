<?php

declare(strict_types=1);

namespace App\Features\Catalog\Actions;

use App\Features\Catalog\Models\Product;
use App\Features\Catalog\Support\ProductSkuConflict;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;

final class UpdateProduct
{
    /** @param array<string, mixed> $attributes */
    public function handle(Product $product, array $attributes): Product
    {
        try {
            $product->update(Arr::only($attributes, ['sku', 'name', 'description', 'price_cents']));

            return $product->refresh();
        } catch (QueryException $queryException) {
            ProductSkuConflict::rethrow($queryException);
        }
    }
}
