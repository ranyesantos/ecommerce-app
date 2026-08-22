# platform — Design do sistema

**Data:** 2026-08-21
**Status:** decisões atualizadas; etapa 1 definida
**Fonte dos requisitos:** `application.md`
**Decisões consolidadas:** [`docs/ADRs/ADRs.md`](../../ADRs/ADRs.md)

## 1. Contexto e objetivo

O projeto estuda RabbitMQ e arquitetura distribuída sobre um domínio de e-commerce: catálogo, estoque, pedidos e pagamentos simulados.

O sistema começará com uma aplicação web Laravel tradicional, que serve o frontend, recebe as interações do usuário e é dona do catálogo. Pedidos, estoque e pagamentos continuarão sendo domínios de serviços Node independentes, construídos gradualmente. O navegador não acessa esses serviços diretamente.

O objetivo é evoluir por seis etapas, introduzindo comunicação assíncrona, observabilidade, Outbox, novos consumers e uma Saga coreografada sem antecipar a complexidade de microsserviços antes de haver um fluxo que a justifique.

O sistema completo não é um monólito: o Laravel é uma aplicação web modular dentro de uma arquitetura distribuída. Nesta primeira etapa, apenas o catálogo existirá como domínio implementado.

## 2. Decisões consolidadas

| # | Decisão | Escolha | Racional |
|---|---|---|---|
| D1 | Organização do repositório | Monorepo | Mantém contratos, infraestrutura, documentação e serviços do projeto versionados juntos. |
| D2 | Aplicação inicial | `catalog-app/`, Laravel web app tradicional | O frontend começa no Laravel, sem uma API REST pública. |
| D3 | Papel do Laravel | Web app + BFF/ponto inicial + dono do catálogo | Recebe requisições, valida entradas, renderiza páginas e adapta integrações; não duplica as regras dos serviços Node. |
| D4 | Interface inicial | Rotas web, formulários e respostas server-side | O CRUD começa como aplicação web tradicional; uma API pública só será criada se surgir um cliente que a exija. |
| D5 | Organização interna do Laravel | Feature by slice, começando por `app/Features/Catalog` | Mantém cada feature próxima de suas Actions, modelos, requests, controllers e testes sem adotar um pacote de módulos pesado. |
| D6 | Domínio dos serviços | `orders-service`, `stock-service` e `payments-service` em Node | Cada serviço é dono de suas regras, dados e consumers. |
| D7 | Banco de dados | Database-per-service na arquitetura distribuída | O catálogo usa `catalog_db`; pedidos, estoque e pagamentos terão seus próprios bancos quando nascerem. |
| D8 | Acesso do navegador | Sempre através do Laravel | Evita expor topologia interna, autenticação duplicada, CORS e contratos específicos de cada serviço ao frontend. |
| D9 | Disponibilidade no frontend | Quantidade aparece somente em detalhe, carrinho e checkout | Listagens exibem os dados do catálogo sem consultar estoque por produto. |
| D10 | Cache de disponibilidade | Redis/projeção atualizada por eventos, com o `stock-service` como fonte oficial | O frontend não precisa de precisão absoluta; o cache reduz acoplamento e custo de leitura. TTL e política de cache miss serão definidos na implementação. |
| D11 | Entrada de pedido | Laravel publica um comando, não `order.created` | O `orders-service` é quem cria o pedido e publica `order.created`. |
| D12 | Comunicação de negócio | RabbitMQ para comandos e eventos; leituras síncronas somente quando justificadas | O fluxo de processamento não depende de chamadas HTTP entre serviços. O Laravel continua sendo a porta de entrada das leituras do frontend. |
| D13 | Topologia do broker | Exchange topic única `ecommerce.events`, com routing keys por domínio | Mantém a topologia simples e permite adicionar consumers sem alterar producers. |
| D14 | Retry | Escada de filas com TTL + DLX: `retry.5s`, `retry.30s`, `retry.2m` → DLQ | Exercita retry, backoff e DLQ sem plugins adicionais. |
| D15 | Envelope de mensagem | `event_id`, `event_type`, `event_version`, `occurred_at`, `correlation_id` e `payload` | Permite rastreabilidade, idempotência e evolução de contratos. |
| D16 | Dinheiro | Inteiros em centavos (`*_cents`) | Evita erros de ponto flutuante. |
| D17 | Pagamento simulado | Tokens determinísticos: `card-success`, `card-transient-failure`, `card-rejected` | Torna falhas reproduzíveis em testes. |
| D18 | Autenticação | Fora do MVP inicial | `customer_email` identifica o comprador durante o projeto. |
| D19 | Falha permanente de captura | DLQ + CLI manual (`capture:retry` ou `order:compensate`) | Permite intervenção explícita sem esconder falhas permanentes em retries infinitos. |
| D20 | Escala | Laravel e serviços Node podem escalar horizontalmente de forma independente | O Laravel deve ser stateless entre instâncias; estado compartilhado fica em PostgreSQL, Redis, storage externo ou RabbitMQ. |

