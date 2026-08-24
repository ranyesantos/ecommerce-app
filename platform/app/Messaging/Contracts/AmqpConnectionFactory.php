<?php

declare(strict_types=1);

namespace App\Messaging\Contracts;

use PhpAmqpLib\Connection\AbstractConnection;

interface AmqpConnectionFactory
{
    public function connect(): AbstractConnection;
}
