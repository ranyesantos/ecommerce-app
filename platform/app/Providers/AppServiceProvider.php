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
        $this->app->singleton(AmqpConnectionFactory::class, function (): AmqpConnectionFactory {
            return new PhpAmqpLibConnectionFactory(
                host: (string) config('rabbitmq.host'),
                port: (int) config('rabbitmq.port'),
                user: (string) config('rabbitmq.user'),
                password: (string) config('rabbitmq.password'),
                vhost: (string) config('rabbitmq.vhost'),
                connectionTimeout: (float) config('rabbitmq.connection_timeout'),
                readTimeout: (float) config('rabbitmq.read_timeout'),
                writeTimeout: (float) config('rabbitmq.write_timeout'),
            );
        });

        $this->app->singleton(EventPublisher::class, function (Application $application): EventPublisher {
            return new RabbitMqEventPublisher(
                connections: $application->make(AmqpConnectionFactory::class),
                exchange: (string) config('rabbitmq.exchange'),
                confirmTimeout: (float) config('rabbitmq.publisher_confirm_timeout'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
