# RabbitMQ and Catalog Product Event Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Run RabbitMQ from the repository root and make the Laravel Catalog publish `catalog.product.created` after a product is committed, without creating queues, bindings, consumers, retry, DLQ, or Outbox.

**Architecture:** The root Compose project owns PostgreSQL, RabbitMQ, and an idempotent topology-bootstrap container. Laravel exposes a small `EventPublisher` port; a `RabbitMqEventPublisher` adapter serializes a versioned `ProductCreated` event, publishes it as a persistent AMQP message, and waits for a broker confirm. `CreateProduct` publishes after its database transaction and treats publication failure as logged best-effort failure.

**Tech Stack:** Docker Compose, PostgreSQL 18 Alpine, RabbitMQ 4.3.5 Management Alpine, Python 3.13 Alpine for bootstrap, PHP 8.5, Laravel 13.26.1, php-amqplib 3.7.4, PHPUnit 13.2.6, Mockery 1.6.

**Spec:** `docs/superpowers/specs/2026-08-24-rabbitmq-catalog-product-event-design.md`

## Global Constraints

Implementation amendment: the RabbitMQ setup uses POSIX Shell plus rabbitmqadmin. The one-shot service imports definitions.json, provisions the restricted catalog user, and applies permissions. Python bootstrap is not part of the implementation.

- The only current messaging resources are vhost `ecommerce` and durable topic exchange `ecommerce.events`; declare no queues or bindings.
- Publish routing key and event type `catalog.product.created`, version `1`.
- Publish only after the PostgreSQL transaction commits; keep product creation successful when publication fails.
- Use publisher confirms, persistent messages, JSON UTF-8, and `mandatory=false`.
- Keep Laravel Queue on the existing `database` connection; do not configure RabbitMQ as a Laravel Queue driver.
- Keep all RabbitMQ topology in `infra/rabbitmq/definitions.json`; application credentials and permissions are applied by bootstrap and never committed as secrets.
- Do not add a feature flag, consumer, retry, DLQ, Inbox, Outbox, replay tool, or retroactive event publication.
- Preserve the existing PostgreSQL volume as `platform_catalog_db_data`.
- New tests must be PHPUnit classes even though older project tests use Pest.
- Before modifying PHP, confirm installed package versions; after modifying PHP, run `vendor/bin/pint --dirty --format agent`.
- Do not stage or commit unrelated existing changes under `docs/ADRs` or `docs/artifacts`.

## File Map

### Repository infrastructure

- Create `compose.yaml`: root orchestration for PostgreSQL, RabbitMQ, and the one-shot bootstrap.
- Create `.gitignore`: ignore the root `.env`.
- Create `.env.example`: non-secret local orchestration variables.
- Create `infra/postgres/init-test-database.sql`: relocated PostgreSQL initialization.
- Create `infra/rabbitmq/definitions.json`: versioned vhost and exchange topology.
- Create `infra/rabbitmq/provision-identity.sh`: idempotent rabbitmqadmin calls for definition import, service identity, and permissions.
- Delete `platform/compose.yaml`: replaced by root Compose.
- Delete `platform/infra/docker/postgres/init-test-database.sql`: relocated to root infrastructure.

### Laravel messaging

- Modify `platform/composer.json` and `platform/composer.lock`: add `php-amqplib/php-amqplib:^3.7`.
- Create `platform/config/rabbitmq.php`: connection, exchange, and confirm timeout configuration.
- Modify `platform/.env.example`: document Laravel-side RabbitMQ variables.
- Create `platform/app/Messaging/Contracts/IntegrationEvent.php`: transport-neutral event contract.
- Create `platform/app/Messaging/Contracts/EventPublisher.php`: publishing port.
- Create `platform/app/Messaging/Contracts/AmqpConnectionFactory.php`: testable connection boundary.
- Create `platform/app/Messaging/Exceptions/EventPublicationFailed.php`: scoped failure containing exchange and routing key.
- Create `platform/app/Messaging/PhpAmqpLibConnectionFactory.php`: create configured php-amqplib connections.
- Create `platform/app/Messaging/RabbitMqEventPublisher.php`: serialize, publish, confirm, and close resources.
- Modify `platform/app/Providers/AppServiceProvider.php`: bind messaging interfaces.

