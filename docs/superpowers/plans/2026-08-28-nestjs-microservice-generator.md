# NestJS Microservice Generator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a separately versioned generator that creates a production-shaped NestJS service scaffold without adding generator code, nested Git metadata, or a lockfile to `inventory-lab`.

**Architecture:** The generator is a small Node.js CLI that validates a service name, copies an embedded text template, replaces explicit tokens, and refuses to overwrite a non-empty target. The generated service contains one codebase with separate API and worker entrypoints, pragmatic feature slices, centralized HTTP/RabbitMQ error handling, custom health checks, and a removable example feature. This plan stops after validating the generic generated project; creating an actual service in `inventory-lab` is Plan 2.

**Tech Stack:** Node.js 24.15+, TypeScript strict/NodeNext, pnpm, NestJS 12.0.1+, Express, Prisma ORM 7.10, PostgreSQL, Zod 4.4, `amqplib` 2.0, Jest 30.4, Supertest 7, Pino 10.3.

**Spec:** `../inventory-lab/docs/superpowers/specs/2026-08-28-nestjs-microservices-design.md`

## Global Constraints

- Execute this plan in the separate repository `F:\dev\projects\study\nestjs-microservice-generator`, not inside `inventory-lab`.
- Use Node.js 24 LTS at version 24.15 or newer and lower than 25.
- Use NestJS 12 with the Express adapter; do not use Fastify.
- Use TypeScript with `strict`, `module: NodeNext`, `moduleResolution: NodeNext`, and ESM.
- Use Prisma ORM 7.10 with `@prisma/adapter-pg`; do not adopt the Prisma 8 release line in this first template.
- Use Zod as the source for request, response, environment, and message schemas.
- Use `amqplib` directly; do not install `@nestjs/microservices` for RabbitMQ transport.
- Use custom health controllers and indicators; do not install `@nestjs/terminus` until a release declares NestJS 12 compatibility.
- Use Jest feature tests with fakes; generated-service tests must not require Docker, PostgreSQL, or RabbitMQ.
- The generated project must not contain `.git`, `pnpm-lock.yaml`, `package-lock.json`, or `bun.lock`.
- The template includes no orders, stock, or payments business rules.
- Do not create the first real service in this plan.

## Target File Map

The external repository will contain:

```text
nestjs-microservice-generator/
├── package.json
├── pnpm-lock.yaml
├── tsconfig.json
├── jest.config.ts
├── src/
│   ├── cli.ts
│   └── generator/
│       ├── generate-service.ts
│       ├── render-template.ts
│       └── service-name.ts
├── tests/
│   ├── generator.feature.spec.ts
│   ├── generated-service.acceptance.spec.ts
│   └── helpers/{test-project,run-command}.ts
├── templates/service/
│   ├── package.json
│   ├── tsconfig.json
│   ├── tsconfig.build.json
│   ├── jest.config.ts
│   ├── prisma.config.ts
│   ├── prisma/schema.prisma
│   ├── Dockerfile
│   ├── .env.example
│   ├── .gitignore
│   ├── .template-version
│   └── src/
│       ├── main-api.ts
│       ├── main-worker.ts
│       ├── http-app.module.ts
│       ├── worker-app.module.ts
│       ├── generated/prisma/.gitkeep
│       ├── shared/{config,database,errors,health,logging,messaging}/
│       └── features/example/
└── README.md
```

---

### Task 1: Initialize the external repository and validate service names

**Files:**
- Create: `package.json`
- Create: `tsconfig.json`
- Create: `jest.config.ts`
- Create: `.gitignore`
- Create: `src/generator/service-name.ts`
- Test: `tests/generator.feature.spec.ts`

**Interfaces:**
- Consumes: raw CLI name such as `orders` or `orders-service`.
- Produces: `parseServiceName(input: string): ServiceIdentity`, where `ServiceIdentity` contains `serviceName`, `className`, and `envPrefix`.

- [ ] **Step 1: Create the repository and package metadata**

Run in PowerShell:

```powershell
New-Item -ItemType Directory -Path 'F:\dev\projects\study\nestjs-microservice-generator'
Set-Location 'F:\dev\projects\study\nestjs-microservice-generator'
git init -b main
pnpm init
pnpm add -D typescript@^5.9.0 tsx@^4.20.0 jest@^30.4.2 @types/jest@^30.0.0 @types/node@^24.0.0 @swc/core @swc/jest@^0.2.39
```

Set `package.json` to expose `pnpm generate`, `pnpm build`, `pnpm test`, and `pnpm test:acceptance`. Set `engines.node` to `>=24.15 <25`, `type` to `module`, and `private` to `true` until distribution is intentionally enabled.

- [ ] **Step 2: Configure strict NodeNext compilation and Jest**

Use these compiler and transform settings:

