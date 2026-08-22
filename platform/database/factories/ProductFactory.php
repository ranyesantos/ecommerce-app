<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Features\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku' => Str::upper(fake()->unique()->bothify('SKU-####-??')),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'price_cents' => fake()->numberBetween(1, 1_000_000),
            'is_active' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => true,
        ]);
    }

    public function trashed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
            'deleted_at' => now(),
        ]);
    }
}