As decisões detalhadas, consequências e alternativas estão em [`docs/ADRs/ADRs.md`](../../ADRs/ADRs.md).

## 3. Arquitetura inicial e alvo

### 3.1 Componentes

- **`catalog-app` (Laravel)** — aplicação web server-side, ponto inicial da aplicação, dono do catálogo e BFF. Renderiza as telas, valida formulários, consulta o catálogo e, nas etapas posteriores, publica comandos e consulta projeções para compor as respostas do frontend.
- **`orders-service` (Node)** — dono de pedidos e itens; cria o pedido, conduz seu estado e publica eventos de pedido.
- **`stock-service` (Node)** — dono do saldo, reservas, expiração de reservas e saída definitiva de estoque.
- **`payments-service` (Node)** — gateway de pagamento simulado; autoriza, captura e executa void.
- **`packages/contracts`** — envelope e schemas dos eventos compartilhados.
- **`packages/core-node`** — infraestrutura comum dos serviços Node: inbox, ack pós-commit, classificação de erros e publisher de outbox.

O Laravel pode conter adapters/DTOs para os serviços Node, mas não contém as regras de pedidos, estoque ou pagamentos. O sistema não expõe os serviços Node diretamente ao navegador.

### 3.2 Fluxo de leitura do catálogo

Na listagem, o Laravel consulta somente o catálogo:

```text
Navegador → Laravel → catalog_db → página de produtos
```

Na página de detalhe, carrinho ou checkout, o Laravel compõe os dados necessários:

```text
Navegador → Laravel
              ├── catalog_db: título, descrição, preço
              └── Redis: quantidade disponível projetada
```

O `stock-service` continua sendo a autoridade. Reservar ou confirmar estoque nunca depende do valor exibido no cache. Uma ausência no cache e a estratégia de reidratação serão tratadas na implementação, sem chamadas individuais por produto.

### 3.3 Fluxo de pedido

Quando o fluxo de pedidos for introduzido, o frontend continuará enviando um formulário para o Laravel. O Laravel valida a entrada, enriquece o comando com os preços do catálogo e publica um comando como `order.placement.requested`.

O `orders-service` consome o comando, persiste o pedido e publica `order.created`. A partir daí, a coreografia segue pelos eventos de estoque e pagamento:

```text
Frontend → Laravel → order.placement.requested
                         ↓
                   orders-service
                         ↓ order.created
             stock-service / payments-service
```

`order.created` é sempre um evento do `orders-service`, nunca uma afirmação feita pelo Laravel antes da persistência do pedido.

### 3.4 Mensageria

Comunicação: exchange topic `ecommerce.events`, filas por consumer group, prefetch inicialmente igual a 1 e ack somente após commit do banco. Idempotência usa uma tabela `inbox` com `(event_id, consumer_group)`. Não há ordenação global garantida pelo RabbitMQ.

Topologia:

- exchange: `ecommerce.events`;
- filas principais por consumer group, como `stock-service.order-placement-requested`;
- filas de retry por consumer, com TTL e dead-letter para a fila principal;
- DLQ terminal por fila, preservando a mensagem para inspeção e resolução manual;
- erros permanentes seguem diretamente para a DLQ.

Entre a etapa 2 e a etapa 4 existe uma janela de dual-write: commit no banco e publicação no broker não são atômicos. Ack tardio transforma uma possível perda silenciosa em duplicação eventual, tratada pela idempotência. O Outbox da etapa 4 elimina essa janela para os serviços que publicam eventos persistidos.

## 4. Estrutura do monorepo

