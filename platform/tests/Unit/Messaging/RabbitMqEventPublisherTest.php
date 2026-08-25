<?php

declare(strict_types=1);

namespace Tests\Unit\Messaging;

use Carbon\CarbonImmutable;
use App\Features\Catalog\Events\ProductCreated;
use App\Features\Catalog\Models\Product;
use App\Messaging\Contracts\AmqpConnectionFactory;
use App\Messaging\Exceptions\EventPublicationFailed;
use App\Messaging\RabbitMqEventPublisher;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RabbitMqEventPublisherTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_publishes_a_persistent_json_message_and_waits_for_confirmation(): void
    {
        $event = $this->event();
        $channel = Mockery::mock(AMQPChannel::class);
        $connection = Mockery::mock(AbstractConnection::class);
        $connections = Mockery::mock(AmqpConnectionFactory::class);

        $connections->shouldReceive('connect')->once()->ordered()->andReturn($connection);
        $connection->shouldReceive('channel')->once()->ordered()->andReturn($channel);
        $channel->shouldReceive('set_nack_handler')
            ->once()
            ->ordered()
            ->with(Mockery::type('callable'));
        $channel->shouldReceive('confirm_select')->once()->ordered();
        $channel->shouldReceive('basic_publish')
            ->once()
            ->ordered()
            ->withArgs(function (AMQPMessage $message, string $exchange, string $routingKey, bool $mandatory) use ($event): bool {
                $this->assertSame('ecommerce.events', $exchange);
                $this->assertSame('catalog.product.created', $routingKey);
                $this->assertFalse($mandatory);
                $this->assertSame(json_encode($event->envelope(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $message->getBody());
                $this->assertSame('application/json', $message->get('content_type'));
                $this->assertSame('utf-8', $message->get('content_encoding'));
                $this->assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $message->get('delivery_mode'));
                $this->assertSame($event->eventId(), $message->get('message_id'));
                $this->assertSame($event->correlationId(), $message->get('correlation_id'));
                $this->assertSame($event->eventType(), $message->get('type'));
                $this->assertSame($event->occurredAt()->getTimestamp(), $message->get('timestamp'));

                $headers = $message->get('application_headers');
                $this->assertInstanceOf(AMQPTable::class, $headers);
                $this->assertSame(['event_version' => $event->eventVersion()], $headers->getNativeData());

                return true;
            });
        $channel->shouldReceive('wait_for_pending_acks')->once()->ordered()->with(5.0);
        $channel->shouldReceive('close')->once()->ordered();
        $connection->shouldReceive('close')->once()->ordered();

        $publisher = new RabbitMqEventPublisher(
            connections: $connections,
            exchange: 'ecommerce.events',
            confirmTimeout: 5.0,
        );

        $publisher->publish($event);

        $this->assertTrue(condition: true);
    }

    public function test_it_wraps_confirmation_failures_with_publication_context(): void
    {
        $event = $this->event();
        $channel = Mockery::mock(AMQPChannel::class);
        $connection = Mockery::mock(AbstractConnection::class);
        $connections = Mockery::mock(AmqpConnectionFactory::class);
        $failure = new RuntimeException('broker did not confirm the message');

        $connections->shouldReceive('connect')->once()->andReturn($connection);
        $connection->shouldReceive('channel')->once()->andReturn($channel);
        $channel->shouldReceive('set_nack_handler')->once()->with(Mockery::type('callable'));
        $channel->shouldReceive('confirm_select')->once();
        $channel->shouldReceive('basic_publish')->once();
        $channel->shouldReceive('wait_for_pending_acks')->once()->with(5.0)->andThrow($failure);
        $channel->shouldReceive('close')->once();
        $connection->shouldReceive('close')->once();

        $publisher = new RabbitMqEventPublisher(
            connections: $connections,
            exchange: 'ecommerce.events',
            confirmTimeout: 5.0,
        );

        $this->expectException(EventPublicationFailed::class);
        $this->expectExceptionMessage('Failed to publish event to ecommerce.events with routing key catalog.product.created.');

        try {
            $publisher->publish($event);
        } catch (EventPublicationFailed $eventPublicationFailed) {
            $this->assertSame('ecommerce.events', $eventPublicationFailed->exchange);
            $this->assertSame('catalog.product.created', $eventPublicationFailed->routingKey);
            $this->assertSame($failure, $eventPublicationFailed->getPrevious());

            throw $eventPublicationFailed;
        }
    }

    public function test_it_ignores_close_failures_after_a_confirmed_publication(): void
    {
        $event = $this->event();
        $channel = Mockery::mock(AMQPChannel::class);
        $connection = Mockery::mock(AbstractConnection::class);
        $connections = Mockery::mock(AmqpConnectionFactory::class);

        $connections->shouldReceive('connect')->once()->andReturn($connection);
        $connection->shouldReceive('channel')->once()->andReturn($channel);
        $channel->shouldReceive('set_nack_handler')->once()->with(Mockery::type('callable'));
        $channel->shouldReceive('confirm_select')->once();
        $channel->shouldReceive('basic_publish')->once();
        $channel->shouldReceive('wait_for_pending_acks')->once()->with(5.0);
        $channel->shouldReceive('close')->once()->andThrow(new RuntimeException('channel close failed'));
        $connection->shouldReceive('close')->once()->andThrow(new RuntimeException('connection close failed'));

        $publisher = new RabbitMqEventPublisher(
            connections: $connections,
            exchange: 'ecommerce.events',
            confirmTimeout: 5.0,
        );

        $publisher->publish($event);

        $this->assertTrue(condition: true);
    }

    private function event(): ProductCreated
    {
        $product = new Product([
            'sku' => 'PUB-001',
            'name' => 'Publisher test product',
            'description' => 'Publisher test description',
            'price_cents' => 1990,
        ]);
        $product->forceFill(['id' => '0198f000-0000-7000-8000-000000000010', 'is_active' => false]);

        return ProductCreated::fromProduct(
            product: $product,
            eventId: '0198f000-0000-7000-8000-000000000001',
            occurredAt: CarbonImmutable::parse('2026-08-24T17:30:00.000Z'),
            correlationId: '0198f000-0000-7000-8000-000000000002',
        );
    }
}
