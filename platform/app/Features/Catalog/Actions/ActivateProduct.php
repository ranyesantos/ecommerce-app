<?php

declare(strict_types=1);

namespace App\Features\Catalog\Actions;

use App\Features\Catalog\Models\Product;
use LogicException;

final class ActivateProduct
{
    public function handle(Product $product): Product
    {
        throw_if($product->trashed(), LogicException::class, 'Um produto na lixeira não pode ser ativado.');

        $product->forceFill(['is_active' => true])->save();

        return $product->refresh();
    }
}
