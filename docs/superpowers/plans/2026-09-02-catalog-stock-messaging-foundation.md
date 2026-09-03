# Catalog–Stock Messaging Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (- [ ]) syntax for tracking.

**Goal:** Preparar a topologia RabbitMQ e os mecanismos genéricos de Inbox/Outbox do Laravel para o fluxo confiável entre Catálogo e Stock.

**Architecture:** A infraestrutura central declara todos os recursos RabbitMQ. O Laravel grava mensagens imutáveis em seu PostgreSQL, publica por dispatcher com confirm e consome resultados por um runner que decide ACK, retry ou DLQ.

**Tech Stack:** PHP 8.5, Laravel 13.17, php-amqplib 3.7, PostgreSQL, Pest 5 e RabbitMQ Management.

**Spec:** docs/superpowers/specs/2026-09-02-stock-initialization-flow-design.md

## Global Constraints

- Ler platform/AGENTS.md e regras aplicáveis antes de editar PHP.
- Usar Artisan com --no-interaction para gerar artefatos Laravel.
- Manter o driver database para jobs internos; RabbitMQ serve apenas à integração.
- Não adicionar dependências PHP nem declarar topologia a partir da aplicação.
- Executar Pint após qualquer alteração PHP.

---

### Task 1: Topologia de comandos, eventos, retries e DLQs

**Files:**
- Modify: infra/rabbitmq/definitions.json
- Modify: infra/rabbitmq/provision-identity.sh
- Modify: compose.yaml
- Modify: .env.example

**Interfaces:**
- Produces: exchanges ecommerce.commands, ecommerce.events, ecommerce.retries e ecommerce.dead-letter; filas stock.commands, catalog.stock-events, três retries e uma DLQ para cada consumer.

- [ ] **Step 1: Registrar o estado inicial**

~~~powershell
$definitions = Get-Content -Raw infra/rabbitmq/definitions.json | ConvertFrom-Json
$definitions.exchanges.name
$definitions.queues.name
~~~

Expected: somente ecommerce.events e nenhuma fila.

- [ ] **Step 2: Adicionar exchanges, filas, bindings e policies**

Bindings principais:

~~~text
ecommerce.commands -> stock.commands        [stock.item.initialize]
ecommerce.events   -> catalog.stock-events  [stock.item.initialized]
ecommerce.events   -> catalog.stock-events  [stock.item.initialization_rejected]
~~~

Cada consumer recebe .retry.1, .retry.2 e .retry.3 com TTLs 5000, 30000 e 120000, retorno exclusivo à fila principal e DLQ terminal sem TTL.

- [ ] **Step 3: Provisionar identidades mínimas**

catalog recebe write em ecommerce.commands, ecommerce.retries e ecommerce.dead-letter, e read somente nas filas catalog.stock-events. stock recebe read somente nas filas stock.commands e write em ecommerce.events, ecommerce.retries e ecommerce.dead-letter. Nenhuma identidade de aplicação recebe configure.

- [ ] **Step 4: Validar JSON e Compose**

Run: Get-Content -Raw infra/rabbitmq/definitions.json | ConvertFrom-Json | Out-Null
Expected: exit 0.

Run: docker compose config --quiet
Expected: exit 0 quando Docker Compose estiver disponível; não iniciar containers.

- [ ] **Step 5: Commit**

~~~bash
git add infra/rabbitmq compose.yaml .env.example
git commit -m "feat(messaging): provision stock command topology"
~~~

### Task 2: Persistência local de Inbox e Outbox

**Files:**
- Create: platform/database/migrations/2026_09_02_000100_create_inbox_messages_table.php
- Create: platform/database/migrations/2026_09_02_000200_create_outbox_messages_table.php
- Create: platform/app/Messaging/Models/InboxMessage.php
- Create: platform/app/Messaging/Models/OutboxMessage.php
- Create: platform/database/factories/InboxMessageFactory.php
- Create: platform/database/factories/OutboxMessageFactory.php
- Create: platform/tests/Feature/Messaging/MessageStorageTest.php

**Interfaces:**
- Produces: tabelas e models com os campos aprovados na spec e unicidade message_id + consumer_name.