```json
{
  "compilerOptions": {
    "target": "ES2023",
    "module": "NodeNext",
    "moduleResolution": "NodeNext",
    "strict": true,
    "noUncheckedIndexedAccess": true,
    "esModuleInterop": true,
    "rootDir": ".",
    "outDir": "dist",
    "types": ["node", "jest"]
  },
  "include": ["src/**/*.ts", "tests/**/*.ts", "jest.config.ts"]
}
```

```ts
import type { Config } from 'jest';

export default {
  testEnvironment: 'node',
  testMatch: ['<rootDir>/tests/**/*.spec.ts'],
  transform: { '^.+\\.ts$': ['@swc/jest'] },
} satisfies Config;
```

- [ ] **Step 3: Write failing name-validation tests**

```ts
import { parseServiceName } from '../src/generator/service-name.js';

describe('service identity', () => {
  it.each([
    ['orders', 'orders-service', 'OrdersService', 'ORDERS_SERVICE'],
    ['stock-service', 'stock-service', 'StockService', 'STOCK_SERVICE'],
  ])('normalizes %s', (input, serviceName, className, envPrefix) => {
    expect(parseServiceName(input)).toEqual({ serviceName, className, envPrefix });
  });

  it.each(['', 'Orders', '../orders', 'orders_service', 'orders service'])('rejects %s', (input) => {
    expect(() => parseServiceName(input)).toThrow('Use lowercase kebab-case');
  });
});
```

- [ ] **Step 4: Run the test and verify failure**

Run: `pnpm test -- generator.feature.spec.ts`

Expected: FAIL because `service-name.ts` does not exist.

- [ ] **Step 5: Implement deterministic normalization**

```ts
export interface ServiceIdentity {
  serviceName: string;
  className: string;
  envPrefix: string;
}

export function parseServiceName(input: string): ServiceIdentity {
  if (!/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/.test(input)) {
    throw new Error('Use lowercase kebab-case for the service name');
  }
  const serviceName = input.endsWith('-service') ? input : `${input}-service`;
  const className = serviceName.split('-').map(part => part[0]!.toUpperCase() + part.slice(1)).join('');
  return { serviceName, className, envPrefix: serviceName.replaceAll('-', '_').toUpperCase() };
}
```

- [ ] **Step 6: Verify and commit**

Run: `pnpm test && pnpm build`

Expected: both commands exit 0.

```powershell
git add package.json pnpm-lock.yaml tsconfig.json jest.config.ts .gitignore src tests
git commit -m "feat: initialize microservice generator"
```

### Task 2: Implement safe template rendering and the CLI

**Files:**
- Create: `src/generator/render-template.ts`
- Create: `src/generator/generate-service.ts`
- Create: `src/cli.ts`
- Modify: `tests/generator.feature.spec.ts`
- Create: `tests/fixtures/minimal-template/nested/config.txt`
- Create: `tests/helpers/test-project.ts`

**Interfaces:**
- Consumes: `GenerateServiceOptions { name, targetRoot, templateRoot, templateVersion }`.
- Produces: `generateService(options): Promise<{ outputDirectory: string; identity: ServiceIdentity }>`.

- [ ] **Step 1: Add failing generation tests**

```ts
const fixtureRoot = fileURLToPath(new URL('./fixtures/minimal-template', import.meta.url));
let targetRoot: string;

beforeEach(async () => {
  targetRoot = await mkdtemp(join(tmpdir(), 'generator-'));
});

it('copies dotfiles, replaces tokens, and records the template version', async () => {
  const result = await generateService({
    name: 'orders',
    targetRoot,
    templateRoot: fixtureRoot,
    templateVersion: '1.0.0',
  });
  expect(result.outputDirectory).toBe(join(targetRoot, 'orders-service'));
  expect(await readFile(join(result.outputDirectory, 'nested/config.txt'), 'utf8'))
    .toBe('orders-service|OrdersService|ORDERS_SERVICE');
  expect(await readFile(join(result.outputDirectory, '.template-version'), 'utf8')).toBe('1.0.0\n');
});

it('refuses to overwrite a non-empty service directory', async () => {
  await expect(generateService({ name: 'orders', targetRoot, templateRoot: fixtureRoot, templateVersion: '1.0.0' }))
    .rejects.toThrow('Target directory already exists');
});
```

- [ ] **Step 2: Verify the tests fail**

Run: `pnpm test -- generator.feature.spec.ts`

Expected: FAIL because `generateService` is missing.

- [ ] **Step 3: Implement token rendering and safe copy**

```ts
const tokenValues = (identity: ServiceIdentity, version: string) => new Map([
  ['__SERVICE_NAME__', identity.serviceName],
  ['__SERVICE_CLASS__', identity.className],
  ['__SERVICE_ENV_PREFIX__', identity.envPrefix],
  ['__TEMPLATE_VERSION__', version],
]);

export function renderTemplate(content: string, values: ReadonlyMap<string, string>): string {
  return [...values].reduce((result, [token, value]) => result.replaceAll(token, value), content);
}
```

