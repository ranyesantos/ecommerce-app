<?php

declare(strict_types=1);

namespace App\Features\Catalog\Events;

use App\Features\Catalog\Models\Product;
use App\Messaging\Contracts\IntegrationEvent;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;

final class ProductCreated implements IntegrationEvent
{
    private const EVENT_TYPE = 'catalog.product.created';

    private const EVENT_VERSION = 1;

    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        private readonly string $id,
        private readonly DateTimeImmutable $timestamp,
        private readonly string $correlation,
        private readonly array $payload,
    ) {}

    public static function fromProduct(
        Product $product,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
        ?string $correlationId = null,
    ): self {
        return new self(
            id: $eventId ?? Str::uuid7()->toString(),
            timestamp: $occurredAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC')),
            correlation: $correlationId ?? Str::uuid7()->toString(),
            payload: [
                'product_id' => (string) $product->getKey(),
                'sku' => $product->sku,
                'name' => $product->name,
                'description' => $product->description,
                'price_cents' => $product->price_cents,
                'is_active' => $product->is_active,
            ],
        );
    }

    public function routingKey(): string
    {
        return self::EVENT_TYPE;
    }

    public function eventId(): string
    {
        return $this->id;
    }

    public function eventType(): string
    {
        return self::EVENT_TYPE;
    }

    public function eventVersion(): int
    {
        return self::EVENT_VERSION;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function correlationId(): string
    {
        return $this->correlation;
    }

    /** @return array{event_id: string, event_type: string, event_version: int, occurred_at: string, correlation_id: string, payload: array<string, mixed>} */
    public function envelope(): array
    {
        return [
            'event_id' => $this->eventId(),
            'event_type' => $this->eventType(),
            'event_version' => $this->eventVersion(),
            'occurred_at' => $this->occurredAt()
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.v\Z'),
            'correlation_id' => $this->correlationId(),
            'payload' => $this->payload,
        ];
    }
}
