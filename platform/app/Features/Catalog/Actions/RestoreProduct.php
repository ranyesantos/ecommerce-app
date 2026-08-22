<?php

declare(strict_types=1);

namespace App\Features\Catalog\Actions;

use App\Features\Catalog\Models\Product;
use Illuminate\Support\Facades\DB;

final class RestoreProduct
{
    public function __invoke(Product $product): Product
    {
        return DB::transaction(function () use ($product): Product {
            $product->forceFill(['is_active' => false]);
            $product->restore();

            return $product->refresh();
        });
    }
}
