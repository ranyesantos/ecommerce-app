<?php

declare(strict_types=1);

return [
    'host' => env('RABBITMQ_HOST', '127.0.0.1'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'vhost' => env('RABBITMQ_VHOST', 'ecommerce'),
    'user' => env('RABBITMQ_USER', 'catalog'),
    'password' => env('RABBITMQ_PASSWORD'),
    'connection_timeout' => (float) env('RABBITMQ_CONNECTION_TIMEOUT', 3.0),
    'read_timeout' => (float) env('RABBITMQ_READ_TIMEOUT', 5.0),
    'write_timeout' => (float) env('RABBITMQ_WRITE_TIMEOUT', 5.0),
    'publisher_confirm_timeout' => (float) env('RABBITMQ_PUBLISHER_CONFIRM_TIMEOUT', 5.0),
    'exchange' => 'ecommerce.events',
];
