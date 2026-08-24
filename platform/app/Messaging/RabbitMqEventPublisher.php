<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Messaging\Contracts\AmqpConnectionFactory;
use App\Messaging\Contracts\EventPublisher;
use App\Messaging\Contracts\IntegrationEvent;
use App\Messaging\Exceptions\EventPublicationFailed;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

final class RabbitMqEventPublisher implements EventPublisher
{
    public function __construct(
        private readonly AmqpConnectionFactory $connections,
        private readonly string $exchange,
        private readonly float $confirmTimeout,
    ) {}

    public function publish(IntegrationEvent $event): void
    {
        $connection = null;
        $channel = null;

        try {
            $connection = $this->connections->connect();
            $channel = $connection->channel();
            $channel->set_nack_handler(function () use ($event): never {
                throw EventPublicationFailed::nacked($this->exchange, $event->routingKey());
            });
            $channel->confirm_select();
            $message = new AMQPMessage(
                json_encode($event->envelope(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $this->messageProperties($event),
            );
            $channel->basic_publish($message, $this->exchange, $event->routingKey(), false);
            $channel->wait_for_pending_acks($this->confirmTimeout);
        } catch (EventPublicationFailed $failure) {
            throw $failure;
        } catch (Throwable $throwable) {
            throw EventPublicationFailed::fromThrowable(
                $this->exchange,
                $event->routingKey(),
                $throwable,
            );
        } finally {
            try {
                $channel?->close();
            } catch (Throwable) {
            }

            try {
                $connection?->close();
            } catch (Throwable) {
            }
        }
    }

    /**
     * @return array{
     *     content_type: string,
     *     content_encoding: string,
     *     delivery_mode: int,
     *     message_id: string,
     *     correlation_id: string,
     *     type: string,
     *     timestamp: int,
     *     application_headers: AMQPTable,
     * }
     */
    private function messageProperties(IntegrationEvent $event): array
    {
        return [
            'content_type' => 'application/json',
            'content_encoding' => 'utf-8',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'message_id' => $event->eventId(),
            'correlation_id' => $event->correlationId(),
            'type' => $event->eventType(),
            'timestamp' => $event->occurredAt()->getTimestamp(),
            'application_headers' => new AMQPTable([
                'event_version' => $event->eventVersion(),
            ]),
        ];
    }
}