- [ ] **Step 1: Gerar artefatos Laravel**

~~~bash
php artisan make:model Messaging/Models/InboxMessage --factory --no-interaction
php artisan make:model Messaging/Models/OutboxMessage --factory --no-interaction
php artisan make:migration create_inbox_messages_table --no-interaction
php artisan make:migration create_outbox_messages_table --no-interaction
php artisan make:test --pest Messaging/MessageStorageTest --no-interaction
~~~

Como os models de mensageria pertencem a `app/Messaging/Models`, mova os arquivos gerados de `app/Models/Messaging/Models` para os caminhos declarados nesta em **Files** antes de editar namespace e factories. Verifique os dois caminhos absolutos dentro de `platform/` antes do `Move-Item`.

- [ ] **Step 2: Escrever testes de persistência**

Comprove que dois consumers podem registrar o mesmo message_id, que o mesmo consumer não pode registrá-lo duas vezes e que uma Outbox pendente possui available_at e published_at nulo.

- [ ] **Step 3: Executar e verificar falha**

Run: php artisan test --compact tests/Feature/Messaging/MessageStorageTest.php
Expected: FAIL enquanto migrations e casts não estiverem completos.

- [ ] **Step 4: Implementar migrations, casts e factories**

Use UUID para identidades, JSONB para body/headers, integer não negativo para attempts, timestamps com timezone e índice de pendentes por published_at e available_at. Não implemente limpeza.

- [ ] **Step 5: Testar, formatar e commit**

Run: php artisan test --compact tests/Feature/Messaging/MessageStorageTest.php
Expected: PASS.

Run: vendor/bin/pint --dirty --format agent
Expected: exit 0.

~~~bash
git add platform/app/Messaging/Models platform/database platform/tests/Feature/Messaging
git commit -m "feat(platform): persist inbox and outbox messages"
~~~

### Task 3: Envelope genérico e gravação da Outbox

**Files:**
- Create: platform/app/Messaging/Contracts/IntegrationMessage.php
- Create: platform/app/Messaging/Contracts/MessagePublisher.php
- Create: platform/app/Messaging/MessageEnvelope.php
- Create: platform/app/Messaging/CanonicalMessageHash.php
- Create: platform/app/Messaging/Outbox/OutboxRecorder.php
- Create: platform/tests/Unit/Messaging/MessageEnvelopeTest.php
- Modify: platform/app/Providers/AppServiceProvider.php
- Modify: platform/app/Messaging/Contracts/IntegrationEvent.php
- Modify: platform/app/Messaging/Contracts/EventPublisher.php

**Interfaces:**
- Produces: IntegrationMessage com messageId(), messageType(), messageVersion(), correlationId(), body() e headers(); OutboxRecorder::record(IntegrationMessage $message, string $exchange, string $routingKey): void.

- [ ] **Step 1: Escrever teste Pest do envelope e hash**

Comprove JSON em snake_case, UUID estável, timestamp UTC com milissegundos e SHA-256 idêntico para objetos com chaves em ordens diferentes.

- [ ] **Step 2: Executar e verificar falha**

Run: php artisan test --compact tests/Unit/Messaging/MessageEnvelopeTest.php
Expected: FAIL porque contratos e implementações não existem.

- [ ] **Step 3: Implementar contratos e recorder**

O recorder recebe mensagem já completa e persiste body/headers sem consultar Produto. Ele nunca publica no RabbitMQ.
Durante a transição, `IntegrationEvent` estende `IntegrationMessage` e o publisher legado delega ao contrato genérico, mantendo os testes existentes verdes até `catalog.product.created` ser removido no plano seguinte.

- [ ] **Step 4: Testar, formatar e commit**

Run: php artisan test --compact tests/Unit/Messaging/MessageEnvelopeTest.php
Expected: PASS.

Run: vendor/bin/pint --dirty --format agent
Expected: exit 0.

~~~bash
git add platform/app/Messaging platform/app/Providers/AppServiceProvider.php platform/tests/Unit/Messaging
git commit -m "feat(platform): record immutable integration messages"
~~~