### Catalog integration and tests

- Create `platform/app/Features/Catalog/Events/ProductCreated.php`: event v1 and product snapshot.
- Modify `platform/app/Features/Catalog/Actions/CreateProduct.php`: explicit transaction followed by best-effort publish.
- Create `platform/tests/Unit/Features/Catalog/Events/ProductCreatedTest.php`: exact envelope contract.
- Create `platform/tests/Unit/Messaging/RabbitMqEventPublisherTest.php`: AMQP properties, routing, confirm, and failure behavior.
- Create `platform/tests/Support/InMemoryEventPublisher.php`: reusable feature-test publisher fake.
- Create `platform/tests/Feature/Catalog/ProductCreatedPublicationTest.php`: HTTP creation, publication order, and failure logging.

---

### Task 1: Move Shared Infrastructure to the Repository Root

**Files:**
- Create: `.gitignore`
- Create: `.env.example`
- Create: `compose.yaml`
- Create: `infra/postgres/init-test-database.sql`
- Create: `infra/rabbitmq/definitions.json`
- Create: `infra/rabbitmq/provision-identity.sh`
- Modify: `platform/.env.example`
- Delete: `platform/compose.yaml`
- Delete: `platform/infra/docker/postgres/init-test-database.sql`

**Interfaces:**
- Consumes: existing PostgreSQL service configuration and volume `platform_catalog_db_data`.
- Produces: healthy `rabbitmq` service on `5672`/`15672`, completed `rabbitmq_identity_setup`, vhost `ecommerce`, exchange `ecommerce.events`, and restricted user `catalog`.

- [ ] **Step 1: Verify the root Compose does not exist yet**

Run from the repository root:

```powershell
Test-Path -LiteralPath compose.yaml
```

Expected: `False` before this task. Also run `docker compose version`; if Docker is unavailable, install/start Docker Desktop before continuing with Task 1 verification.

- [ ] **Step 2: Relocate PostgreSQL orchestration without changing its data volume**

Create `infra/postgres/init-test-database.sql` with the existing statement:

```sql
CREATE DATABASE catalog_db_testing;
```

Remove the old copy and replace `platform/compose.yaml` with root `compose.yaml`. The PostgreSQL portion must use:

```yaml
name: inventory-lab

services:
  catalog_db:
    image: postgres:18-alpine
    environment:
      POSTGRES_DB: ${POSTGRES_DB}
      POSTGRES_USER: ${POSTGRES_USER}
      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD}
    ports:
      - '5432:5432'
    healthcheck:
      test: ['CMD-SHELL', 'pg_isready -U "$${POSTGRES_USER}" -d "$${POSTGRES_DB}"']
      interval: 5s
      timeout: 5s
      retries: 10
    volumes:
      - catalog_db_data:/var/lib/postgresql/data
      - ./infra/postgres/init-test-database.sql:/docker-entrypoint-initdb.d/init-test-database.sql:ro

volumes:
  catalog_db_data:
    name: platform_catalog_db_data
```

- [ ] **Step 3: Declare the minimal RabbitMQ topology**

Create `infra/rabbitmq/definitions.json` exactly with no queues or bindings:

```json
{
  "vhosts": [
    { "name": "ecommerce" }
  ],
  "exchanges": [
    {
      "name": "ecommerce.events",
      "vhost": "ecommerce",
      "type": "topic",
      "durable": true,
      "auto_delete": false,
      "internal": false,
      "arguments": {}
    }
  ],
  "queues": [],
  "bindings": []
}
```

- [ ] **Step 4: Implement the idempotent rabbitmqadmin Shell setup**

Create `infra/rabbitmq/bootstrap.py`. Use Python standard-library HTTP calls so passwords are JSON-encoded safely rather than interpolated into shell JSON:

