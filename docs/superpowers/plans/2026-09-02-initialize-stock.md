# Initialize Stock Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (- [ ]) syntax for tracking.

**Goal:** Entregar a inicialização de estoque desde o cadastro do Produto até a projeção confirmada ou rejeitada no Catálogo.

**Architecture:** Laravel persiste Produto, projeção pendente e comando na mesma transação. Stock processa o comando uma vez, atualiza Item/Ledger e grava um evento de resultado em sua Outbox; Laravel consome esse evento e atualiza sua projeção local.

**Tech Stack:** Laravel 13.17/Pest 5, Node.js 24.15+, NestJS 12, Prisma/PostgreSQL, Zod, RabbitMQ/amqplib e pnpm 11.

**Spec:** docs/superpowers/specs/2026-09-02-stock-initialization-flow-design.md

## Global Constraints

- Executar primeiro os planos 2026-09-02-stock-service-foundation.md e 2026-09-02-catalog-stock-messaging-foundation.md.
- initial_quantity é obrigatória, inteira e está entre 0 e 2.147.483.647.
- Não publicar nem consumir catalog.product.created neste incremento.
- Não colocar regras do Ledger em shared.
- Não criar trigger PostgreSQL, Testcontainers, smoke test ou E2E com infraestrutura real.
- Todo efeito local do consumer, Inbox e Outbox participa da mesma transação.

---

### Task 1: Contratos versionados e JSON Schemas

**Files:**
- Create: packages/contracts/package.json
- Create: packages/contracts/src/message-envelope.ts
- Create: packages/contracts/src/stock/initialize-stock.ts
- Create: packages/contracts/src/stock/stock-item-initialized.ts
- Create: packages/contracts/src/stock/stock-item-initialization-rejected.ts
- Create: packages/contracts/schemas/stock.item.initialize.v1.json
- Create: packages/contracts/schemas/stock.item.initialized.v1.json
- Create: packages/contracts/schemas/stock.item.initialization_rejected.v1.json
- Create: packages/contracts/src/stock/tests/initialize-stock.spec.ts

**Interfaces:**
- Produces: Zod schemas e tipos InitializeStockCommandV1, StockItemInitializedV1 e StockItemInitializationRejectedV1; JSON Schemas independentes de linguagem.

- [ ] **Step 1: Escrever testes dos três envelopes**

~~~ts
expect(initializeStockCommandV1Schema.parse(validCommand).payload.initial_quantity).toBe(10);
expect(() => initializeStockCommandV1Schema.parse(commandWithNegativeQuantity)).toThrow();
expect(() => initializeStockCommandV1Schema.parse(commandAboveInt32)).toThrow();
~~~

- [ ] **Step 2: Executar e verificar falha**

Run: corepack pnpm --filter @ecommerce/contracts test -- --runInBand
Expected: FAIL porque o pacote ainda não existe.

- [ ] **Step 3: Implementar schemas e geração reproduzível**

Use UUID, timestamps UTC, literals exatos de tipo/versão/produtor e strict objects. Gere os três arquivos JSON por z.toJSONSchema(), sem copiar TypeScript para o Laravel.

- [ ] **Step 4: Testar e verificar artefatos**

Run: corepack pnpm --filter @ecommerce/contracts test -- --runInBand
Expected: PASS.

Run: corepack pnpm --filter @ecommerce/contracts build
Expected: PASS.

- [ ] **Step 5: Commit**

~~~bash
git add packages/contracts pnpm-lock.yaml
git commit -m "feat(contracts): define stock initialization messages"
~~~

### Task 2: Persistência do Item e Ledger

**Files:**
- Modify: stock-service/prisma/schema.prisma
- Create: stock-service/prisma/migrations/202609020002_create_stock_ledger/migration.sql
- Create: stock-service/src/features/initialize-stock/stock-ledger.ts
- Create: stock-service/src/features/initialize-stock/tests/stock-ledger.spec.ts

**Interfaces:**
- Produces: models StockItem, StockTransaction e StockEntry; enums StockTransactionType, StockAccount e StockDirection; buildInitializationEntries(quantity).

- [ ] **Step 1: Escrever testes do construtor de lançamentos**

~~~ts
expect(buildInitializationEntries(0)).toEqual([]);
expect(buildInitializationEntries(10)).toEqual([
  { account: 'RECEIVING', direction: 'DECREASE', quantity: 10 },
  { account: 'AVAILABLE', direction: 'INCREASE', quantity: 10 },
]);
~~~

