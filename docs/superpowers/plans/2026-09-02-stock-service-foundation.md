# Stock Service Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Criar a fundação executável e testada do `stock-service`, sem implementar regras da slice `initialize-stock`.

**Architecture:** API e worker são processos NestJS separados. `shared` contém somente configuração, banco, erros, health, logging e mensageria; Inbox e Outbox são mecanismos locais reutilizáveis, enquanto Ledger e regras de estoque permanecem fora desta fase.

**Tech Stack:** Node.js 24.15+, TypeScript strict/NodeNext, NestJS 12/Express, Prisma/PostgreSQL, Zod, amqplib, Jest/Supertest, pnpm 11 e Pino.

**Spec:** `docs/superpowers/specs/2026-09-02-stock-initialization-flow-design.md`

## Global Constraints

- Usar um único `pnpm-lock.yaml` na raiz e nunca criar lockfile dentro do serviço.
- Não criar `CombinedAppModule`; API e worker continuam separados.
- Não colocar Ledger, projeções ou regras de estoque em `shared`.
- A suíte automática não inicia Docker, PostgreSQL ou RabbitMQ reais.
- Não adicionar Testcontainers, Terminus, Nx ou Turborepo.
- Cada tarefa termina com build e testes relevantes verdes.

---

### Task 1: Bootstrap do workspace e toolchain

**Files:**
- Create: `package.json`
- Create: `pnpm-workspace.yaml`
- Create: `stock-service/package.json`
- Create: `stock-service/tsconfig.json`
- Create: `stock-service/tsconfig.build.json`
- Create: `stock-service/jest.config.ts`
- Create: `stock-service/src/shared/health/tests/toolchain.spec.ts`
- Delete: `.gitkeep` somente nos diretórios que passarem a conter arquivos reais

**Interfaces:**
- Consumes: Node.js `>=24.15.0` e Corepack/pnpm 11.
- Produces: scripts `build`, `test`, `test:watch`, `start:api`, `start:worker`, `dev:api`, `dev:worker`, `prisma:generate` e `prisma:migrate`.

- [ ] **Step 1: Escrever o teste mínimo de toolchain**

```ts
describe('stock-service toolchain', () => {
  it('executes TypeScript tests in NodeNext mode', () => {
    expect(import.meta.url.startsWith('file:')).toBe(true);
  });
});
```

- [ ] **Step 2: Executar o teste e comprovar a ausência da configuração**

Run: `corepack pnpm --filter stock-service test -- --runInBand`
Expected: FAIL porque workspace e pacote ainda não existem.

- [ ] **Step 3: Criar workspace, pacote e configurações**

O `pnpm-workspace.yaml` deve conter:

```yaml
packages:
  - stock-service
  - packages/*
```

O pacote deve declarar `type: module`, engines Node `>=24.15.0`, NestJS 12, Prisma, Zod, amqplib, Pino, Jest, ts-jest e Supertest, sem dependência de `@nestjs/cqrs` ou `@nestjs/terminus`.

- [ ] **Step 4: Instalar, testar e compilar**

Run: `corepack pnpm install`
Expected: cria somente `pnpm-lock.yaml` na raiz.

Run: `corepack pnpm --filter stock-service test -- --runInBand`
Expected: PASS.