```text
platform/
├── catalog-app/             # Laravel web app + catálogo + BFF
├── orders-service/          # Node 20, TypeScript, Fastify, amqplib, pg, Vitest
├── stock-service/
├── payments-service/
├── packages/
│   ├── contracts/            # envelope + JSON Schema dos eventos, tipos TS
│   └── core-node/            # runtime comum dos serviços Node
├── infra/docker/             # compose, Dockerfiles, RabbitMQ
├── docs/
│   ├── ADRs/                 # ADRs consolidados
│   ├── artifacts/            # visuais de apoio
│   └── superpowers/{specs,plans}/
└── application.md
```

### 4.1 Feature by slice no Laravel

O primeiro slice é o catálogo de produtos:

```text
catalog-app/
├── app/
│   ├── Features/
│   │   └── Catalog/
│   │       ├── Actions/
│   │       ├── Http/
│   │       │   ├── Controllers/
│   │       │   └── Requests/
│   │       ├── Models/
│   │       ├── Queries/
│   │       └── Providers/
│   └── Shared/
├── resources/views/catalog/
├── routes/web.php
└── tests/
    ├── Feature/Catalog/
    └── Unit/Features/Catalog/
```

A convenção `App\\` já cobre `app/Features`, portanto não é necessário um pacote de módulos. Os generators padrão do Artisan continuam disponíveis, mas alguns `make:*` têm destinos padrão diferentes de uma slice completa; quando necessário, os arquivos serão criados com namespace explícito, movidos para a slice ou atendidos por generators locais simples.

### 4.2 Banco inicial do catálogo

Na etapa 1, o PostgreSQL possui a tabela `products` com UUIDv7, SKU único, nome, descrição opcional, preço em centavos, estado de ativação e soft delete. O modelo completo, suas invariantes e transições estão definidos em [`2026-08-21-catalog-product-model-design.md`](2026-08-21-catalog-product-model-design.md).

Quantidade de estoque não pertence ao banco do catálogo. A etapa 1 não recebe quantidade no formulário; esse fluxo será acrescentado quando o `stock-service` existir.

## 5. Coreografia

### Fluxo feliz (montado ao longo das etapas 2a–2d)

| Passo | Mensagem | Publica | Consome |
|---|---|---|---|
| 1 | `order.placement.requested` | catalog-app/Laravel | orders-service |
| 2 | `order.created` | orders-service | stock-service |
| 3 | `stock.reserved` | stock-service | orders-service |
| 4 | `payment.authorized` | payments-service | orders-service |
| 5 | `order.confirmed` | orders-service | payments-service |
| 6 | `payment.captured` | payments-service | orders-service e stock-service |
| 7 | `stock.confirmed` | stock-service | orders-service |

### Compensações (etapa 6)

- Sem estoque → `stock.unavailable` → pedido rejeitado; pagamento jamais é solicitado.
- Autorização falhou → `payment.declined` → `order.cancelled` → estoque libera a reserva.
- Confirmação falhou após autorização → `order.cancelled` → payments executa void e estoque libera.
- Captura com falha transitória → escada de retry → DLQ → CLI manual (`capture:retry` / `order:compensate`).
- Reserva expirada → `stock.reservation.expired` → `order.cancelled`; o estoque é devolvido.
- Timeout por etapa → scheduler emite o evento de timeout persistido; compensadores tratam a falha sem conhecer a Saga inteira.

## 6. Plano de evolução

### Etapa 1 — Web app Laravel e CRUD do catálogo

**Escopo:** scaffold de `catalog-app`; aplicação web tradicional; feature slice `Catalog`; CRUD de produtos por telas e formulários; validação de SKU único e preço maior que zero; PostgreSQL `catalog_db`; Docker Compose; suite Pest; views server-side. Não há API pública, RabbitMQ, pedidos, estoque ou pagamentos nesta etapa.

**Concluída quando:**

1. Usuário consegue listar, criar, visualizar, editar e remover/desativar produtos pela aplicação web.
2. Validações impedem SKU duplicado e preço inválido.
3. Testes Pest cobrem o fluxo HTTP e as regras do catálogo.
4. `docker compose up` do zero sobe `catalog-app` e `catalog_db`.
5. O código do CRUD permanece dentro da slice `Catalog`.

### Etapa 2 — RabbitMQ e serviços nascem um por um

**Escopo em subetapas:**