`generateService` must resolve all paths, require the destination to be absent, recursively enumerate `templateRoot`, create directories, render UTF-8 files, and remove the partially created destination if copying fails. Do not follow symbolic links. Reject any template entry whose resolved destination escapes the service directory.

Add test helpers with these exact signatures for later tasks:

```ts
export async function pathExists(path: string): Promise<boolean> {
  return access(path).then(() => true, () => false);
}

export async function generateFixture(name: string): Promise<string> {
  const targetRoot = await mkdtemp(join(tmpdir(), 'generated-service-'));
  const result = await generateService({
    name,
    targetRoot,
    templateRoot: fileURLToPath(new URL('../../templates/service', import.meta.url)),
    templateVersion: '1.0.0',
  });
  return result.outputDirectory;
}
```

- [ ] **Step 4: Implement the CLI contract**

```ts
const { values } = parseArgs({
  options: {
    name: { type: 'string', short: 'n' },
    output: { type: 'string', short: 'o' },
  },
});

if (!values.name || !values.output) {
  throw new Error('Usage: pnpm generate --name orders --output ../inventory-lab/services');
}
```

Read the version from the generator's own `package.json`, use `templates/service` as `templateRoot`, and print only the absolute generated directory on success.

- [ ] **Step 5: Verify and commit**

Run: `pnpm test && pnpm build`

Expected: all generator tests pass and TypeScript compiles.

```powershell
git add src tests package.json
git commit -m "feat: generate services from embedded templates"
```

### Task 3: Add the generated project's toolchain and process skeleton

**Files:**
- Create: `templates/service/package.json`
- Create: `templates/service/tsconfig.json`
- Create: `templates/service/tsconfig.build.json`
- Create: `templates/service/jest.config.ts`
- Create: `templates/service/.swcrc`
- Create: `templates/service/.gitignore`
- Create: `templates/service/.env.example`
- Create: `templates/service/.template-version`
- Create: `templates/service/src/main-api.ts`
- Create: `templates/service/src/main-worker.ts`
- Create: `templates/service/src/http-app.module.ts`
- Create: `templates/service/src/worker-app.module.ts`
- Modify: `tests/generator.feature.spec.ts`

**Interfaces:**
- Consumes: template tokens from Task 2.
- Produces: generated scripts `dev`, `dev:api`, `dev:worker`, `build`, `start:api`, `start:worker`, `test`, `prisma:generate`, `prisma:migrate:deploy`.

- [ ] **Step 1: Add a failing manifest test**

```ts
it('generates the approved NodeNext NestJS toolchain without nested metadata', async () => {
  const output = await generateFixture('orders');
  const pkg = JSON.parse(await readFile(join(output, 'package.json'), 'utf8'));
  expect(pkg.name).toBe('orders-service');
  expect(pkg.engines.node).toBe('>=24.15 <25');
  expect(pkg.dependencies['@nestjs/common']).toBe('^12.0.1');
  expect(pkg.dependencies['@nestjs/terminus']).toBeUndefined();
  expect(await pathExists(join(output, 'pnpm-lock.yaml'))).toBe(false);
  expect(await pathExists(join(output, '.git'))).toBe(false);
});
```

- [ ] **Step 2: Verify failure**

Run: `pnpm test -- generator.feature.spec.ts`

Expected: FAIL because the service template manifest does not exist.

- [ ] **Step 3: Create the service manifest**

Use these runtime ranges in the template: Nest packages `^12.0.1`, `@nestjs/config ^12.0.0`, Prisma packages `^7.10.0`, `amqplib ^2.0.1`, `pino ^10.3.1`, `pino-http ^11.0.0`, `zod ^4.4.3`, `zod-openapi ^6.0.1`, `reflect-metadata ^0.2.2`, and `rxjs ^7.8.2`. Add `pg`, `dotenv`, and `concurrently`. Use Jest 30, Supertest 7, SWC/Jest, TypeScript 5.9, and matching Nest testing packages as dev dependencies.

The essential scripts are:

```json
{
  "dev": "concurrently -k -n api,worker \"pnpm dev:api\" \"pnpm dev:worker\"",
  "dev:api": "tsx watch src/main-api.ts",
  "dev:worker": "tsx watch src/main-worker.ts",
  "build": "pnpm prisma:generate && tsc -p tsconfig.build.json",
  "start:api": "node dist/main-api.js",
  "start:worker": "node dist/main-worker.js",
  "test": "jest --runInBand",
  "prisma:generate": "prisma generate",
  "prisma:migrate:deploy": "prisma migrate deploy"
}
```

- [ ] **Step 4: Create strict ESM configuration and empty root modules**

Set `type: module`, `module/moduleResolution: NodeNext`, `target: ES2023`, decorators enabled, `strict: true`, and `strictPropertyInitialization: false`. Imports in source must include emitted `.js` extensions.

```ts
@Module({ imports: [SharedConfigModule, LoggingModule] })
export class HttpAppModule {}

@Module({ imports: [SharedConfigModule, LoggingModule] })
export class WorkerAppModule {}
```

