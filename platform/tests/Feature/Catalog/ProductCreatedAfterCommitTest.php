<?php

declare(strict_types=1);

use App\Features\Catalog\Actions\CreateProduct;
use App\Features\Catalog\Models\Product;
use App\Messaging\Contracts\EventPublisher;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\InMemoryEventPublisher;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->publisher = new InMemoryEventPublisher;
    $this->app->instance(EventPublisher::class, $this->publisher);
});

it('does not publish when an outer transaction rolls back', function (): void {
    DB::beginTransaction();

    try {
        resolve(CreateProduct::class)->handle([
            'sku' => 'ROLLBACK-1',
            'name' => 'Rollback product',
            'description' => 'Product description',
            'price_cents' => 1990,
        ]);

        expect($this->publisher->published)->toHaveCount(0);
    } finally {
        DB::rollBack();
    }

    expect($this->publisher->published)->toHaveCount(0)
        ->and(Product::query()->where('sku', 'ROLLBACK-1')->exists())->toBeFalse();
});

it('publishes exactly once after an outer transaction commits', function (): void {
    DB::transaction(function (): void {
        resolve(CreateProduct::class)->handle([
            'sku' => 'COMMIT-1',
            'name' => 'Committed product',
            'description' => 'Product description',
            'price_cents' => 2990,
        ]);

        expect($this->publisher->published)->toHaveCount(0);
    });

    expect($this->publisher->published)->toHaveCount(1)
        ->and(Product::query()->where('sku', 'COMMIT-1')->exists())->toBeTrue();
});
