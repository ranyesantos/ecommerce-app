<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Messaging\Contracts\EventPublisher;
use App\Messaging\Contracts\IntegrationEvent;
use App\Messaging\Exceptions\EventPublicationFailed;
use Closure;

final class InMemoryEventPublisher implements EventPublisher
{
    /** @var list<IntegrationEvent> */
    public array $published = [];

    public ?EventPublicationFailed $failure = null;

    /** @var (Closure(IntegrationEvent): void)|null */
    public ?Closure $beforePublish = null;

    public function publish(IntegrationEvent $event): void
    {
        if ($this->failure instanceof EventPublicationFailed) {
            throw $this->failure;
        }

        ($this->beforePublish)?->__invoke($event);
        $this->published[] = $event;
    }
}