```python
import base64
import json
import os
import sys
import urllib.error
import urllib.request

API_URL = "http://rabbitmq:15672/api"
ADMIN_USER = os.environ["RABBITMQ_ADMIN_USER"]
ADMIN_PASSWORD = os.environ["RABBITMQ_ADMIN_PASSWORD"]
CATALOG_USER = os.environ["RABBITMQ_CATALOG_USER"]
CATALOG_PASSWORD = os.environ["RABBITMQ_CATALOG_PASSWORD"]


def request(method: str, path: str, payload: object) -> None:
    credentials = base64.b64encode(
        f"{ADMIN_USER}:{ADMIN_PASSWORD}".encode("utf-8")
    ).decode("ascii")
    body = json.dumps(payload).encode("utf-8")
    http_request = urllib.request.Request(
        f"{API_URL}{path}",
        data=body,
        method=method,
        headers={
            "Authorization": f"Basic {credentials}",
            "Content-Type": "application/json",
        },
    )
    with urllib.request.urlopen(http_request, timeout=10) as response:
        if response.status < 200 or response.status >= 300:
            raise RuntimeError(f"{method} {path} returned {response.status}")


try:
    request("PUT", f"/users/{CATALOG_USER}", {
        "password": CATALOG_PASSWORD,
        "tags": [],
    })
    with open("/config/definitions.json", encoding="utf-8") as definitions_file:
        request("POST", "/definitions", json.load(definitions_file))
    request("PUT", f"/permissions/ecommerce/{CATALOG_USER}", {
        "configure": "^$",
        "write": "^ecommerce\\.events$",
        "read": "^$",
    })
except (KeyError, OSError, RuntimeError, urllib.error.URLError) as error:
    print(f"RabbitMQ bootstrap failed: {error}", file=sys.stderr)
    raise SystemExit(1) from error
```

- [ ] **Step 5: Add RabbitMQ and identity setup services to root Compose**

Add these services and the volume to `compose.yaml`:

```yaml
  rabbitmq:
    image: rabbitmq:4.3.5-management-alpine
    environment:
      RABBITMQ_DEFAULT_USER: ${RABBITMQ_ADMIN_USER}
      RABBITMQ_DEFAULT_PASS: ${RABBITMQ_ADMIN_PASSWORD}
    ports:
      - '5672:5672'
      - '15672:15672'
    healthcheck:
      test: ['CMD', 'rabbitmq-diagnostics', '-q', 'ping']
      interval: 5s
      timeout: 5s
      retries: 12
      start_period: 10s
    volumes:
      - rabbitmq_data:/var/lib/rabbitmq

  rabbitmq_setup:
    image: python:3.13-alpine
    depends_on:
      rabbitmq:
        condition: service_healthy
    environment:
      RABBITMQ_ADMIN_USER: ${RABBITMQ_ADMIN_USER}
      RABBITMQ_ADMIN_PASSWORD: ${RABBITMQ_ADMIN_PASSWORD}
      RABBITMQ_CATALOG_USER: ${RABBITMQ_CATALOG_USER}
      RABBITMQ_CATALOG_PASSWORD: ${RABBITMQ_CATALOG_PASSWORD}
    command: ['python', '/bootstrap/bootstrap.py']
    restart: 'no'
    volumes:
      - ./infra/rabbitmq/bootstrap.py:/bootstrap/bootstrap.py:ro
      - ./infra/rabbitmq/definitions.json:/config/definitions.json:ro

volumes:
  rabbitmq_data:
```

Merge this with the PostgreSQL content so there is only one root `services` and one root `volumes` mapping.

- [ ] **Step 6: Add non-secret environment templates and ignore local secrets**

Create root `.gitignore`:

```gitignore
.env
```

Create root `.env.example`:

```dotenv
POSTGRES_DB=catalog_db
POSTGRES_USER=catalog
POSTGRES_PASSWORD=change-me-postgres

RABBITMQ_ADMIN_USER=admin
RABBITMQ_ADMIN_PASSWORD=change-me-rabbitmq-admin
RABBITMQ_CATALOG_USER=catalog
RABBITMQ_CATALOG_PASSWORD=change-me-rabbitmq-catalog
```

