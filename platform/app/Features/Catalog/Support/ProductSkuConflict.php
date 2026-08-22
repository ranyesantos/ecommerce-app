<?php

declare(strict_types=1);

namespace App\Features\Catalog\Support;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

final class ProductSkuConflict
{
    public static function rethrow(QueryException $exception): never
    {
        if (($exception->errorInfo[0] ?? null) === '23505'
            && str_contains($exception->getMessage(), 'products_sku_unique')) {
            throw ValidationException::withMessages([
                'sku' => 'Este SKU já está em uso.',
            ]);
        }

        throw $exception;
    }
}
