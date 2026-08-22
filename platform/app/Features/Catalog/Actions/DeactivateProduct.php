<?php

declare(strict_types=1);

namespace App\Features\Catalog\Actions;

use App\Features\Catalog\Models\Product;

final class DeactivateProduct
{
    public function __invoke(Product $product): Product
    {
        $product->forceFill(['is_active' => false])->save();

        return $product->refresh();
    }
}