Copy `.env.example` to the ignored root `.env` for local execution and replace all `change-me-*` values with local credentials. To preserve access to the existing PostgreSQL volume, copy its current database name, user, and password from the ignored `platform/.env` into the corresponding root PostgreSQL variables. Change `DB_PASSWORD` in `platform/.env.example` to the same non-secret placeholder `change-me-postgres`; actual ignored root and platform `.env` values must match. Do not stage either `.env`.

- [ ] **Step 7: Validate configuration and topology**

Run:

```powershell
python -m json.tool infra/rabbitmq/definitions.json *> $null
python -m py_compile infra/rabbitmq/bootstrap.py
docker compose config --quiet
```

Expected: all commands exit `0` and `docker compose config` shows no variable warnings.

- [ ] **Step 8: Commit infrastructure only**

```powershell
git add .gitignore .env.example compose.yaml infra/postgres/init-test-database.sql infra/rabbitmq/definitions.json infra/rabbitmq/bootstrap.py platform/.env.example platform/compose.yaml platform/infra/docker/postgres/init-test-database.sql
git commit -m "feat: add shared rabbitmq infrastructure"
```

### Task 2: Add the Versioned Product Event Contract

**Files:**
- Create: `platform/app/Messaging/Contracts/IntegrationEvent.php`
- Create: `platform/app/Messaging/Contracts/EventPublisher.php`
- Create: `platform/app/Features/Catalog/Events/ProductCreated.php`
- Create: `platform/tests/Unit/Features/Catalog/Events/ProductCreatedTest.php`

**Interfaces:**
- Consumes: persisted `App\Features\Catalog\Models\Product`.
- Produces: `IntegrationEvent::routingKey()`, `eventId()`, `correlationId()`, and `envelope()`; `EventPublisher::publish(IntegrationEvent $event): void`.

- [ ] **Step 1: Generate the PHPUnit test and write the failing contract assertion**

Run:

```powershell
php artisan make:test --phpunit --unit Features/Catalog/Events/ProductCreatedTest --no-interaction
```

The test constructs an inactive Product in memory and supplies fixed metadata:

```php
$event = ProductCreated::fromProduct(
    product: $product,
    eventId: '0198f000-0000-7000-8000-000000000001',
    occurredAt: new DateTimeImmutable('2026-08-24T17:30:00.000Z'),
    correlationId: '0198f000-0000-7000-8000-000000000002',
);

self::assertSame('catalog.product.created', $event->routingKey());
self::assertSame([
    'event_id' => '0198f000-0000-7000-8000-000000000001',
    'event_type' => 'catalog.product.created',
    'event_version' => 1,
    'occurred_at' => '2026-08-24T17:30:00.000Z',
    'correlation_id' => '0198f000-0000-7000-8000-000000000002',
    'payload' => [
        'product_id' => (string) $product->getKey(),
        'sku' => 'TEC-001',
        'name' => 'Teclado mecânico',
        'description' => 'Teclado ABNT2',
        'price_cents' => 29990,
        'is_active' => false,
    ],
], $event->envelope());
```

Also assert that no `quantity`, `stock`, or `deleted_at` key is present in `payload`.

- [ ] **Step 2: Run the test to verify it fails**

```powershell
php artisan test --compact tests/Unit/Features/Catalog/Events/ProductCreatedTest.php
```

Expected: failure because `ProductCreated` and `IntegrationEvent` do not exist.

- [ ] **Step 3: Implement the transport-neutral contracts**

`IntegrationEvent` must expose exact transport metadata without mentioning RabbitMQ:

```php
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
```

`EventPublisher` is:

```php
interface EventPublisher
{
    public function publish(IntegrationEvent $event): void;
}
```

- [ ] **Step 4: Implement `ProductCreated` v1**

Use a final immutable class. `fromProduct()` accepts optional `eventId`, `occurredAt`, and `correlationId`; defaults are new UUIDv7 values and the current UTC instant. The test product must be constructed with `sku`, `name`, `description`, and `price_cents`, then `forceFill()` the fixed `id` and `is_active=false` before creating the event. Normalize the timestamp with:

