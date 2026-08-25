<?php

declare(strict_types=1);

namespace App\Messaging\Contracts;

use DateTimeImmutable;

interface IntegrationEvent
{
    public function routingKey(): string;

    public function eventId(): string;

    public function eventType(): string;

    public function eventVersion(): int;

    public function occurredAt(): DateTimeImmutable;

    public function correlationId(): string;

    /** @return array{event_id: string, event_type: string, event_version: int, occurred_at: string, correlation_id: string, payload: array<string, mixed>} */
    public function envelope(): array;
}
