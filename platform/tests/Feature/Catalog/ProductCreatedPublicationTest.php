<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

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
use Tests\TestCase;
use Throwable;

final class ProductCreatedPublicationTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryEventPublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publisher = new InMemoryEventPublisher;
        $this->app->instance(EventPublisher::class, $this->publisher);
    }

    public function test_it_publishes_a_product_created_event_after_creating_a_product(): void
    {
        $response = $this->post(route('products.store'), [
            'sku' => 'PUBLISH-1',
            'name' => 'Published product',
            'description' => 'Product description',
            'price_cents' => 1990,
        ]);

        $product = Product::query()->where('sku', 'PUBLISH-1')->firstOrFail();

        $response->assertRedirect(route('products.show', $product));
        self::assertCount(1, $this->publisher->published);
        self::assertInstanceOf(ProductCreated::class, $this->publisher->published[0]);

        $event = $this->publisher->published[0];

        self::assertSame('catalog.product.created', $event->eventType());
        self::assertSame('catalog.product.created', $event->routingKey());
        self::assertSame([
            'product_id' => (string) $product->getKey(),
            'sku' => $product->sku,
            'name' => $product->name,
            'description' => $product->description,
            'price_cents' => $product->price_cents,
            'is_active' => $product->is_active,
        ], $event->envelope()['payload']);
    }

    public function test_it_keeps_the_product_and_http_result_when_publication_fails(): void
    {
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
    }

    public function test_it_publishes_only_after_the_product_transaction_commits(): void
    {
        $transactionLevel = DB::transactionLevel();

        $this->publisher->beforePublish = function (IntegrationEvent $event) use ($transactionLevel): void {
            self::assertSame($transactionLevel, DB::transactionLevel());

            $productId = $event->envelope()['payload']['product_id'];

            self::assertNotNull(Product::query()->find($productId));
        };

        $product = resolve(CreateProduct::class)->handle([
            'sku' => 'ORDER-1',
            'name' => 'Order product',
            'description' => 'Product description',
            'price_cents' => 3990,
        ]);

        self::assertSame('ORDER-1', $product->sku);
        self::assertCount(1, $this->publisher->published);
    }
}