```php
$occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
```

Return only the six approved payload fields. Keep constants inside the class:

```php
private const EVENT_TYPE = 'catalog.product.created';
private const EVENT_VERSION = 1;
```

- [ ] **Step 5: Run the focused unit test and formatter**

```powershell
php artisan test --compact tests/Unit/Features/Catalog/Events/ProductCreatedTest.php
vendor/bin/pint --dirty --format agent
```

Expected: test passes and Pint exits `0`.

- [ ] **Step 6: Commit the event contract**

```powershell
git add app/Messaging/Contracts/IntegrationEvent.php app/Messaging/Contracts/EventPublisher.php app/Features/Catalog/Events/ProductCreated.php tests/Unit/Features/Catalog/Events/ProductCreatedTest.php
git commit -m "feat: define product created event contract"
```

Run these commands from `platform/` so paths match.

### Task 3: Implement the Confirmed RabbitMQ Publisher

**Files:**
- Modify: `platform/composer.json`
- Modify: `platform/composer.lock`
- Create: `platform/config/rabbitmq.php`
- Modify: `platform/.env.example`
- Create: `platform/app/Messaging/Contracts/AmqpConnectionFactory.php`
- Create: `platform/app/Messaging/Exceptions/EventPublicationFailed.php`
- Create: `platform/app/Messaging/PhpAmqpLibConnectionFactory.php`
- Create: `platform/app/Messaging/RabbitMqEventPublisher.php`
- Modify: `platform/app/Providers/AppServiceProvider.php`
- Create: `platform/tests/Unit/Messaging/RabbitMqEventPublisherTest.php`

**Interfaces:**
- Consumes: `IntegrationEvent` from Task 2 and `rabbitmq.*` config.
- Produces: container-bound `EventPublisher`, publishing to `ecommerce.events` with broker confirmation or throwing `EventPublicationFailed`.

- [ ] **Step 1: Install the approved AMQP dependency**

Run from `platform/`:

```powershell
composer require php-amqplib/php-amqplib:^3.7 --no-interaction
composer show php-amqplib/php-amqplib
```

Expected: Composer resolves `v3.7.4` and confirms the required `sockets` and `mbstring` extensions are available.

- [ ] **Step 2: Write the failing publisher unit tests**

Generate and implement a PHPUnit class:

```powershell
php artisan make:test --phpunit --unit Messaging/RabbitMqEventPublisherTest --no-interaction
```

Use Mockery mocks for `AbstractConnection` and `AMQPChannel`. The success test must assert, in order:

1. `channel()` is opened;
2. `set_nack_handler()` is registered;
3. `confirm_select()` is called;
4. `basic_publish()` receives exchange `ecommerce.events`, routing key `catalog.product.created`, `mandatory=false`;
5. the `AMQPMessage` body is the JSON envelope and properties contain `content_type=application/json`, `content_encoding=utf-8`, `delivery_mode=2`, `message_id`, `correlation_id`, `type`, and integer UTC `timestamp`;
6. `wait_for_pending_acks(5.0)` is called;
7. channel and connection are closed.

The failure test makes `wait_for_pending_acks()` throw and asserts `EventPublicationFailed` contains exchange, routing key, and the original exception as `getPrevious()`.

- [ ] **Step 3: Run the publisher test to verify it fails**

```powershell
php artisan test --compact tests/Unit/Messaging/RabbitMqEventPublisherTest.php
```

Expected: failure because the publisher classes do not exist.

- [ ] **Step 4: Add RabbitMQ configuration**

Create `config/rabbitmq.php`:

```php
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
```

Append the same keys with placeholder values to `.env.example`. Keep `QUEUE_CONNECTION=database` unchanged. Set matching values in the ignored `platform/.env`, using the same catalog password as the root `.env`.

- [ ] **Step 5: Implement the connection boundary and factory**

`AmqpConnectionFactory` declares:

```php
public function connect(): AbstractConnection;
```

`PhpAmqpLibConnectionFactory` receives these promoted constructor properties with the exact names and types:

