<?php

declare(strict_types=1);

namespace App\Features\Catalog\Http\Controllers;

use App\Features\Catalog\Actions\CreateProduct;
use App\Features\Catalog\Actions\TrashProduct;
use App\Features\Catalog\Actions\UpdateProduct;
use App\Features\Catalog\Http\Requests\StoreProductRequest;
use App\Features\Catalog\Http\Requests\UpdateProductRequest;
use App\Features\Catalog\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class ProductController
{
    public function index(): View
    {
        return view('catalog.products.index', [
            'products' => Product::query()->latest()->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('catalog.products.create');
    }

    public function store(StoreProductRequest $request, CreateProduct $createProduct): RedirectResponse
    {
        $product = $createProduct($request->validated());

        return to_route('products.show', $product)
            ->with('status', 'Produto criado com sucesso.');
    }

    public function show(Product $product): View
    {
        return view('catalog.products.show', ['product' => $product]);
    }

    public function edit(Product $product): View
    {
        return view('catalog.products.edit', ['product' => $product]);
    }

    public function update(UpdateProductRequest $request, Product $product, UpdateProduct $updateProduct): RedirectResponse
    {
        $updateProduct($product, $request->validated());

        return to_route('products.show', $product)
            ->with('status', 'Produto atualizado com sucesso.');
    }

    public function destroy(Product $product, TrashProduct $trashProduct): RedirectResponse
    {
        $trashProduct($product);

        return to_route('products.index')
            ->with('status', 'Produto movido para a lixeira.');
    }
}
