<?php

declare(strict_types=1);

namespace App\Messaging\Contracts;

interface EventPublisher
{
    public function publish(IntegrationEvent $event): void;
}
