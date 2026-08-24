<?php

declare(strict_types=1);

namespace App\Features\Catalog\Actions;

use App\Features\Catalog\Events\ProductCreated;
use App\Features\Catalog\Models\Product;
use App\Features\Catalog\Support\ProductSkuConflict;
use App\Messaging\Contracts\EventPublisher;
use App\Messaging\Exceptions\EventPublicationFailed;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class CreateProduct
{
    public function __construct(private readonly EventPublisher $events) {}

    /** @param array<string, mixed> $attributes */
    public function handle(array $attributes): Product
    {
        try {
            $product = DB::transaction(function () use ($attributes): Product {
                $product = new Product(Arr::only($attributes, ['sku', 'name', 'description', 'price_cents']));
                $product->forceFill(['is_active' => false])->save();

                return $product;
            });
        } catch (QueryException $queryException) {
            ProductSkuConflict::rethrow($queryException);
        }

        $event = ProductCreated::fromProduct($product);

        try {
            $this->events->publish($event);
        } catch (EventPublicationFailed $failure) {
            Log::error('Failed to publish catalog.product.created.', [
                'event_id' => $event->eventId(),
                'product_id' => (string) $product->getKey(),
                'correlation_id' => $event->correlationId(),
                'exchange' => $failure->exchange,
                'routing_key' => $failure->routingKey,
                'exception' => $failure,
            ]);
        }

        return $product;
    }
}
