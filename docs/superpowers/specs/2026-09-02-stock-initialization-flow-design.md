# Fundação do stock-service e fluxo de inicialização

**Data:** 2026-09-02  
**Status:** desenho aprovado  
**Escopo:** fundação compartilhada, integração confiável Catálogo–Stock e primeira slice `initialize-stock`

## 1. Objetivo

Construir primeiro a infraestrutura reutilizada pelas slices do `stock-service` e, depois, entregar a inicialização de um Item de Estoque de ponta a ponta. O cadastro do Produto continua sendo uma única ação para o usuário, mas a gravação do Catálogo e a alteração do Estoque respeitam bancos e responsabilidades separados.

## 2. Sequência de entrega

A implementação será dividida em três planos, nesta ordem:

1. fundação compartilhada do `stock-service`;
2. fundação de mensageria do Catálogo e topologia RabbitMQ;
3. slice `initialize-stock` de ponta a ponta.

Cada tarefa produz um resultado verificável, mantém build e testes verdes e pode ser revisada em um commit independente. Ledger e projeções não pertencem a `shared`; nascem dentro da primeira feature e só serão extraídos quando outra slice demonstrar reutilização concreta.

## 3. Fluxo aprovado

O formulário de criação exige `initial_quantity`, inteira entre `0` e `2.147.483.647`. Na mesma transação PostgreSQL, o Laravel:

1. cria o Produto;
2. cria `stock_availability` em `PENDING`, com quantidade e versão zero;
3. grava o comando `stock.item.initialize` em `outbox_messages`.

O dispatcher do Laravel publica o comando em `ecommerce.commands`. O Stock consome pela fila `stock.commands`, valida o envelope, aplica idempotência com `inbox_messages` e executa a inicialização em uma transação local.

No sucesso, Stock cria o Item na versão `1`, grava o Ledger quando a quantidade for positiva e adiciona `stock.item.initialized` à sua Outbox. Em conflito por Item já existente, grava a Inbox e `stock.item.initialization_rejected`; falhas transitórias fazem rollback e entram na escada de retry; mensagens inválidas vão à DLQ.

O Catálogo consome os resultados pela fila `catalog.stock-events`. Sucesso muda a projeção para `SYNCED`; rejeição definitiva muda para `FAILED`. Recebimento e Ajuste ficam bloqueados na interface e no backend enquanto a projeção não estiver `SYNCED`.

`catalog.product.created` não será publicado neste incremento. Ele não possui consumidor concreto, e a quantidade inicial é uma instrução destinada ao Estoque, não um atributo do fato de Catálogo.

## 4. Convenção de mensagens

O primeiro segmento da routing key identifica o contexto dono da capacidade ou do fato. Exchange e tempo verbal distinguem comando de evento:

- `ecommerce.commands` + `stock.item.initialize`: instrução destinada ao Stock;
- `ecommerce.events` + `stock.item.initialized`: fato ocorrido no Stock;
- `ecommerce.events` + `stock.item.initialization_rejected`: recusa definitiva ocorrida no Stock.

### 4.1 Comando de inicialização v1

```json
{
  "command_id": "019c0000-0000-7000-8000-000000000001",
  "command_type": "stock.item.initialize",
  "command_version": 1,
  "producer": "catalog",
  "sent_at": "2026-09-02T22:10:00.000Z",
  "correlation_id": "019c0000-0000-7000-8000-000000000002",
  "payload": {
    "product_id": "019c0000-0000-7000-8000-000000000003",
    "initial_quantity": 10
  }
}
```

Não há identidade de usuário na versão 1 porque o projeto ainda não possui login. No AMQP, `message_id` repete `command_id`, `type` repete `command_type` e a mensagem usa JSON UTF-8, persistência e publisher confirm.

### 4.2 Sucesso v1

```json
{
  "event_id": "019c0000-0000-7000-8000-000000000004",
  "event_type": "stock.item.initialized",
  "event_version": 1,
  "producer": "stock",
  "occurred_at": "2026-09-02T22:10:01.000Z",
  "correlation_id": "019c0000-0000-7000-8000-000000000002",
  "causation_id": "019c0000-0000-7000-8000-000000000001",
  "payload": {
    "product_id": "019c0000-0000-7000-8000-000000000003",
    "available_quantity": 10,
    "stock_version": 1
  }
}
```

### 4.3 Rejeição v1

```json
{
  "event_id": "019c0000-0000-7000-8000-000000000005",
  "event_type": "stock.item.initialization_rejected",
  "event_version": 1,
  "producer": "stock",
  "occurred_at": "2026-09-02T22:10:01.000Z",
  "correlation_id": "019c0000-0000-7000-8000-000000000002",
  "causation_id": "019c0000-0000-7000-8000-000000000001",
  "payload": {
    "product_id": "019c0000-0000-7000-8000-000000000003",
    "code": "ITEM_ALREADY_INITIALIZED"
  }
}
```

Mensagens incompatíveis com o schema não produzem evento de rejeição, pois podem não conter dados confiáveis; seguem diretamente para a DLQ.

## 5. Entrega, idempotência e falhas

A entrega é at-least-once. O consumer só envia ACK depois do commit dos efeitos locais. Para uma inicialização bem-sucedida, uma única transação do `stock_db` grava Inbox, Item, Ledger, projeção operacional e Outbox.