Run: `corepack pnpm --filter stock-service build`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add package.json pnpm-workspace.yaml pnpm-lock.yaml stock-service
git commit -m "build(stock): bootstrap pnpm and TypeScript"
```

### Task 2: Configuração tipada e logging

**Files:**
- Create: `stock-service/src/shared/config/environment.schema.ts`
- Create: `stock-service/src/shared/config/config.module.ts`
- Create: `stock-service/src/shared/config/tests/environment.schema.spec.ts`
- Create: `stock-service/src/shared/logging/logger.module.ts`

**Interfaces:**
- Consumes: variáveis `SERVICE_NAME`, `API_PORT`, `WORKER_PORT`, `DATABASE_URL`, `RABBITMQ_URL`, `LOG_LEVEL` e `SHUTDOWN_GRACE_MS`.
- Produces: `Environment`, `environmentSchema` e `ConfigModule` global; logger Pino com `service` e `role`.

- [ ] **Step 1: Escrever testes de configuração**

```ts
const validEnvironment = {
  SERVICE_NAME: 'stock-service',
  API_PORT: '3001',
  WORKER_PORT: '3002',
  DATABASE_URL: 'postgresql://stock:stock@localhost:5432/stock_db',
  RABBITMQ_URL: 'amqp://stock:stock@localhost:5672/ecommerce',
  LOG_LEVEL: 'info',
  SHUTDOWN_GRACE_MS: '10000',
};
expect(() => environmentSchema.parse({})).toThrow();
expect(environmentSchema.parse(validEnvironment).SERVICE_NAME).toBe('stock-service');
```

- [ ] **Step 2: Executar e verificar falha**

Run: `corepack pnpm --filter stock-service test -- environment.schema.spec.ts --runInBand`
Expected: FAIL porque `environmentSchema` não existe.

- [ ] **Step 3: Implementar schema e módulos**

Use coerção Zod para portas e grace period, valide portas entre `1` e `65535` e proíba acesso direto a `process.env` fora deste diretório. Configure Pino em JSON para stdout, sem payloads ou segredos.

- [ ] **Step 4: Testar e compilar**

Run: `corepack pnpm --filter stock-service test -- environment.schema.spec.ts --runInBand`
Expected: PASS.

Run: `corepack pnpm --filter stock-service build`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add stock-service/src/shared/config stock-service/src/shared/logging
git commit -m "feat(stock): add typed configuration and logging"
```

### Task 3: Erros centralizados

**Files:**
- Create: `stock-service/src/shared/errors/application-error.ts`
- Create: `stock-service/src/shared/errors/problem-details.filter.ts`
- Create: `stock-service/src/shared/errors/consumer-error-classifier.ts`
- Create: `stock-service/src/shared/errors/tests/application-error.spec.ts`

**Interfaces:**
- Produces: `ApplicationError` com `code`, `kind`, mensagem segura, detalhes e causa; `ProblemDetailsFilter`; `classifyConsumerError(error): 'retry' | 'dlq' | 'result'`.

- [ ] **Step 1: Escrever testes para os cinco kinds**

```ts
expect(httpStatusFor('validation')).toBe(400);
expect(httpStatusFor('not_found')).toBe(404);
expect(httpStatusFor('conflict')).toBe(409);
expect(httpStatusFor('business')).toBe(422);
expect(httpStatusFor('transient')).toBe(503);
```

- [ ] **Step 2: Executar e verificar falha**

Run: `corepack pnpm --filter stock-service test -- application-error.spec.ts --runInBand`
Expected: FAIL porque os contratos de erro não existem.

- [ ] **Step 3: Implementar contratos e mapeamentos**

Erros estruturais e permanentes classificam como `dlq`, transitórios como `retry`, e recusas esperadas que geram evento como `result`. O filtro HTTP responde `application/problem+json` e nunca expõe stack.

- [ ] **Step 4: Testar e compilar**

Run: `corepack pnpm --filter stock-service test -- application-error.spec.ts --runInBand`
Expected: PASS.