```php
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
```

Its `connect()` builds `AMQPConnectionConfig`, sets host, port, user, password, vhost, IO type `AMQPConnectionConfig::IO_TYPE_STREAM`, connection/read/write timeouts, then returns:

```php
return AMQPConnectionFactory::create($config);
```

- [ ] **Step 6: Implement confirmed publishing and scoped failure**

`EventPublicationFailed` extends `RuntimeException` and exposes readonly `exchange` and `routingKey` properties.

`RabbitMqEventPublisher::publish()` must follow this skeleton:

```php
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
```

The adapter must not declare the exchange because `catalog` has no `configure` permission. A close failure after the broker confirm is ignored because the publication outcome is already known; connection, publish, nack, and confirm failures are always wrapped as `EventPublicationFailed`.

`messageProperties()` returns this exact mapping, using `AMQPTable` for the version header:

```php
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
```

- [ ] **Step 7: Bind the adapter in `AppServiceProvider`**

In `register()`, create singleton bindings using explicit casts from `config('rabbitmq.*')`:

```php
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
```

Do not pass the whole Laravel config repository into the messaging classes.

- [ ] **Step 8: Run focused tests and static checks**

```powershell
php artisan test --compact tests/Unit/Messaging/RabbitMqEventPublisherTest.php
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse app/Messaging app/Providers/AppServiceProvider.php --memory-limit=1G
```

Expected: all commands exit `0`.

- [ ] **Step 9: Commit the publisher adapter**

```powershell
git add composer.json composer.lock config/rabbitmq.php .env.example app/Messaging/Contracts/AmqpConnectionFactory.php app/Messaging/Exceptions/EventPublicationFailed.php app/Messaging/PhpAmqpLibConnectionFactory.php app/Messaging/RabbitMqEventPublisher.php app/Providers/AppServiceProvider.php tests/Unit/Messaging/RabbitMqEventPublisherTest.php
git commit -m "feat: publish integration events with rabbitmq confirms"
```

### Task 4: Publish Best-Effort After Product Commit

**Files:**
- Modify: `platform/app/Features/Catalog/Actions/CreateProduct.php`
- Create: `platform/tests/Support/InMemoryEventPublisher.php`
- Create: `platform/tests/Feature/Catalog/ProductCreatedPublicationTest.php`

**Interfaces:**
- Consumes: `EventPublisher::publish(IntegrationEvent $event)` and `ProductCreated::fromProduct(Product $product)`.
- Produces: product creation followed by a publication attempt; publication failure is logged and does not change the HTTP result.

- [ ] **Step 1: Create a reusable in-memory publisher fake**

Implement `Tests\Support\InMemoryEventPublisher` with:

```php
/** @var list<IntegrationEvent> */
public array $published = [];

public ?EventPublicationFailed $failure = null;

/** @var (Closure(IntegrationEvent): void)|null */
public ?Closure $beforePublish = null;

public function publish(IntegrationEvent $event): void
{
    if ($this->failure !== null) {
        throw $this->failure;
    }

    ($this->beforePublish)?->__invoke($event);
    $this->published[] = $event;
}
```

- [ ] **Step 2: Write failing HTTP feature tests**

Generate a PHPUnit feature test:

```powershell
php artisan make:test --phpunit Catalog/ProductCreatedPublicationTest --no-interaction
```

Use `RefreshDatabase`. In `setUp()`, bind one fake instance into the container as `EventPublisher::class`.

Test A posts a valid product and asserts one published `ProductCreated` whose envelope matches the persisted product and whose event/routing types are `catalog.product.created`.

Test B configures the fake with `EventPublicationFailed`, calls `Log::spy()`, posts a product, then asserts:

```php
$response->assertRedirect();
$this->assertDatabaseHas('products', ['sku' => 'BROKER-DOWN-1']);
Log::shouldHaveReceived('error')->once()->with(
    'Failed to publish catalog.product.created.',
    Mockery::on(fn (array $context): bool =>
        $context['product_id'] !== ''
        && $context['event_id'] !== ''
        && $context['correlation_id'] !== ''
        && $context['exchange'] === 'ecommerce.events'
        && $context['routing_key'] === 'catalog.product.created'
        && $context['exception'] instanceof Throwable
    ),
);
```

