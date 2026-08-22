<?php

declare(strict_types=1);

use App\Features\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Number;

uses(RefreshDatabase::class);

it('lists only normal products on the index', function (): void {
    $product = Product::factory()->create(['sku' => 'VISIBLE-1']);
    Product::factory()->trashed()->create(['sku' => 'TRASHED-1']);

    $this->get(route('products.index'))
        ->assertOk()
        ->assertSee($product->sku)
        ->assertSee($product->name)
        ->assertSee(Number::currency($product->price_cents / 100, in: 'BRL', locale: 'pt_BR'))
        ->assertSee($product->is_active ? 'Ativo' : 'Inativo')
        ->assertDontSee('TRASHED-1');
});

it('renders escaped product descriptions on the show screen', function (): void {
    $product = Product::factory()->create([
        'description' => '<script>alert("xss")</script>',
    ]);

    $this->get(route('products.show', $product))
        ->assertOk()
        ->assertSee(e($product->description), false)
        ->assertDontSee($product->description, false)
        ->assertDontSee('{!!', false);
});

it('renders only the allowed product fields on create and edit screens', function (): void {
    $product = Product::factory()->create();

    foreach ([route('products.create'), route('products.edit', $product)] as $url) {
        $this->get($url)
            ->assertOk()
            ->assertSee('name="sku"', false)
            ->assertSee('name="name"', false)
            ->assertSee('name="description"', false)
            ->assertSee('name="price_cents"', false)
            ->assertDontSee('name="quantity"', false)
            ->assertDontSee('name="is_active"', false);
    }
});

it('serves the product show, create, and edit screens', function (): void {
    $product = Product::factory()->create();

    $this->get(route('products.show', $product))->assertOk();
    $this->get(route('products.create'))->assertOk();
    $this->get(route('products.edit', $product))->assertOk();
});

it('stores an inactive product and redirects to its show screen', function (): void {
    $response = $this->post(route('products.store'), crudProductPayload());

    $product = Product::query()->where('sku', 'WEB-STORE-1')->firstOrFail();

    $response->assertRedirect(route('products.show', $product))
        ->assertSessionHas('status');

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'sku' => 'WEB-STORE-1',
        'is_active' => false,
        'deleted_at' => null,
    ]);
});

it('redirects invalid store input back with errors and old input', function (): void {
    $this->from(route('products.create'))
        ->post(route('products.store'), crudProductPayload(['name' => '']))
        ->assertRedirect(route('products.create'))
        ->assertSessionHasErrors('name')
        ->assertSessionHasInput('sku', 'WEB-STORE-1');
});

it('updates an active product without deactivating it', function (): void {
    $product = Product::factory()->active()->create(['sku' => 'WEB-UPDATE-1']);

    $response = $this->put(route('products.update', $product), crudProductPayload([
        'sku' => 'WEB-UPDATED-1',
        'name' => 'Updated product',
    ]));

    $response->assertRedirect(route('products.show', $product))
        ->assertSessionHas('status');

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'sku' => 'WEB-UPDATED-1',
        'name' => 'Updated product',
        'is_active' => true,
    ]);
});

it('redirects invalid update input back with errors and old input', function (): void {
    $product = Product::factory()->create();

    $this->from(route('products.edit', $product))
        ->put(route('products.update', $product), crudProductPayload(['price_cents' => 0]))
        ->assertRedirect(route('products.edit', $product))
        ->assertSessionHasErrors('price_cents')
        ->assertSessionHasInput('sku', 'WEB-STORE-1');
});

it('runs explicit product status transitions', function (): void {
    $product = Product::factory()->create();

    $this->patch(route('products.activate', $product))
        ->assertRedirect(route('products.show', $product))
        ->assertSessionHas('status');
    $this->assertDatabaseHas('products', ['id' => $product->id, 'is_active' => true]);

    $this->patch(route('products.deactivate', $product))
        ->assertRedirect(route('products.show', $product))
        ->assertSessionHas('status');
    $this->assertDatabaseHas('products', ['id' => $product->id, 'is_active' => false]);
});

it('soft deletes an inactive product and redirects to the index', function (): void {
    $product = Product::factory()->active()->create();

    $this->delete(route('products.destroy', $product))
        ->assertRedirect(route('products.index'))
        ->assertSessionHas('status');

    $this->assertSoftDeleted('products', [
        'id' => $product->id,
        'is_active' => false,
    ]);
});

it('lists only trashed products in the trash screen', function (): void {
    Product::factory()->create(['sku' => 'NORMAL-1']);
    $trashed = Product::factory()->trashed()->create(['sku' => 'TRASHED-1']);

    $this->get(route('products.trash.index'))
        ->assertOk()
        ->assertSee('TRASHED-1')
        ->assertSee($trashed->deleted_at->format('Y-m-d'))
        ->assertSee(route('products.trash.restore', $trashed), false)
        ->assertSee('name="_method" value="PATCH"', false)
        ->assertDontSee(route('products.destroy', $trashed), false)
        ->assertDontSee('NORMAL-1');
});

it('renders pagination when the product index has more than one page', function (): void {
    Product::factory()->count(16)->create();

    $this->get(route('products.index'))
        ->assertOk()
        ->assertSee('page=2', false);
});

it('restores a trashed product as inactive', function (): void {
    $product = Product::factory()->trashed()->create(['is_active' => false]);

    $this->patch(route('products.trash.restore', $product))
        ->assertRedirect(route('products.edit', $product))
        ->assertSessionHas('status');

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'is_active' => false,
        'deleted_at' => null,
    ]);
});

it('returns not found when restoring an active product', function (): void {
    $product = Product::query()->create([
        'sku' => 'HTTP-ACTIVE-RESTORE-1',
        'name' => 'HTTP active product',
        'price_cents' => 100,
    ]);
    $product->forceFill(['is_active' => true])->save();

    $this->patch(route('products.trash.restore', $product))
        ->assertNotFound();

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'is_active' => true,
        'deleted_at' => null,
    ]);
});

it('does not bind trashed products on normal routes but does on restore', function (): void {
    $product = Product::factory()->trashed()->create();

    $this->get(route('products.show', $product))->assertNotFound();
    $this->get(route('products.edit', $product))->assertNotFound();
    $this->patch(route('products.trash.restore', $product))->assertRedirect();
});

function crudProductPayload(array $overrides = []): array
{
    return array_merge([
        'sku' => 'WEB-STORE-1',
        'name' => 'Web product',
        'description' => 'Web description',
        'price_cents' => 1990,
    ], $overrides);
}
