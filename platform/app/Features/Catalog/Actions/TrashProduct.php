<?php

declare(strict_types=1);

namespace App\Features\Catalog\Actions;

use App\Features\Catalog\Models\Product;
use Illuminate\Support\Facades\DB;

final class TrashProduct
{
    public function handle(Product $product): Product
    {
        return DB::transaction(function () use ($product): Product {
            $product->forceFill(['is_active' => false])->save();
            $product->delete();

            return $product->refresh();
        });
    }
}