- [ ] **Step 5: Verify and commit**

Run: `pnpm test`

Expected: manifest and token tests pass.

```powershell
git add templates tests
git commit -m "feat: add NestJS service process skeleton"
```

### Task 4: Add validated configuration, Pino logging, and Prisma

**Files:**
- Create: `templates/service/src/shared/config/env.schema.ts`
- Create: `templates/service/src/shared/config/shared-config.module.ts`
- Create: `templates/service/src/shared/logging/app-logger.service.ts`
- Create: `templates/service/src/shared/logging/logging.module.ts`
- Create: `templates/service/src/shared/database/prisma.service.ts`
- Create: `templates/service/src/shared/database/database.module.ts`
- Create: `templates/service/prisma/schema.prisma`
- Create: `templates/service/prisma.config.ts`
- Test: `templates/service/src/shared/config/config.feature.spec.ts`

**Interfaces:**
- Produces: `Environment`, `AppLoggerService`, and global `PrismaService`.
- Consumes later: `PrismaService.isReady(): Promise<boolean>` and `AppLoggerService` implementing Nest `LoggerService`.

- [ ] **Step 1: Write the failing environment feature test**

```ts
it('rejects startup configuration without database and RabbitMQ URLs', () => {
  expect(() => envSchema.parse({ SERVICE_NAME: 'example-service' }))
    .toThrow(/DATABASE_URL/);
});

it('coerces ports and applies safe defaults', () => {
  const validEnv = {
    SERVICE_NAME: 'example-service',
    DATABASE_URL: 'postgresql://postgres:postgres@localhost:5432/example',
    RABBITMQ_URL: 'amqp://guest:guest@localhost:5672',
  };
  expect(envSchema.parse(validEnv)).toMatchObject({
    API_PORT: 3000,
    WORKER_ADMIN_PORT: 3001,
    SHUTDOWN_TIMEOUT_MS: 10000,
  });
});
```

- [ ] **Step 2: Run and verify failure**

Run inside a generated fixture: `pnpm test -- config.feature.spec.ts`

Expected: FAIL because `envSchema` is missing.

- [ ] **Step 3: Implement the environment schema and global config module**

```ts
export const envSchema = z.object({
  NODE_ENV: z.enum(['development', 'test', 'production']).default('development'),
  SERVICE_NAME: z.string().min(1),
  API_PORT: z.coerce.number().int().positive().default(3000),
  WORKER_ADMIN_PORT: z.coerce.number().int().positive().default(3001),
  DATABASE_URL: z.string().url(),
  RABBITMQ_URL: z.string().url(),
  LOG_LEVEL: z.enum(['trace', 'debug', 'info', 'warn', 'error', 'fatal']).default('info'),
  SHUTDOWN_TIMEOUT_MS: z.coerce.number().int().positive().default(10000),
});
export type Environment = z.infer<typeof envSchema>;
```

Register it with `ConfigModule.forRoot({ isGlobal: true, validationSchema: envSchema })`. Features must inject typed configuration and must not read `process.env` directly.

- [ ] **Step 4: Implement Pino as Nest's logger**

Create one Pino root logger with redaction for `authorization`, `cookie`, `password`, and `token`. `AppLoggerService` must implement `log`, `error`, `warn`, `debug`, `verbose`, and `fatal`, always attaching `service` and `process_role`.

- [ ] **Step 5: Configure Prisma ORM 7 for PostgreSQL**

```prisma
generator client {
  provider = "prisma-client"
  output   = "../src/generated/prisma"
}

datasource db {
  provider = "postgresql"
}

model ExampleRecord {
  id            String   @id @default(uuid()) @db.Uuid
  name          String
  sourceEventId String?  @unique @map("source_event_id")
  createdAt     DateTime @default(now()) @map("created_at")
  @@map("example_records")
}

model InboxMessage {
  eventId       String   @map("event_id")
  consumerGroup String   @map("consumer_group")
  outputEventId String   @map("output_event_id") @db.Uuid
  processedAt   DateTime @default(now()) @map("processed_at")
  @@id([eventId, consumerGroup])
  @@map("inbox_messages")
}
```

Instantiate the generated `PrismaClient` with `new PrismaPg({ connectionString })`. `PrismaService` connects on module init, disconnects on destroy, and implements `isReady()` using `SELECT 1`.

- [ ] **Step 6: Verify and commit**

Run in a generated fixture: `pnpm prisma:generate && pnpm test && pnpm build`

Expected: generated client succeeds; tests and build exit 0 without connecting to PostgreSQL.

```powershell
git add templates/service
git commit -m "feat: add service configuration logging and Prisma"
```

### Task 5: Add centralized HTTP errors, contracts, and API bootstrap

