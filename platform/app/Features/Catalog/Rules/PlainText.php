<?php

declare(strict_types=1);

namespace App\Features\Catalog\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PlainText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && strip_tags($value) !== $value) {
            $fail('O campo :attribute deve conter apenas texto simples.');
        }
    }
}
