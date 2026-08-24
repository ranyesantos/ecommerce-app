<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Messaging\Contracts\AmqpConnectionFactory;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory as PhpAmqpConnectionFactory;

final class PhpAmqpLibConnectionFactory implements AmqpConnectionFactory
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        private readonly string $password,
        private readonly string $vhost,
        private readonly float $connectionTimeout,
        private readonly float $readTimeout,
        private readonly float $writeTimeout,
    ) {}

    public function connect(): AbstractConnection
    {
        $config = new AMQPConnectionConfig;
        $config->setHost($this->host);
        $config->setPort($this->port);
        $config->setUser($this->user);
        $config->setPassword($this->password);
        $config->setVhost($this->vhost);
        $config->setIoType(AMQPConnectionConfig::IO_TYPE_STREAM);
        $config->setConnectionTimeout($this->connectionTimeout);
        $config->setReadTimeout($this->readTimeout);
        $config->setWriteTimeout($this->writeTimeout);

        return PhpAmqpConnectionFactory::create($config);
    }
}