Test C assigns `beforePublish` to a closure that queries the newly created product and asserts it already exists, then calls `CreateProduct`. This proves the publication call occurs after the transaction callback returns.

- [ ] **Step 3: Run the feature test to verify it fails**

```powershell
php artisan test --compact tests/Feature/Catalog/ProductCreatedPublicationTest.php
```

Expected: failure because `CreateProduct` does not yet publish.

- [ ] **Step 4: Add explicit transaction and best-effort publication**

Give `CreateProduct` a promoted `EventPublisher` dependency. Keep SKU exception translation around the transaction only:

```php
try {
    $product = DB::transaction(function () use ($attributes): Product {
        $product = new Product(Arr::only($attributes, ['sku', 'name', 'description', 'price_cents']));
        $product->forceFill(['is_active' => false])->save();

        return $product;
    });
} catch (QueryException $queryException) {
    ProductSkuConflict::rethrow($queryException);
}

$event = ProductCreated::fromProduct($product);

try {
    $this->events->publish($event);
} catch (EventPublicationFailed $failure) {
    Log::error('Failed to publish catalog.product.created.', [
        'event_id' => $event->eventId(),
        'product_id' => (string) $product->getKey(),
        'correlation_id' => $event->correlationId(),
        'exchange' => $failure->exchange,
        'routing_key' => $failure->routingKey,
        'exception' => $failure,
    ]);
}

return $product;
```

Do not catch persistence errors, validation errors, or arbitrary application exceptions as publication failures.

- [ ] **Step 5: Run focused and existing Catalog regression tests**

```powershell
php artisan test --compact tests/Feature/Catalog/ProductCreatedPublicationTest.php
php artisan test --compact tests/Feature/Catalog/ProductCrudTest.php tests/Feature/Catalog/ProductPersistenceTest.php
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse app/Features/Catalog/Actions/CreateProduct.php app/Features/Catalog/Events app/Messaging --memory-limit=1G
```

Expected: all tests and static checks exit `0`.

- [ ] **Step 6: Commit the Catalog integration**

```powershell
git add app/Features/Catalog/Actions/CreateProduct.php tests/Support/InMemoryEventPublisher.php tests/Feature/Catalog/ProductCreatedPublicationTest.php
git commit -m "feat: emit event when catalog product is created"
```

### Task 5: Verify Broker, Permissions, and End-to-End Publication

**Files:**
- Verify only; modify earlier task files only when a failing check identifies a defect.

**Interfaces:**
- Consumes: root Compose, RabbitMQ identity setup, Laravel producer, PostgreSQL.
- Produces: evidence that the approved first slice works with no queues or bindings.

- [ ] **Step 1: Start shared infrastructure**

From the repository root:

```powershell
docker compose up -d --wait catalog_db rabbitmq
docker compose run --rm rabbitmq_setup
docker compose ps
```

Expected: `catalog_db` and `rabbitmq` are healthy; `rabbitmq_setup` exits `0`.

- [ ] **Step 2: Verify topology and least-privilege permissions**

```powershell
docker compose exec rabbitmq rabbitmqctl list_vhosts name
docker compose exec rabbitmq rabbitmqctl list_exchanges -p ecommerce name type durable auto_delete
docker compose exec rabbitmq rabbitmqctl list_queues -p ecommerce name
docker compose exec rabbitmq rabbitmqctl list_permissions -p ecommerce
```

Expected:

- vhost list contains `ecommerce`;
- exchange list contains `ecommerce.events topic true false`;
- queue list contains zero queues;
- `catalog` permissions are configure `^$`, write `^ecommerce\.events$`, read `^$`.

- [ ] **Step 3: Prove bootstrap is idempotent**

```powershell
docker compose run --rm rabbitmq_setup
docker compose run --rm rabbitmq_setup
```

Expected: both runs exit `0`; topology inspection from Step 2 remains unchanged.