- [ ] **Step 2: Executar e verificar falha**

Run: corepack pnpm --filter stock-service test -- stock-ledger.spec.ts --runInBand
Expected: FAIL porque o construtor não existe.

- [ ] **Step 3: Implementar schema e construtor**

StockItem usa id UUIDv7, productId único, availableQuantity/reservedQuantity int não negativos e version positiva. Entries usam quantity positiva e direction explícita; não criar operações de update/delete do Ledger.

- [ ] **Step 4: Gerar Prisma, testar e compilar**

Run: corepack pnpm --filter stock-service prisma:generate
Expected: PASS sem conectar ao banco.

Run: corepack pnpm --filter stock-service test -- stock-ledger.spec.ts --runInBand
Expected: PASS.

Run: corepack pnpm --filter stock-service build
Expected: PASS.

- [ ] **Step 5: Commit**

~~~bash
git add stock-service/prisma stock-service/src/features/initialize-stock
git commit -m "feat(stock): model stock items and ledger"
~~~

### Task 3: Caso de uso initialize-stock

**Files:**
- Create: stock-service/src/features/initialize-stock/initialize-stock.service.ts
- Create: stock-service/src/features/initialize-stock/initialize-stock.module.ts
- Create: stock-service/src/features/initialize-stock/tests/initialize-stock.service.spec.ts

**Interfaces:**
- Consumes: InitializeStockCommandV1 e client Prisma transacional.
- Produces: InitializeStockResult, discriminado entre initialized e rejected.

- [ ] **Step 1: Escrever testes de comportamento**

Cubra quantidade zero sem Ledger; quantidade positiva com duas entries balanceadas; versão inicial 1; reserved zero; outro command_id para o mesmo product_id retorna ITEM_ALREADY_INITIALIZED; falha intermediária faz rollback no fake transacional.

- [ ] **Step 2: Executar e verificar falha**

Run: corepack pnpm --filter stock-service test -- initialize-stock.service.spec.ts --runInBand
Expected: FAIL porque o serviço não existe.

- [ ] **Step 3: Implementar o caso de uso mínimo**

Valide limites antes de escrever. Faça uma única transação que cria Inbox, Item, Ledger quando necessário e evento na Outbox. Capture somente o conflito unique de product_id; outros erros permanecem transitórios ou desconhecidos.

- [ ] **Step 4: Testar e compilar**

Run: corepack pnpm --filter stock-service test -- initialize-stock.service.spec.ts --runInBand
Expected: PASS.

Run: corepack pnpm --filter stock-service build
Expected: PASS.

- [ ] **Step 5: Commit**

~~~bash
git add stock-service/src/features/initialize-stock
git commit -m "feat(stock): initialize stock items transactionally"
~~~

### Task 4: Consumer e eventos de resultado do Stock

**Files:**
- Create: stock-service/src/features/initialize-stock/consumers/initialize-stock.consumer.ts
- Create: stock-service/src/features/initialize-stock/schemas/initialize-stock.schema.ts
- Create: stock-service/src/features/initialize-stock/stock-result-event.factory.ts
- Create: stock-service/src/features/initialize-stock/tests/initialize-stock.consumer.spec.ts
- Modify: stock-service/src/worker-app.module.ts

**Interfaces:**
- Consumes: stock.item.initialize v1 na fila stock.commands.
- Produces: stock.item.initialized v1 ou stock.item.initialization_rejected v1 na Outbox; decisão ACK/retry/DLQ pelo runner compartilhado.

- [ ] **Step 1: Escrever testes do adapter**

Cubra mensagem válida, duplicata idêntica, MESSAGE_PAYLOAD_MISMATCH, schema inválido, sucesso e conflito. Verifique event_id novo, correlation_id preservado e causation_id igual ao command_id.

- [ ] **Step 2: Executar e verificar falha**

Run: corepack pnpm --filter stock-service test -- initialize-stock.consumer.spec.ts --runInBand
Expected: FAIL porque consumer e factory não existem.

- [ ] **Step 3: Implementar adapter fino**

O consumer somente parseia Zod, mapeia snake_case para camelCase e chama InitializeStockService. Não repetir try/catch nem lógica de retry da infraestrutura.

- [ ] **Step 4: Testar a slice**

Run: corepack pnpm --filter stock-service test -- initialize-stock --runInBand
Expected: PASS.