**Files:**
- Create: `templates/service/src/shared/errors/application-error.ts`
- Create: `templates/service/src/shared/errors/problem-details.filter.ts`
- Create: `templates/service/src/shared/errors/errors.module.ts`
- Create: `templates/service/src/shared/logging/correlation.middleware.ts`
- Create: `templates/service/src/shared/http/configure-http-app.ts`
- Create: `templates/service/src/shared/http/openapi.ts`
- Create: `templates/service/src/shared/http/pagination.schema.ts`
- Test: `templates/service/src/shared/errors/http-errors.feature.spec.ts`
- Modify: `templates/service/src/main-api.ts`
- Modify: `templates/service/src/http-app.module.ts`

**Interfaces:**
- Produces: `ApplicationError`, `ProblemDetailsFilter`, and `configureHttpApp(app, role)`.
- Error kinds map to HTTP: validation 400, not_found 404, conflict 409, business 422, transient 503, unknown 500.

- [ ] **Step 1: Write a failing HTTP error feature test**

```ts
expect(await request(app.getHttpServer()).get('/internal/v1/error/not-found'))
  .toMatchObject({
    status: 404,
    headers: expect.objectContaining({ 'content-type': expect.stringContaining('application/problem+json') }),
    body: expect.objectContaining({ code: 'EXAMPLE_NOT_FOUND', status: 404 }),
  });

expect(await request(app.getHttpServer()).get('/internal/v1/error/unknown'))
  .toMatchObject({ status: 500, body: expect.not.objectContaining({ stack: expect.anything() }) });
```

- [ ] **Step 2: Verify failure**

Run: `pnpm test -- http-errors.feature.spec.ts`

Expected: FAIL because the global filter is absent.

- [ ] **Step 3: Implement typed errors and Problem Details mapping**

```ts
export type ErrorKind = 'validation' | 'not_found' | 'conflict' | 'business' | 'transient';

export class ApplicationError extends Error {
  constructor(
    readonly code: string,
    readonly kind: ErrorKind,
    message: string,
    readonly details?: Readonly<Record<string, unknown>>,
    options?: ErrorOptions,
  ) { super(message, options); }
}
```

The global filter must map known errors, convert Nest `BadRequestException` from Standard Schema into validation Problem Details, attach the request path as `instance`, set `application/problem+json`, log once, and hide unknown causes.

- [ ] **Step 4: Configure NestJS 12 HTTP conventions**

```ts
app.setGlobalPrefix('internal');
app.enableVersioning({ type: VersioningType.URI });
app.useGlobalPipes(new StandardSchemaValidationPipe({ transform: true }));
app.useGlobalFilters(app.get(ProblemDetailsFilter));
app.enableShutdownHooks();
```

Use `routeConflictPolicy: { duplicate: 'error', shadow: 'warn' }` and `routeResolutionStrategy: 'specificity'` when creating the Nest application. Correlation middleware must reuse `x-correlation-id` or generate `crypto.randomUUID()` and return it in the response header.

Provide the shared query contract without creating a generic filtering DSL:

```ts
export const paginationQuerySchema = z.object({
  page: z.coerce.number().int().min(1).default(1),
  per_page: z.coerce.number().int().min(1).max(100).default(20),
});
```

- [ ] **Step 5: Add OpenAPI generation**

Create an `openapi:generate` script that boots the API without listening, builds an OpenAPI 3.1 document from Nest route metadata and Zod schemas, writes `artifacts/openapi.json`, and closes the app. Import `zod-openapi` once so `.meta()` types are available.

- [ ] **Step 6: Verify and commit**

Run: `pnpm test && pnpm build`

Expected: Problem Details tests pass and no response exposes a stack.

```powershell
git add templates/service
git commit -m "feat: centralize HTTP contracts and errors"
```

### Task 6: Add RabbitMQ infrastructure and centralized consumer handling

**Files:**
- Create: `templates/service/src/shared/messaging/message-envelope.schema.ts`
- Create: `templates/service/src/shared/messaging/rabbit-connection.service.ts`
- Create: `templates/service/src/shared/messaging/event-publisher.ts`
- Create: `templates/service/src/shared/messaging/consumer-registry.ts`
- Create: `templates/service/src/shared/messaging/consumer-runner.ts`
- Create: `templates/service/src/shared/messaging/messaging.module.ts`
- Test: `templates/service/src/shared/messaging/consumer-runner.feature.spec.ts`

**Interfaces:**
- Produces: `EventPublisher.publish(routingKey, envelope): Promise<void>`, `ConsumerRunner.run(delivery, handler): Promise<void>`, `ConsumerRegistry.isReady(): boolean`, and `RabbitConnectionService.isReady(): boolean`.
- Consumes: `ApplicationError`; `transient` and unknown failures use bounded retry, while schema-invalid and known non-transient application failures dead-letter; success ACKs.

- [ ] **Step 1: Write failing disposition tests with a fake channel**