- **2a — Broker no ar:** RabbitMQ no compose com exchange, filas, retry TTL+DLX, DLQ e Management UI; nasce `packages/core-node`.
- **2b — `orders-service`:** o Laravel recebe a ação web de criar pedido, valida e publica `order.placement.requested`; o serviço persiste o pedido e publica `order.created`.
- **2c — `stock-service`:** consome `order.created`, reserva com `UPDATE` condicional atômico e publica `stock.reserved` ou `stock.unavailable`.
- **2d — `payments-service` + fechamento:** consome `stock.reserved`, autoriza com tokens determinísticos e fecha o ciclo com captura e saída definitiva de estoque.

**Concluída quando:**

1. O fluxo do pedido iniciado pela aplicação web percorre os serviços por mensagens.
2. Reentrega artificial da mesma mensagem não produz dupla reserva.
3. Consumer interrompido antes do ack é reentregue e processado.
4. Falha transitória percorre a escada de retry e cai na DLQ.
5. Falha permanente vai direto à DLQ.
6. Testes de consumer estão verdes nos três serviços Node.
7. ADRs de broker, retry e dual-write estão registradas.

### Etapa 3 — Observabilidade

**Escopo:** `correlation_id` gerado na entrada web e propagado no envelope; logs JSON estruturados (Laravel + pino); métricas Prometheus com Grafana; tracing OpenTelemetry com Jaeger.

**Concluída quando:**

1. Um `correlation_id` da requisição aparece nos logs de todos os serviços envolvidos.
2. Existe trace ponta a ponta de um pedido no Jaeger.
3. Há dashboard com lag, throughput, retries e profundidade das DLQs.
4. A stack de observabilidade está registrada em ADR.

### Etapa 4 — Outbox Pattern

**Escopo:** `outbox_events` por serviço, escrito na mesma transação do negócio; publisher em `core-node` com `FOR UPDATE SKIP LOCKED`; `published_at` após confirmação do broker. A publicação inicial do Laravel pode permanecer fail-fast até que haja uma necessidade de outbox próprio para comandos de entrada.

**Concluída quando:**

1. Broker derrubado durante o processamento não perde o evento persistido.
2. Dois publishers concorrentes não publicam o mesmo registro simultaneamente.
3. Não há escrita dual fora de transação nos serviços abrangidos.
4. A ADR do Outbox registra a substituição da mitigação temporária da etapa 2.

### Etapa 5 — Coreografia ampliada

**Escopo:** consumers independentes de notificação e analytics; `event_version` com v1/v2 coexistindo; contratos JSON Schema validados nos extremos.

**Concluída quando:**

1. Consumer novo é subscrito sem mudança nos producers.
2. Eventos v1 e v2 coexistem em teste.
3. Contrato inválido é rejeitado com ack e log, sem retry infinito.
4. ADR de versionamento de eventos está registrada.

### Etapa 6 — Saga coreografada

**Escopo:** compensações completas; scheduler de expiração de reservas; timeouts por etapa; CLI de resolução da DLQ (`capture:retry` / `order:compensate`).

**Concluída quando existe um teste automatizado para cada cenário:**

1. Sem estoque → pedido cancelado, pagamento jamais solicitado.
2. Auth falhou → estoque liberado e pedido cancelado.
3. Confirmação falhou pós-autorização → void, estoque liberado e pedido cancelado.
4. Captura transitória → retries, DLQ e resolução manual nas duas direções.
5. Reserva expirada → estoque devolvido e pedido cancelado.
6. Timeout de confirmação → compensação completa.
7. Replay em massa das mensagens de um pedido → estado final inalterado.

## 7. Requisitos transversais

- Consumers são idempotentes; toda mensagem usa o envelope D15.
- Assume-se at-least-once delivery, nunca exactly-once.
- Erros recuperáveis e permanentes são classificados de forma diferente.
- Workers ficam separados das regras de negócio.
- O navegador fala somente com o Laravel.
- O `stock-service` é a fonte oficial da quantidade; o Redis é uma projeção de leitura.
- Cada etapa permanece pequena e suas decisões são registradas em ADRs.
- Testes cobrem regras de negócio, publicação, idempotência e compensações.

## 8. Fora de escopo inicial

API REST pública, aplicativo mobile, autenticação/autorização completa, gateway de pagamento real, deploy de produção, Kubernetes, multi-região, catálogo com imagens e busca avançada.

## 9. Artefato visual

O fluxo da etapa 1 e a arquitetura alvo da etapa 2 estão ilustrados em [`docs/artifacts/etapa-1-api-laravel.html`](../../artifacts/etapa-1-api-laravel.html). O nome do arquivo é legado; o conteúdo foi atualizado para representar o web app Laravel.
