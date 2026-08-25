<?php

declare(strict_types=1);

use App\Features\Catalog\Actions\CreateProduct;
use App\Features\Catalog\Events\ProductCreated;
use App\Features\Catalog\Models\Product;
use App\Messaging\Contracts\EventPublisher;
use App\Messaging\Contracts\IntegrationEvent;
use App\Messaging\Exceptions\EventPublicationFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Support\InMemoryEventPublisher;
use Throwable;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->publisher = new InMemoryEventPublisher;
    $this->app->instance(EventPublisher::class, $this->publisher);
});

it('publishes a product created event after creating a product', function (): void {
    $response = $this->post(route('products.store'), [
        'sku' => 'PUBLISH-1',
        'name' => 'Published product',
        'description' => 'Product description',
        'price_cents' => 1990,
    ]);

    $product = Product::query()->where('sku', 'PUBLISH-1')->firstOrFail();

    $response->assertRedirect(route('products.show', $product));
    expect($this->publisher->published)->toHaveCount(1);
    expect($this->publisher->published[0])->toBeInstanceOf(ProductCreated::class);

    $event = $this->publisher->published[0];

    expect($event->eventType())->toBe('catalog.product.created')
        ->and($event->routingKey())->toBe('catalog.product.created')
        ->and($event->envelope()['payload'])->toBe([
            'product_id' => (string) $product->getKey(),
            'sku' => $product->sku,
            'name' => $product->name,
            'description' => $product->description,
            'price_cents' => $product->price_cents,
            'is_active' => $product->is_active,
        ]);
});

it('keeps the product and http result when publication fails', function (): void {
    $this->publisher->failure = new EventPublicationFailed(
        exchange: 'ecommerce.events',
        routingKey: 'catalog.product.created',
        message: 'Broker unavailable.',
    );
    Log::spy();

    $response = $this->post(route('products.store'), [
        'sku' => 'BROKER-DOWN-1',
        'name' => 'Broker down product',
        'description' => 'Product description',
        'price_cents' => 2990,
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('products', ['sku' => 'BROKER-DOWN-1']);
    Log::shouldHaveReceived('error')->once()->with(
        'Failed to publish catalog.product.created.',
        Mockery::on(fn (array $context): bool => $context['product_id'] !== ''
            && $context['event_id'] !== ''
            && $context['correlation_id'] !== ''
            && $context['exchange'] === 'ecommerce.events'
            && $context['routing_key'] === 'catalog.product.created'
            && $context['exception'] instanceof Throwable
        ),
    );
});

it('publishes only after the product transaction commits', function (): void {
    $transactionLevel = DB::transactionLevel();

    $this->publisher->beforePublish = function (IntegrationEvent $event) use ($transactionLevel): void {
        expect(DB::transactionLevel())->toBe($transactionLevel);

        $productId = $event->envelope()['payload']['product_id'];

        expect(Product::query()->find($productId))->not->toBeNull();
    };

    $product = resolve(CreateProduct::class)->handle([
        'sku' => 'ORDER-1',
        'name' => 'Order product',
        'description' => 'Product description',
        'price_cents' => 3990,
    ]);

    expect($product->sku)->toBe('ORDER-1')
        ->and($this->publisher->published)->toHaveCount(1);
});