Run: corepack pnpm --filter stock-service build
Expected: PASS.

- [ ] **Step 5: Commit**

~~~bash
git add stock-service/src/features/initialize-stock stock-service/src/worker-app.module.ts
git commit -m "feat(stock): consume initialization commands"
~~~

### Task 5: Projeção stock_availability no Catálogo

**Files:**
- Create: platform/database/migrations/2026_09_02_000300_create_stock_availability_table.php
- Create: platform/app/Features/Catalog/Models/StockAvailability.php
- Create: platform/database/factories/StockAvailabilityFactory.php
- Modify: platform/app/Features/Catalog/Models/Product.php
- Create: platform/tests/Feature/Catalog/StockAvailabilityPersistenceTest.php

**Interfaces:**
- Produces: projeção local com PENDING, SYNCED e FAILED; relação Product::stockAvailability().

- [ ] **Step 1: Gerar model, migration, factory e teste**

~~~bash
php artisan make:model Features/Catalog/Models/StockAvailability --factory --no-interaction
php artisan make:migration create_stock_availability_table --no-interaction
php artisan make:test --pest Catalog/StockAvailabilityPersistenceTest --no-interaction
~~~

Mova o model gerado de `app/Models/Features/Catalog/Models` para `app/Features/Catalog/Models/StockAvailability.php`, confirme que ambos os caminhos resolvidos permanecem dentro de `platform/` e então ajuste namespace e factory.

- [ ] **Step 2: Escrever testes da projeção inicial**

Comprove defaults PENDING, quantidade/versão zero, FK local para products e casts de timestamps e status.

- [ ] **Step 3: Executar e verificar falha**

Run: php artisan test --compact tests/Feature/Catalog/StockAvailabilityPersistenceTest.php
Expected: FAIL antes de completar migration e model.

- [ ] **Step 4: Implementar persistência**

Use available_quantity e stock_version inteiros não negativos, failure_code/last_event_id/synced_at nulos e enum PHP StockSyncStatus com cases TitleCase mapeados para valores wire uppercase.

- [ ] **Step 5: Testar, formatar e commit**

Run: php artisan test --compact tests/Feature/Catalog/StockAvailabilityPersistenceTest.php
Expected: PASS.

Run: vendor/bin/pint --dirty --format agent
Expected: exit 0.

~~~bash
git add platform/app/Features/Catalog platform/database platform/tests/Feature/Catalog
git commit -m "feat(catalog): persist stock availability projection"
~~~

### Task 6: Cadastro publica stock.item.initialize pela Outbox

**Files:**
- Create: platform/app/Features/Catalog/Messages/InitializeStock.php
- Modify: platform/app/Features/Catalog/Http/Requests/StoreProductRequest.php
- Modify: platform/app/Features/Catalog/Actions/CreateProduct.php
- Modify: platform/app/Features/Catalog/Http/Controllers/ProductController.php
- Modify: platform/resources/views/catalog/products/create.blade.php
- Modify: platform/resources/views/catalog/products/_form.blade.php
- Delete: platform/app/Features/Catalog/Events/ProductCreated.php
- Delete: platform/tests/Unit/Features/Catalog/Events/ProductCreatedTest.php
- Delete: platform/tests/Feature/Catalog/ProductCreatedAfterCommitTest.php
- Delete: platform/tests/Feature/Catalog/ProductCreatedPublicationTest.php
- Create: platform/tests/Feature/Catalog/ProductStockInitializationTest.php

**Interfaces:**
- Produces: uma transação que cria Product, StockAvailability PENDING e InitializeStock na Outbox; resposta HTTP não aguarda Stock.

- [ ] **Step 1: Solicitar aprovação específica antes de remover testes antigos**

Liste os três testes de catalog.product.created e confirme que serão substituídos por ProductStockInitializationTest. Não execute remoção sem essa aprovação, conforme platform/AGENTS.md.

- [ ] **Step 2: Escrever testes do novo cadastro**

Cubra initial_quantity obrigatória, zero aceito, negativo/fracionário/acima de int32 rejeitados; Produto, projeção e Outbox criados juntos; falha de qualquer escrita reverte tudo; body contém o contrato exato e não possui identidade de usuário.

- [ ] **Step 3: Executar e verificar falha**

Run: php artisan test --compact tests/Feature/Catalog/ProductStockInitializationTest.php
Expected: FAIL porque o formulário e action ainda não suportam quantidade.