`inbox_messages` usa unicidade por `(message_id, consumer_name)` e armazena tipo, versão, hash canônico do conteúdo e instante de processamento. A mesma identidade com o mesmo conteúdo é reentrega idempotente. A mesma identidade com conteúdo diferente produz `MESSAGE_PAYLOAD_MISMATCH` e vai para DLQ.

Cada serviço mantém suas próprias `inbox_messages` e `outbox_messages`. A Outbox armazena a mensagem completa e imutável, incluindo identidade, exchange, routing key, tipo, versão, body, headers, criação, próxima tentativa, publicação, número de tentativas e último erro. O dispatcher não reconstrói mensagens consultando estado atual.

Falhas transitórias percorrem retries de `5s`, `30s` e `2m`; depois seguem para a DLQ. Falhas permanentes de schema vão diretamente para a DLQ. Inbox e Outbox não possuem limpeza automática neste incremento, e a DLQ não possui replay automático.

## 6. Topologia e permissões

`infra/rabbitmq/definitions.json` é a única fonte de verdade da topologia. Aplicações não recebem permissão de `configure`.

Recursos necessários:

- `ecommerce.commands`, topic;
- `ecommerce.events`, topic;
- `ecommerce.retries`, direct;
- `ecommerce.dead-letter`, direct;
- `stock.commands` e seus três retries e DLQ;
- `catalog.stock-events` e seus três retries e DLQ;
- bindings explícitos para o comando e os dois resultados.

O usuário `catalog` publica comandos e lê somente a fila de resultados. O usuário `stock` lê somente a fila de comandos e publica eventos. Credenciais permanecem fora do Git.

## 7. Persistência do Stock

### 7.1 Estado operacional

`stock_items` contém:

- `id` UUIDv7 como identidade interna;
- `product_id` UUIDv7 único, sem FK externa;
- `available_quantity` inteiro não negativo;
- `reserved_quantity` inteiro não negativo, iniciado em zero;
- `version` inteira positiva, iniciada em `1`;
- timestamps.

Quantidade Total é derivada de Disponível mais Reservado e não é persistida separadamente. Cada alteração efetiva de saldo incrementará a versão do Item; a versão é por Item, não global.

### 7.2 Ledger

`stock_transactions` contém identidade UUIDv7, tipo, `source_message_id`, `correlation_id` e criação. `stock_entries` pertence a uma Transação e a um Item, identifica uma Conta, usa direção `INCREASE` ou `DECREASE`, quantidade inteira estritamente positiva e criação.

As contas fixas são `RECEIVING`, `AVAILABLE`, `RESERVED`, `CONFIRMED_OUTFLOW`, `ADJUSTMENT_GAIN` e `ADJUSTMENT_LOSS`. A inicialização positiva diminui `RECEIVING` e aumenta `AVAILABLE` pela mesma quantidade. Inicialização zero não cria Transação nem Lançamentos.

A aplicação garante imutabilidade e balanceamento; não haverá trigger PostgreSQL neste incremento. Não existem operações de edição ou exclusão do Ledger. Correções futuras criam novas Transações.

## 8. Projeção no Catálogo

`stock_availability` usa `product_id` como PK e FK local para `products`, além de:

- `available_quantity`, inteira não negativa e inicialmente zero;
- `stock_version`, inteira não negativa e inicialmente zero;
- `sync_status`: `PENDING`, `SYNCED` ou `FAILED`;
- `failure_code` opcional;
- `last_event_id` opcional;
- `synced_at` opcional;
- timestamps.

O consumer aplica apenas `stock_version` maior que a atual. `event_id` trata a duplicação da mesma mensagem; `stock_version` impede que eventos diferentes e atrasados sobrescrevam estado mais novo.

## 9. Fundação do serviço

O `stock-service` segue Node.js 24.15+, TypeScript strict/NodeNext, NestJS 12/Express, Prisma/PostgreSQL, Zod, `amqplib`, Jest/Supertest, pnpm workspace e Pino. API e worker possuem entrypoints e módulos separados. A API expõe apenas health neste recorte; o worker expõe health administrativo e o consumer.

`shared` contém somente config, database, errors, health, logging e mecanismos genéricos de messaging. O código contábil e a regra de inicialização permanecem na slice `initialize-stock`.

## 10. Testes e verificação

A suíte inicial roda sem Docker:

- módulo Nest real;
- Prisma fake em memória;
- canal, consumer e publisher RabbitMQ fakes;
- Pest no Laravel seguindo os padrões existentes;
- sucesso, quantidade zero, limites, balanceamento, conflito, duplicidade, payload mismatch, ACK, retry, DLQ, Outbox e projeção fora de ordem.

Não haverá Testcontainers, integração real, E2E automatizado nem smoke test via Compose neste incremento. Triggers de banco também não serão criadas sem cobertura de infraestrutura real.

## 11. Fora do escopo

- Reservas, Pedidos e Pagamentos;
- implementação de `receive-stock` e `adjust-stock` nesta entrega;
- login ou identidade humana no envelope;
- publicação de `catalog.product.created`;
- consulta HTTP de negócio no Stock;
- limpeza automática de Inbox/Outbox;
- replay automático de DLQ;
- infraestrutura de testes real;
- observabilidade avançada, HA do RabbitMQ, Kubernetes, Nx ou Turborepo.
