<?php

declare(strict_types=1);

namespace App\Features\Catalog\Http\Controllers;

use App\Features\Catalog\Actions\ActivateProduct;
use App\Features\Catalog\Actions\DeactivateProduct;
use App\Features\Catalog\Models\Product;
use Illuminate\Http\RedirectResponse;

final class ProductStatusController
{
    public function activate(Product $product, ActivateProduct $activateProduct): RedirectResponse
    {
        $activateProduct($product);

        return to_route('products.show', $product)
            ->with('status', 'Produto ativado com sucesso.');
    }

    public function deactivate(Product $product, DeactivateProduct $deactivateProduct): RedirectResponse
    {
        $deactivateProduct($product);

        return to_route('products.show', $product)
            ->with('status', 'Produto desativado com sucesso.');
    }
}