### Task 4: Dispatcher confiável da Outbox

**Files:**
- Create: platform/app/Messaging/Outbox/DispatchOutboxMessages.php
- Create: platform/app/Console/Commands/DispatchIntegrationOutbox.php
- Create: platform/app/Messaging/RabbitMqMessagePublisher.php
- Modify: platform/app/Messaging/RabbitMqEventPublisher.php
- Modify: platform/tests/Unit/Messaging/RabbitMqEventPublisherTest.php
- Create: platform/tests/Feature/Messaging/DispatchOutboxMessagesTest.php

**Interfaces:**
- Consumes: registros pendentes ordenados por available_at e created_at.
- Produces: publicação persistente com publisher confirm; published_at somente após confirmação; attempts e last_error em falha.

- [ ] **Step 1: Gerar command e teste Pest**

~~~bash
php artisan make:command DispatchIntegrationOutbox --no-interaction
php artisan make:test --pest Messaging/DispatchOutboxMessagesTest --no-interaction
~~~

- [ ] **Step 2: Escrever testes de sucesso e falha do confirm**

Use publisher fake: sucesso marca apenas a mensagem confirmada; falha mantém published_at nulo, incrementa attempts e preserva body.

- [ ] **Step 3: Executar e verificar falha**

Run: php artisan test --compact tests/Feature/Messaging/DispatchOutboxMessagesTest.php
Expected: FAIL antes do dispatcher.

- [ ] **Step 4: Implementar dispatcher e generalizar publisher**

Não manter publishers paralelos. O command executa uma iteração limitada e retorna código diferente de zero somente em falha fatal do processo.

- [ ] **Step 5: Testar, formatar e commit**

Run: php artisan test --compact tests/Feature/Messaging/DispatchOutboxMessagesTest.php
Expected: PASS.

Run: vendor/bin/pint --dirty --format agent
Expected: exit 0.

~~~bash
git add platform/app/Messaging platform/app/Console platform/tests/Feature/Messaging
git commit -m "feat(platform): dispatch integration outbox"
~~~

### Task 5: Runner de consumers com Inbox, retries e DLQ

**Files:**
- Create: platform/app/Messaging/Consumers/ConsumerHandler.php
- Create: platform/app/Messaging/Consumers/ConsumerRunner.php
- Create: platform/app/Messaging/Inbox/InboxRecorder.php
- Create: platform/app/Console/Commands/ConsumeIntegrationMessages.php
- Create: platform/tests/Unit/Messaging/ConsumerRunnerTest.php

**Interfaces:**
- Produces: ConsumerRunner::handle(AmqpMessage $message, string $consumerName, ConsumerHandler $handler): void; ACK, retry ou DLQ conforme classificação.

- [ ] **Step 1: Gerar command e teste Pest**

~~~bash
php artisan make:command ConsumeIntegrationMessages --no-interaction
php artisan make:test --pest --unit Messaging/ConsumerRunnerTest --no-interaction
~~~

- [ ] **Step 2: Escrever a matriz de testes**

Cubra sucesso com ACK depois do commit; duplicata com mesmo hash e sem efeito; MESSAGE_PAYLOAD_MISMATCH para outro conteúdo; transient para retries 1/2/3; inválida direto para DLQ; ACK da original somente após publisher confirm da transferência.

- [ ] **Step 3: Executar e verificar falha**

Run: php artisan test --compact tests/Unit/Messaging/ConsumerRunnerTest.php
Expected: FAIL porque o runner não existe.

- [ ] **Step 4: Implementar runner sem handler de negócio**

O command recebe o nome da fila por argumento permitido, resolve o handler registrado e não declara topologia. Não criar consumer de catalog.product.created.

- [ ] **Step 5: Executar quality gate e commit**

Run: php artisan test --compact tests/Unit/Messaging/ConsumerRunnerTest.php tests/Feature/Messaging
Expected: PASS.

Run: vendor/bin/pint --dirty --format agent
Expected: exit 0.

~~~bash
git add platform/app/Messaging platform/app/Console platform/tests
git commit -m "feat(platform): add idempotent message consumer runner"
~~~
