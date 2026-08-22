<?php

declare(strict_types=1);

use App\Features\Catalog\Http\Controllers\ProductController;
use App\Features\Catalog\Http\Controllers\ProductStatusController;
use App\Features\Catalog\Http\Controllers\ProductTrashController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/products');

Route::resource('products', ProductController::class);
Route::patch('products/{product}/activate', [ProductStatusController::class, 'activate'])
    ->name('products.activate');
Route::patch('products/{product}/deactivate', [ProductStatusController::class, 'deactivate'])
    ->name('products.deactivate');
Route::get('products-trash', [ProductTrashController::class, 'index'])
    ->name('products.trash.index');
Route::patch('products-trash/{product}/restore', [ProductTrashController::class, 'restore'])
    ->withTrashed()
    ->name('products.trash.restore');
