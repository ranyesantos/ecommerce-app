<?php

declare(strict_types=1);

namespace App\Providers;

use App\Messaging\Contracts\AmqpConnectionFactory;
use App\Messaging\Contracts\EventPublisher;
use App\Messaging\PhpAmqpLibConnectionFactory;
use App\Messaging\RabbitMqEventPublisher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AmqpConnectionFactory::class, fn (): AmqpConnectionFactory => new PhpAmqpLibConnectionFactory(
            host: config()->string('rabbitmq.host'),
            port: config()->integer('rabbitmq.port'),
            user: config()->string('rabbitmq.user'),
            password: config()->string('rabbitmq.password'),
            vhost: config()->string('rabbitmq.vhost'),
            connectionTimeout: config()->float('rabbitmq.connection_timeout'),
            readTimeout: config()->float('rabbitmq.read_timeout'),
            writeTimeout: config()->float('rabbitmq.write_timeout'),
        ));

        $this->app->singleton(EventPublisher::class, fn (Application $application): EventPublisher => new RabbitMqEventPublisher(
            connections: $application->make(AmqpConnectionFactory::class),
            exchange: config()->string('rabbitmq.exchange'),
            confirmTimeout: config()->float('rabbitmq.publisher_confirm_timeout'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
