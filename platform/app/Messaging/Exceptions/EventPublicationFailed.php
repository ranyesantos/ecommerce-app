<?php

declare(strict_types=1);

namespace App\Messaging\Exceptions;

use RuntimeException;
use Throwable;

final class EventPublicationFailed extends RuntimeException
{
    public function __construct(
        public readonly string $exchange,
        public readonly string $routingKey,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function fromThrowable(
        string $exchange,
        string $routingKey,
        Throwable $previous,
    ): self {
        return new self(
            exchange: $exchange,
            routingKey: $routingKey,
            message: sprintf(
                'Failed to publish event to %s with routing key %s.',
                $exchange,
                $routingKey,
            ),
            previous: $previous,
        );
    }

    public static function nacked(string $exchange, string $routingKey): self
    {
        return new self(
            exchange: $exchange,
            routingKey: $routingKey,
            message: sprintf(
                'Broker negatively acknowledged event on %s with routing key %s.',
                $exchange,
                $routingKey,
            ),
        );
    }
}