```ts
it('acks once after successful handling', async () => {
  await runner.run(delivery, async () => undefined);
  expect(channel.ack).toHaveBeenCalledTimes(1);
  expect(channel.nack).not.toHaveBeenCalled();
});

it('routes transient failures to retry without acking', async () => {
  await runner.run(delivery, async () => { throw new ApplicationError('DB_BUSY', 'transient', 'busy'); });
  expect(retryPublisher.publish).toHaveBeenCalledWith(delivery, 1);
  expect((retryPublisher.publish as jest.Mock).mock.invocationCallOrder[0])
    .toBeLessThan(channel.ack.mock.invocationCallOrder[0]!);
});

it('dead-letters invalid payloads without retry', async () => {
  await runner.run(invalidDelivery, handler);
  expect(deadLetterPublisher.publish).toHaveBeenCalledTimes(1);
  expect(retryPublisher.publish).not.toHaveBeenCalled();
});
```

- [ ] **Step 2: Verify failure**

Run: `pnpm test -- consumer-runner.feature.spec.ts`

Expected: FAIL because messaging providers are absent.

- [ ] **Step 3: Define and validate the versioned envelope**

```ts
export const messageEnvelopeSchema = z.object({
  event_id: z.uuid(),
  event_type: z.string().min(1),
  event_version: z.string().regex(/^v\d+$/),
  occurred_at: z.iso.datetime(),
  correlation_id: z.uuid(),
  payload: z.unknown(),
});
```

Generate `artifacts/schemas/message-envelope.v1.json` with `z.toJSONSchema(messageEnvelopeSchema)`. Keep the existing `event_id` naming used by the messaging ADRs.

- [ ] **Step 4: Implement connection, confirms, and consumer boundary**

Use one connection per process, a confirm channel for publishing, manual ACK, configured prefetch, and explicit close methods. The runner validates before invoking the handler. It publishes to the configured retry or DLQ route and waits for publisher confirmation before ACKing the original. If retry/DLQ publication fails, it must `nack(message, false, true)` so the original is not lost.

- [ ] **Step 5: Verify and commit**

Run: `pnpm test && pnpm build`

Expected: fake-channel tests prove ACK, retry, DLQ, and publish-failure behavior without RabbitMQ.

```powershell
git add templates/service
git commit -m "feat: add RabbitMQ worker infrastructure"
```

### Task 7: Add custom liveness, readiness, and graceful shutdown

**Files:**
- Create: `templates/service/src/shared/health/readiness-indicator.ts`
- Create: `templates/service/src/shared/health/health.service.ts`
- Create: `templates/service/src/shared/health/health.controller.ts`
- Create: `templates/service/src/shared/health/health.module.ts`
- Test: `templates/service/src/shared/health/health.feature.spec.ts`
- Modify: `templates/service/src/http-app.module.ts`
- Modify: `templates/service/src/worker-app.module.ts`
- Modify: `templates/service/src/main-api.ts`
- Modify: `templates/service/src/main-worker.ts`

**Interfaces:**
- Produces: `ReadinessIndicator { name: string; check(): Promise<boolean> }`, `/health/live`, and `/health/ready`.
- API readiness consumes Prisma; worker readiness consumes Prisma, Rabbit connection, and consumer registry.

- [ ] **Step 1: Write failing health feature tests**

```ts
expect(await request(app.getHttpServer()).get('/health/live'))
  .toMatchObject({ status: 200, body: { status: 'ok' } });

expect(await request(app.getHttpServer()).get('/health/ready'))
  .toMatchObject({
    status: 503,
    body: { status: 'error', checks: { database: 'up', rabbitmq: 'down' } },
  });
```

- [ ] **Step 2: Verify failure**

Run: `pnpm test -- health.feature.spec.ts`

Expected: FAIL with 404.

- [ ] **Step 3: Implement small custom indicators**

```ts
export interface ReadinessIndicator {
  readonly name: string;
  check(): Promise<boolean>;
}

export type HealthBody = {
  status: 'ok' | 'error';
  checks?: Record<string, 'up' | 'down'>;
};
```

Liveness must never call PostgreSQL or RabbitMQ. Readiness evaluates all indicators, returns 200 only when all are up, and otherwise throws an application error that the health controller renders as 503 with the check map. Keep these endpoints outside `/internal/v1`.

In the API bootstrap, exclude `health/live` and `health/ready` from the global `internal` prefix and mark both routes with `@Version(VERSION_NEUTRAL)`. The worker bootstrap exposes only those two routes on its administrative port.

- [ ] **Step 4: Wire process-specific readiness and shutdown**

The API listens on `API_PORT`; the worker listens only for health on `WORKER_ADMIN_PORT`. On SIGTERM/SIGINT, stop accepting HTTP, cancel consumers, wait up to `SHUTDOWN_TIMEOUT_MS`, then close RabbitMQ and Prisma. Do not expose business controllers from `WorkerAppModule`.

- [ ] **Step 5: Verify and commit**

Run: `pnpm test && pnpm build`

Expected: liveness/readiness tests pass with fake indicators; no Terminus dependency exists.

```powershell
git add templates/service
git commit -m "feat: add custom service health and shutdown"
```

### Task 8: Add the removable example slice and feature tests

