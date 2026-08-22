<?php

declare(strict_types=1);

namespace App\Features\Catalog\Actions;

use App\Features\Catalog\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class RestoreProduct
{
    public function handle(Product $product): Product
    {
        if (! $product->trashed()) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$product->getKey()]);
        }

        return DB::transaction(function () use ($product): Product {
            $product->forceFill(['is_active' => false]);
            $product->restore();

            return $product->refresh();
        });
    }
}
