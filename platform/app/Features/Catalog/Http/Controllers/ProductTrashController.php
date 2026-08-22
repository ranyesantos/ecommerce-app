<?php

declare(strict_types=1);

namespace App\Features\Catalog\Http\Controllers;

use App\Features\Catalog\Actions\RestoreProduct;
use App\Features\Catalog\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class ProductTrashController
{
    public function index(): View
    {
        return view('catalog.products.trash', [
            'products' => Product::onlyTrashed()->latest('deleted_at')->paginate(15),
        ]);
    }

    public function restore(Product $product, RestoreProduct $restoreProduct): RedirectResponse
    {
        $restoreProduct->handle($product);

        return to_route('products.edit', $product)
            ->with('status', 'Produto restaurado com sucesso.');
    }
}