**Files:**
- Create: `templates/service/src/features/example/example.schemas.ts`
- Create: `templates/service/src/features/example/example.service.ts`
- Create: `templates/service/src/features/example/example.module.ts`
- Create: `templates/service/src/features/example/http/example.controller.ts`
- Create: `templates/service/src/features/example/consumers/example-requested.consumer.ts`
- Create: `templates/service/src/features/example/tests/example-http.feature.spec.ts`
- Create: `templates/service/src/features/example/tests/example-consumer.feature.spec.ts`
- Create: `templates/service/src/testing/fake-prisma.service.ts`
- Create: `templates/service/src/testing/fake-event-publisher.ts`
- Modify: `templates/service/src/http-app.module.ts`
- Modify: `templates/service/src/worker-app.module.ts`

**Interfaces:**
- HTTP: `POST /internal/v1/examples` with `{ "name": string }`, response `{ "id": uuid, "name": string, "created_at": ISO datetime }`.
- HTTP: `GET /internal/v1/examples?page=1&per_page=20`, response `{ "data": Example[], "meta": { "page": number, "per_page": number, "total": number } }`.
- Message: consumes `example.requested.v1`, persists idempotently, and publishes `example.created.v1`.

- [ ] **Step 1: Write the HTTP feature tests first**

```ts
expect(await request(app.getHttpServer()).post('/internal/v1/examples').send({ name: 'demo' }))
  .toMatchObject({ status: 201, body: { name: 'demo' } });

expect(await request(app.getHttpServer()).post('/internal/v1/examples').send({ name: '' }))
  .toMatchObject({ status: 400, body: { code: 'REQUEST_VALIDATION_FAILED' } });

expect(await request(app.getHttpServer()).get('/internal/v1/examples?page=1&per_page=101'))
  .toMatchObject({ status: 400, body: { code: 'REQUEST_VALIDATION_FAILED' } });
```

- [ ] **Step 2: Write the consumer feature tests first**

```ts
await consumer.handle(exampleRequestedEnvelope);
await consumer.handle(exampleRequestedEnvelope);

expect(prisma.exampleRecords).toHaveLength(1);
expect(publisher.events).toHaveLength(2);
expect(publisher.events[0]?.routingKey).toBe('example.created.v1');
expect(new Set(publisher.events.map(event => event.envelope.event_id))).toHaveSize(1);
```

Add cases proving transient failure requests retry, malformed payload dead-letters, and successful handling ACKs only after persistence/publication completes.

- [ ] **Step 3: Run and verify failure**

Run: `pnpm test -- example-http.feature.spec.ts example-consumer.feature.spec.ts`

Expected: FAIL because the example slice is absent.

- [ ] **Step 4: Implement Zod schemas and the slice**

```ts
export const createExampleSchema = z.object({ name: z.string().trim().min(1).max(100) });
export const exampleResponseSchema = z.object({
  id: z.uuid(),
  name: z.string(),
  created_at: z.iso.datetime(),
});
```

Attach the request schema with `@Body({ schema: createExampleSchema })`. Attach response serialization with `@UseInterceptors(StandardSchemaSerializerInterceptor)` and `@SerializeOptions({ schema: exampleResponseSchema })`. Keep mapping between Prisma camelCase fields and wire `snake_case` in one presenter function.

- [ ] **Step 5: Implement idempotent consumer behavior**

In one Prisma transaction, insert the Inbox identity with a newly generated `outputEventId` and insert the example effect with `sourceEventId` equal to the input `event_id`. Treat the composite unique violation as an already-processed local effect, then reload the Inbox and ExampleRecord. After either a new or duplicate delivery, reconstruct and publish `example.created.v1` with the persisted `outputEventId`, then ACK after publisher confirmation. This permits duplicate output delivery with the same event identity but never duplicate local effects, matching at-least-once semantics without pretending to provide Outbox guarantees. The fake Prisma implementation must model both uniqueness rules.

- [ ] **Step 6: Verify and commit**

Run: `pnpm test && pnpm build`

Expected: all feature tests pass without Docker.

```powershell
git add templates/service
git commit -m "feat: add removable example feature"
```

### Task 9: Add Docker, documentation, and generated-project acceptance

**Files:**
- Create: `templates/service/Dockerfile`
- Create: `templates/service/README.md`
- Create: `templates/service/scripts/generate-contracts.ts`
- Create: `tests/generated-service.acceptance.spec.ts`
- Create: `tests/helpers/run-command.ts`
- Create: `README.md`
- Modify: `package.json`

**Interfaces:**
- Produces: a generated project that installs, generates Prisma, builds, generates contracts, and passes tests.
- Does not produce or integrate a real `inventory-lab/services/*` directory.

- [ ] **Step 1: Write the failing acceptance test**

