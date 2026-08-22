<?php

declare(strict_types=1);

namespace App\Features\Catalog\Actions;

use LogicException;
use App\Features\Catalog\Models\Product;

final class ActivateProduct
{
    public function __invoke(Product $product): Product
    {
        throw_if($product->trashed(), LogicException::class, 'Um produto na lixeira não pode ser ativado.');

        $product->forceFill(['is_active' => true])->save();

        return $product->refresh();
    }
}
