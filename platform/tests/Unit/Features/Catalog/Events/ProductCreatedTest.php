<?php

declare(strict_types=1);

namespace Tests\Unit\Features\Catalog\Events;

use App\Features\Catalog\Events\ProductCreated;
use App\Features\Catalog\Models\Product;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ProductCreatedTest extends TestCase
{
    public function test_it_builds_a_versioned_product_created_envelope(): void
    {
        $product = new Product([
            'sku' => 'TEC-001',
            'name' => 'Teclado mecânico',
            'description' => 'Teclado ABNT2',
            'price_cents' => 29990,
        ]);
        $product->forceFill([
            'id' => '0198f000-0000-7000-8000-000000000010',
            'is_active' => false,
        ]);

        $event = ProductCreated::fromProduct(
            product: $product,
            eventId: '0198f000-0000-7000-8000-000000000001',
            occurredAt: new DateTimeImmutable('2026-08-24T17:30:00.000Z'),
            correlationId: '0198f000-0000-7000-8000-000000000002',
        );

        self::assertSame('catalog.product.created', $event->routingKey());
        self::assertSame([
            'event_id' => '0198f000-0000-7000-8000-000000000001',
            'event_type' => 'catalog.product.created',
            'event_version' => 1,
            'occurred_at' => '2026-08-24T17:30:00.000Z',
            'correlation_id' => '0198f000-0000-7000-8000-000000000002',
            'payload' => [
                'product_id' => (string) $product->getKey(),
                'sku' => 'TEC-001',
                'name' => 'Teclado mecânico',
                'description' => 'Teclado ABNT2',
                'price_cents' => 29990,
                'is_active' => false,
            ],
        ], $event->envelope());

        self::assertArrayNotHasKey('quantity', $event->envelope()['payload']);
        self::assertArrayNotHasKey('stock', $event->envelope()['payload']);
        self::assertArrayNotHasKey('deleted_at', $event->envelope()['payload']);
    }
}