```ts
it('creates a complete service that installs, builds, exports contracts, and tests', async () => {
  const output = await generateTemporaryService('acceptance');
  await run('corepack', ['pnpm', 'install', '--lockfile=false'], output);
  await run('corepack', ['pnpm', 'prisma:generate'], output);
  await run('corepack', ['pnpm', 'build'], output);
  await run('corepack', ['pnpm', 'contracts:generate'], output);
  await run('corepack', ['pnpm', 'test'], output);
  expect(await pathExists(join(output, 'artifacts/openapi.json'))).toBe(true);
  expect(await pathExists(join(output, 'artifacts/schemas/message-envelope.v1.json'))).toBe(true);
});
```

Configure this file under `pnpm test:acceptance`, not the fast `pnpm test`, because it performs a fresh package installation.

Implement the subprocess helper with this interface:

```ts
export async function runCommand(command: string, args: string[], cwd: string): Promise<void> {
  await new Promise<void>((resolve, reject) => {
    const child = spawn(command, args, { cwd, stdio: 'inherit', shell: process.platform === 'win32' });
    child.once('error', reject);
    child.once('exit', code => code === 0 ? resolve() : reject(new Error(`${command} exited with ${code}`)));
  });
}
```

Import it as `run` in the acceptance test: `import { runCommand as run } from './helpers/run-command.js';`. Implement `generateTemporaryService` as a named alias of `generateFixture` from Task 2 so every acceptance test uses the production template path.

- [ ] **Step 2: Verify failure**

Run: `pnpm test:acceptance`

Expected: FAIL until Dockerfile, contract scripts, and service documentation exist.

- [ ] **Step 3: Add a single multi-stage Dockerfile**

The build stage installs with pnpm, runs Prisma generation and TypeScript build. The runtime stage contains production dependencies and `dist`; its default command starts the API, while deployment may override it with `node dist/main-worker.js`. Run as a non-root user and expose API and worker-admin ports.

- [ ] **Step 4: Add deterministic contract generation**

`scripts/generate-contracts.ts` must delete and recreate only the local `artifacts/` directory, write pretty-printed JSON with a trailing newline, and sort schema keys before output so repeated generation produces no diff.

- [ ] **Step 5: Document generation and removal of the example**

The generator README must show exactly:

```powershell
pnpm generate --name orders --output 'F:\dev\projects\study\inventory-lab\services'
```

The generated README must explain `pnpm dev`, separate API/worker commands, environment variables, migrations, health URLs, feature-test policy, contract generation, and the exact files/imports removed when replacing `features/example`.

- [ ] **Step 6: Run the release gate**

Run:

```powershell
pnpm test
pnpm build
pnpm test:acceptance
git status --short
```

Expected: all commands pass; status lists only intended documentation changes before commit; the temporary generated project is cleaned up by the test.

- [ ] **Step 7: Commit the completed generator**

```powershell
git add README.md package.json pnpm-lock.yaml templates tests
git commit -m "feat: validate generated NestJS services"
```

### Task 10: Final audit and first stable tag

**Files:**
- Modify: `package.json`
- Modify: `README.md`
- Verify: all repository and template files

**Interfaces:**
- Produces: immutable generator version `v1.0.0` and generated `.template-version` value `1.0.0`.
- Plan 2 consumes this tag to create the first real service.

- [ ] **Step 1: Audit prohibited dependencies and artifacts**

Run:

```powershell
rg -n "@nestjs/terminus" templates/service/package.json
rg -n "fastify|vitest" templates/service
rg -n "orders|stock|payments" templates/service/src
Get-ChildItem -Recurse -Force templates/service | Where-Object Name -In '.git','pnpm-lock.yaml','package-lock.json','bun.lock'
```

Expected: no matches. Mentions in explanatory documentation must use generic wording instead of domain names.

- [ ] **Step 2: Audit template tokens and exact generation**

Generate `audit-service` into a temporary directory and run:

```powershell
rg -n "__[A-Z_]+__" $auditServicePath
Get-ChildItem -Force $auditServicePath | Where-Object Name -In '.git','pnpm-lock.yaml','package-lock.json','bun.lock'
```

Expected: both commands produce no entries.

- [ ] **Step 3: Run all verification from a clean checkout**

Run: `pnpm install --frozen-lockfile && pnpm test && pnpm build && pnpm test:acceptance`

Expected: exit 0 for every command.

- [ ] **Step 4: Set and commit version 1.0.0**

Set the generator package version to `1.0.0`; rerun the generator test that asserts `.template-version` equals `1.0.0`.

```powershell
git add package.json pnpm-lock.yaml README.md
git commit -m "chore: release generator 1.0.0"
git tag -a v1.0.0 -m "NestJS microservice generator v1.0.0"
git status --short
```

Expected: clean status and annotated local tag `v1.0.0`. Pushing the repository or tag is a separate external action and requires explicit authorization.

## Completion Boundary

This plan is complete when the external generator repository has a clean, verified `v1.0.0` tag and can create a generic service in a temporary directory. Do not create `orders-service`, modify the `inventory-lab` pnpm workspace, or implement domain behavior here. Those actions belong respectively to Plan 2 and Plan 3.