Run: `corepack pnpm --filter stock-service build`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add stock-service/src/shared/errors
git commit -m "feat(stock): centralize application errors"
```

### Task 4: Prisma e persistência de Inbox/Outbox

**Files:**
- Create: `stock-service/prisma/schema.prisma`
- Create: `stock-service/prisma/migrations/202609020001_create_message_storage/migration.sql`
- Create: `stock-service/src/shared/database/prisma.service.ts`
- Create: `stock-service/src/shared/database/database.module.ts`
- Create: `stock-service/src/shared/database/tests/prisma.service.spec.ts`
- Modify: `compose.yaml`
- Modify: `.env.example`

**Interfaces:**
- Produces: `PrismaService`, models `InboxMessage` e `OutboxMessage`, e serviço Compose `stock_db`.
- Schema Inbox: `messageId`, `consumerName`, `messageType`, `messageVersion`, `payloadHash`, `processedAt`, unique composto.
- Schema Outbox: `messageId`, `exchange`, `routingKey`, `messageType`, `messageVersion`, `body`, `headers`, `createdAt`, `availableAt`, `publishedAt`, `attempts`, `lastError`.

- [ ] **Step 1: Escrever o teste do lifecycle do PrismaService**

Use um client fake e comprove que `onModuleInit` conecta e `onModuleDestroy` desconecta exatamente uma vez.

- [ ] **Step 2: Executar e verificar falha**

Run: `corepack pnpm --filter stock-service test -- prisma.service.spec.ts --runInBand`
Expected: FAIL porque `PrismaService` não existe.

- [ ] **Step 3: Criar schema, migration, serviço e Compose**

Use UUID para `message_id`, JSONB para `body` e `headers`, índice parcial de registros não publicados por `available_at` e nenhuma rotina de exclusão. `stock_db` usa volume e healthcheck próprios; credenciais de exemplo não contêm segredo real.

- [ ] **Step 4: Gerar client, testar e compilar**

Run: `corepack pnpm --filter stock-service prisma:generate`
Expected: PASS sem iniciar Docker.

Run: `corepack pnpm --filter stock-service test -- prisma.service.spec.ts --runInBand`
Expected: PASS sem iniciar Docker.

Run: `corepack pnpm --filter stock-service build`
Expected: PASS sem iniciar Docker.

- [ ] **Step 5: Commit**

```bash
git add compose.yaml .env.example stock-service/prisma stock-service/src/shared/database
git commit -m "feat(stock): add Prisma message storage"
```

### Task 5: Envelopes e idempotência genéricos

**Files:**
- Create: `stock-service/src/shared/messaging/message-envelope.schema.ts`
- Create: `stock-service/src/shared/messaging/canonical-message-hash.ts`
- Create: `stock-service/src/shared/messaging/inbox.service.ts`
- Create: `stock-service/src/shared/messaging/outbox.service.ts`
- Create: `stock-service/src/shared/messaging/tests/message-storage.spec.ts`

**Interfaces:**
- Produces: schemas-base de comando/evento; `canonicalMessageHash(message): string`; `InboxService.begin(messageId, consumerName, hash)`; `OutboxService.enqueue(message, destination, transaction)`.

- [ ] **Step 1: Escrever testes de duplicação e mismatch**

```ts
await expect(inbox.begin(id, consumer, hashA)).resolves.toEqual({ duplicate: false });
await expect(inbox.begin(id, consumer, hashA)).resolves.toEqual({ duplicate: true });
await expect(inbox.begin(id, consumer, hashB)).rejects.toMatchObject({ code: 'MESSAGE_PAYLOAD_MISMATCH' });
```

- [ ] **Step 2: Executar e verificar falha**

Run: `corepack pnpm --filter stock-service test -- message-storage.spec.ts --runInBand`
Expected: FAIL porque os serviços não existem.

- [ ] **Step 3: Implementar hash, Inbox e Outbox**

Canonicalize o JSON parseado antes de SHA-256; não use bytes brutos nem ordem original das chaves. A Outbox recebe o client transacional para participar do mesmo commit do caso de uso.

- [ ] **Step 4: Testar e compilar**

Run: `corepack pnpm --filter stock-service test -- message-storage.spec.ts --runInBand`
Expected: PASS.

Run: `corepack pnpm --filter stock-service build`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add stock-service/src/shared/messaging
git commit -m "feat(stock): add inbox and outbox primitives"
```

### Task 6: Cliente RabbitMQ, dispatcher e consumer wrapper

