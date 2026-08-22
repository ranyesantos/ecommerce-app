<?php

declare(strict_types=1);

namespace App\Features\Catalog\Http\Requests;

use App\Features\Catalog\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $sku = $this->input('sku', '');
        $name = $this->input('name', '');
        $description = $this->input('description', '');

        if (is_string($sku)) {
            $sku = Str::upper(trim($sku));
        }

        if (is_string($name)) {
            $name = trim($name);
        }

        if (is_string($description)) {
            $description = trim($description);
            $description = $description === '' ? null : $description;
        }

        $this->merge([
            'sku' => $sku,
            'name' => $name,
            'description' => $description,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('products', 'sku')->ignore($this->route('product'))],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', new PlainText],
            'price_cents' => ['required', 'integer', 'min:1'],
            'is_active' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'sku.regex' => 'O campo sku deve conter apenas letras maiúsculas, números, hífen e sublinhado.',
            'sku.unique' => 'O sku informado já está cadastrado.',
            'price_cents.integer' => 'O campo price_cents deve ser um número inteiro.',
            'price_cents.min' => 'O campo price_cents deve ser no mínimo 1.',
            'is_active.prohibited' => 'O campo is_active não pode ser informado.',
        ];
    }
}