- [ ] **Step 4: Implementar formulário, mensagem e transação**

CreateProduct não publica diretamente. Ele usa OutboxRecorder dentro da mesma DB::transaction e responde com sucesso após o commit local.

- [ ] **Step 5: Remover publicação órfã aprovada, testar e formatar**

Run: php artisan test --compact tests/Feature/Catalog/ProductStockInitializationTest.php tests/Feature/Catalog/ProductCrudTest.php
Expected: PASS.

Run: vendor/bin/pint --dirty --format agent
Expected: exit 0.

- [ ] **Step 6: Commit**

~~~bash
git add platform
git commit -m "feat(catalog): request stock initialization on product creation"
~~~

### Task 7: Catálogo consome os resultados do Stock

**Files:**
- Create: platform/app/Features/Catalog/Messaging/StockInitializationResultHandler.php
- Create: platform/app/Features/Catalog/Messaging/StockResultSchemas.php
- Create: platform/tests/Feature/Catalog/StockInitializationResultHandlerTest.php
- Modify: platform/app/Providers/AppServiceProvider.php

**Interfaces:**
- Consumes: stock.item.initialized v1 e stock.item.initialization_rejected v1.
- Produces: projeção SYNCED ou FAILED na mesma transação da Inbox.

- [ ] **Step 1: Escrever testes de atualização versionada**

Cubra sucesso para versão 1; duplicata sem efeito; versão menor ignorada; rejeição com ITEM_ALREADY_INITIALIZED; evento desconhecido/estrutura inválida enviado à classificação permanente.

- [ ] **Step 2: Executar e verificar falha**

Run: php artisan test --compact tests/Feature/Catalog/StockInitializationResultHandlerTest.php
Expected: FAIL porque o handler não existe.

- [ ] **Step 3: Implementar handler e registro**

Sucesso limpa failure_code, grava available_quantity, stock_version, last_event_id e synced_at. Rejeição grava FAILED e failure_code. O handler não envia ACK diretamente.

- [ ] **Step 4: Testar, formatar e commit**

Run: php artisan test --compact tests/Feature/Catalog/StockInitializationResultHandlerTest.php
Expected: PASS.

Run: vendor/bin/pint --dirty --format agent
Expected: exit 0.

~~~bash
git add platform/app/Features/Catalog platform/app/Providers platform/tests/Feature/Catalog
git commit -m "feat(catalog): project stock initialization results"
~~~

### Task 8: Exibir sincronização e fechar quality gates

**Files:**
- Modify: platform/resources/views/catalog/products/show.blade.php
- Modify: platform/resources/views/catalog/products/index.blade.php somente se ela já expuser ações de estoque
- Modify: platform/README.md
- Modify: stock-service/README.md
- Modify: docs/ADRs/services/ADRs.md
- Create: platform/tests/Feature/Catalog/ProductStockStatusTest.php

**Interfaces:**
- Produces: status PENDING/SYNCED/FAILED visível; quantidade exibida apenas quando confirmada; regra documentada de bloquear Receive/Adjust até SYNCED.

- [ ] **Step 1: Escrever teste HTTP dos três estados**

Comprove que PENDING não exibe a quantidade solicitada como confirmada, SYNCED exibe available_quantity e FAILED apresenta mensagem derivada do código estável.

- [ ] **Step 2: Executar e verificar falha**

Run: php artisan test --compact tests/Feature/Catalog/ProductStockStatusTest.php
Expected: FAIL antes da view.

- [ ] **Step 3: Implementar apresentação e documentação**

Não criar botão ou endpoint Receive/Adjust neste plano. Registre que essas slices devem chamar a guarda STOCK_ITEM_NOT_READY quando forem implementadas.

- [ ] **Step 4: Executar quality gates**

Run: corepack pnpm --filter @ecommerce/contracts test -- --runInBand
Expected: PASS.

Run: corepack pnpm --filter stock-service test -- --runInBand
Expected: PASS sem Docker.

Run: corepack pnpm --filter stock-service build
Expected: PASS.

Run: php artisan test --compact tests/Feature/Catalog tests/Unit/Messaging
Expected: PASS.

Run: vendor/bin/pint --dirty --format agent
Expected: exit 0.

- [ ] **Step 5: Commit**

~~~bash
git add platform stock-service docs/ADRs/services/ADRs.md
git commit -m "docs(stock): complete initialization workflow"
~~~