- [ ] **Step 4: Run the complete PHP verification gate**

From `platform/`:

```powershell
php artisan config:clear
php artisan test --compact
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=1G
```

Expected: tests, formatter, and static analysis all exit `0`.

- [ ] **Step 5: Smoke-test a real product publication**

From `platform/`, read the Management API count, execute the real action, and compare the count:

```powershell
$orchestrationValues = Get-Content ..\.env | Where-Object { $_ -match '^[A-Z0-9_]+=' } | ConvertFrom-StringData
$credentialBytes = [Text.Encoding]::UTF8.GetBytes("$($orchestrationValues.RABBITMQ_ADMIN_USER):$($orchestrationValues.RABBITMQ_ADMIN_PASSWORD)")
$managementHeaders = @{ Authorization = "Basic $([Convert]::ToBase64String($credentialBytes))" }
$exchangeUrl = 'http://127.0.0.1:15672/api/exchanges/ecommerce/ecommerce.events'
$beforeResponse = Invoke-RestMethod -Uri $exchangeUrl -Headers $managementHeaders
$publishCountBefore = if ($null -eq $beforeResponse.message_stats) { 0 } else { [int] $beforeResponse.message_stats.publish_in }
php artisan tinker --execute 'resolve(\App\Features\Catalog\Actions\CreateProduct::class)->handle(["sku" => "RABBIT-SMOKE-20260824-1", "name" => "RabbitMQ smoke product", "description" => "Real publisher smoke test", "price_cents" => 1990]);'
$afterResponse = Invoke-RestMethod -Uri $exchangeUrl -Headers $managementHeaders
$publishCountAfter = [int] $afterResponse.message_stats.publish_in
if ($publishCountAfter -le $publishCountBefore) { throw 'The exchange publish count did not increase.' }
php artisan tinker --execute 'dump(\App\Features\Catalog\Models\Product::query()->where("sku", "RABBIT-SMOKE-20260824-1")->exists());'
```

Expected: the count increases by at least one and Tinker prints `true`. Re-run the queue and binding inspection from Step 2 and confirm neither was created. Because there is intentionally no queue, do not expect a stored message or a publish-out count.

- [ ] **Step 6: Verify broker-down behavior**

```powershell
docker compose -f ..\compose.yaml stop rabbitmq
```

Still from `platform/`, execute the real action while the broker is stopped:

```powershell
php artisan tinker --execute 'resolve(\App\Features\Catalog\Actions\CreateProduct::class)->handle(["sku" => "RABBIT-DOWN-20260824-1", "name" => "Broker down product", "description" => "Best-effort failure smoke test", "price_cents" => 2990]);'
php artisan tinker --execute 'dump(\App\Features\Catalog\Models\Product::query()->where("sku", "RABBIT-DOWN-20260824-1")->exists());'
rg -n "Failed to publish catalog.product.created" storage/logs/laravel.log
```

Expected: Tinker prints `true` and the newest matching log contains `event_id`, `product_id`, `correlation_id`, `ecommerce.events`, `catalog.product.created`, and the exception. Restart and re-bootstrap:

```powershell
docker compose -f ..\compose.yaml start rabbitmq
docker compose -f ..\compose.yaml run --rm rabbitmq_setup
```

Expected: broker returns healthy; the failed event is not replayed automatically.

- [ ] **Step 7: Review the final scoped diff**

```powershell
git status --short
git diff --check
git log --oneline -5
```

Confirm no queue, binding, consumer, retry, DLQ, Inbox, Outbox, feature flag, or secret entered the tracked diff. Do not fold pre-existing documentation/artifact changes into the feature commits.

## Official References

- [RabbitMQ definition import](https://www.rabbitmq.com/docs/definitions)
- [RabbitMQ access control](https://www.rabbitmq.com/docs/access-control)
- [RabbitMQ publisher confirms](https://www.rabbitmq.com/docs/confirms)
- [php-amqplib repository and examples](https://github.com/php-amqplib/php-amqplib)
- [RabbitMQ official Docker image](https://hub.docker.com/_/rabbitmq)