**Files:**
- Create: `stock-service/src/shared/messaging/amqp-connection.service.ts`
- Create: `stock-service/src/shared/messaging/confirmed-publisher.ts`
- Create: `stock-service/src/shared/messaging/outbox-dispatcher.service.ts`
- Create: `stock-service/src/shared/messaging/consumer-runner.ts`
- Create: `stock-service/src/shared/messaging/messaging.module.ts`
- Create: `stock-service/src/shared/messaging/tests/consumer-runner.spec.ts`
- Create: `stock-service/src/shared/messaging/tests/outbox-dispatcher.spec.ts`

**Interfaces:**
- Produces: publicação persistente com confirm; ACK após resultado confirmado; retry `5s/30s/2m`; DLQ após terceira falha ou imediatamente para mensagem inválida.

- [ ] **Step 1: Escrever testes de confirm, ACK, retry e DLQ com canal fake**

Comprove que publicação confirmada antecede `publishedAt`, que falha no confirm preserva a mensagem pendente e que ACK da original só acontece depois do confirm da tentativa de retry.

- [ ] **Step 2: Executar e verificar falha**

Run: `corepack pnpm --filter stock-service test -- consumer-runner.spec.ts outbox-dispatcher.spec.ts --runInBand`
Expected: FAIL porque os adaptadores ainda não existem.

- [ ] **Step 3: Implementar adaptadores sem declarar topologia**

Use `amqplib` diretamente, `deliveryMode: 2`, `contentType: application/json`, `messageId`, `type`, `correlationId` e headers versionados. A aplicação deve falhar em readiness se os recursos centrais estiverem ausentes; não chamar `assertExchange` ou `assertQueue`.

- [ ] **Step 4: Testar e compilar**

Run: `corepack pnpm --filter stock-service test -- consumer-runner.spec.ts outbox-dispatcher.spec.ts --runInBand`
Expected: PASS.

Run: `corepack pnpm --filter stock-service build`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add stock-service/src/shared/messaging
git commit -m "feat(stock): add reliable RabbitMQ adapters"
```

### Task 7: API, worker, health e lifecycle

**Files:**
- Create: `stock-service/src/main-api.ts`
- Create: `stock-service/src/main-worker.ts`
- Create: `stock-service/src/http-app.module.ts`
- Create: `stock-service/src/worker-app.module.ts`
- Create: `stock-service/src/shared/health/health.controller.ts`
- Create: `stock-service/src/shared/health/health.service.ts`
- Create: `stock-service/src/shared/health/health.module.ts`
- Create: `stock-service/src/shared/health/tests/health.spec.ts`
- Create: `stock-service/.env.example`
- Create: `stock-service/Dockerfile`
- Create: `stock-service/README.md`

**Interfaces:**
- Produces: `/health/live` e `/health/ready` em API e worker; shutdown ordenado de consumo, RabbitMQ e Prisma.

- [ ] **Step 1: Escrever testes Supertest de liveness e readiness**

```ts
await request(app.getHttpServer()).get('/health/live').expect(200, { status: 'ok' });
await request(app.getHttpServer()).get('/health/ready').expect(503);
```

- [ ] **Step 2: Executar e verificar falha**

Run: `corepack pnpm --filter stock-service test -- health.spec.ts --runInBand`
Expected: FAIL porque os módulos não existem.

- [ ] **Step 3: Implementar os dois processos**

A API verifica apenas as dependências exigidas por suas rotas; o worker verifica Prisma, conexão/canal RabbitMQ e registro dos consumers. O worker abre somente a porta administrativa, sem endpoint de negócio. Implemente graceful shutdown limitado por `SHUTDOWN_GRACE_MS`.

- [ ] **Step 4: Executar o quality gate da fundação**

Run: `corepack pnpm --filter stock-service test -- --runInBand`
Expected: PASS sem Docker.

Run: `corepack pnpm --filter stock-service build`
Expected: PASS em TypeScript strict/NodeNext.

- [ ] **Step 5: Commit**

```bash
git add stock-service
git commit -m "feat(stock): bootstrap API and worker processes"
```
