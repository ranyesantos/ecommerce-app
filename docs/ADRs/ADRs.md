# ADRs — platform

Este arquivo consolida as decisões arquiteturais tomadas para o projeto. Cada seção registra uma decisão pequena e independente; alterações futuras devem atualizar o status e documentar a substituição.

## Índice

| ID | Decisão | Status |
|---|---|---|
| ADR-001 | Monorepo | Aceita |
| ADR-002 | Laravel como web app, BFF e ponto inicial | Aceita |
| ADR-003 | Sem API pública no primeiro slice | Aceita |
| ADR-004 | Feature by slice no Laravel | Aceita |
| ADR-005 | Fronteiras dos domínios | Aceita |
| ADR-006 | Database-per-service | Aceita |
| ADR-007 | Navegador acessa somente o Laravel | Aceita |
| ADR-008 | Quantidade não aparece na listagem | Aceita |
| ADR-009 | Disponibilidade em cache/projeção | Aceita |
| ADR-010 | Comandos e eventos de pedido | Aceita |
| ADR-011 | RabbitMQ como comunicação de negócio | Aceita |
| ADR-012 | Topologia de exchange e filas | Aceita |
| ADR-013 | Retry, DLQ e idempotência | Aceita |
| ADR-014 | Envelope versionado de mensagens | Aceita |
| ADR-015 | Valores monetários em centavos | Aceita |
| ADR-016 | Falhas determinísticas de pagamento | Aceita |
| ADR-017 | Escala independente | Aceita |
| ADR-018 | Outbox em etapas | Aceita |
| ADR-019 | Modelo inicial do produto | Aceita |

## ADR-001 — Monorepo

**Contexto:** o projeto terá uma aplicação Laravel, três serviços Node, pacotes compartilhados, infraestrutura e documentação.

**Decisão:** manter tudo em um monorepo `platform/`.

**Consequências:** contratos e compose ficam próximos dos consumidores; builds e deploys podem ser independentes dentro do mesmo repositório; a raiz Git precisa ser o próprio projeto para evitar misturar seus arquivos com projetos irmãos.

## ADR-002 — Laravel como web app, BFF e ponto inicial

**Contexto:** o Laravel servirá o frontend e será a entrada usada pelo navegador. Os domínios distribuídos continuarão em serviços Node.

**Decisão:** `catalog-app` será uma aplicação web tradicional Laravel, dona do catálogo e BFF/ponto inicial para as interações do usuário.

**Consequências:** o Laravel valida formulários, renderiza páginas e adapta integrações; não duplica as regras de pedidos, estoque ou pagamentos; o sistema completo não é um monólito, embora o Laravel seja uma aplicação modular.

## ADR-003 — Sem API pública no primeiro slice

**Contexto:** o primeiro objetivo é entregar um CRUD de catálogo para um frontend servido pela própria aplicação.

**Decisão:** usar rotas web, formulários, controllers, Form Requests e respostas server-side. Não criar endpoints REST públicos para o catálogo nesta etapa.

**Consequências:** o contrato inicial é a interface web; uma futura API poderá reutilizar Actions e regras da slice sem tornar a camada HTTP inicial mais complexa.

## ADR-004 — Feature by slice no Laravel

**Contexto:** manter controllers, requests, modelos, Actions e testes de uma feature próximos facilita a evolução e uma eventual extração.

**Decisão:** colocar o catálogo em `app/Features/Catalog`, com views em `resources/views/catalog` e testes em `tests/Feature/Catalog` e `tests/Unit/Features/Catalog`.

**Consequências:** o namespace `App\\` continua funcionando sem pacote de módulos; alguns `make:*` do Artisan usam destinos convencionais diferentes e podem exigir namespace explícito, movimentação ou generators locais simples.

## ADR-005 — Fronteiras dos domínios

**Contexto:** o objetivo do projeto é estudar a evolução para comunicação distribuída sem duplicar regras.

**Decisão:** o Laravel é dono do catálogo; `orders-service`, `stock-service` e `payments-service` são donos, respectivamente, de pedidos, estoque e pagamentos.

**Consequências:** adapters e projeções no Laravel são permitidos; regras de negócio e estado autoritativo permanecem no serviço dono.

## ADR-006 — Database-per-service

**Contexto:** Outbox, idempotência e Saga ficam mais didáticos quando cada serviço controla sua persistência.

**Decisão:** usar `catalog_db`, `orders_db`, `stock_db` e `payments_db` quando os serviços correspondentes existirem.

**Consequências:** não haverá joins entre bancos de domínios; leituras compostas usam BFF, cache, projeções ou contratos explícitos.

## ADR-007 — Navegador acessa somente o Laravel

**Contexto:** expor serviços internos diretamente criaria contratos, autenticação e CORS duplicados e acoplaria o frontend à topologia.

**Decisão:** o navegador sempre chama o Laravel. O Laravel acessa cache, banco, broker e, quando necessário, integrações internas.

**Consequências:** o Laravel pode compor respostas e esconder mudanças internas; ele deve permanecer stateless entre instâncias e não virar o orquestrador da Saga.

## ADR-008 — Quantidade não aparece na listagem

**Contexto:** não é necessário exibir estoque em cada card da página de produtos.

**Decisão:** listagens consultam somente dados do catálogo. Quantidade pode aparecer em detalhe, carrinho e checkout.

**Consequências:** não há consulta de estoque por produto em uma listagem; o frontend recebe quantidade somente quando a tela realmente precisa dela.

## ADR-009 — Disponibilidade em cache/projeção

**Contexto:** a quantidade exibida não precisa ser absolutamente precisa e o `stock-service` não deve ser consultado diretamente pelo navegador.

**Decisão:** o Laravel mantém disponibilidade projetada em Redis. O `stock-service` publica mudanças com quantidade absoluta e versão; um consumer atualiza o cache. Reservas, liberações, expirações, reposições e ajustes podem mudar o valor.

**Consequências:** a tela pode exibir informação eventual; a reserva real sempre é validada pelo `stock-service`; TTL e comportamento de cache miss permanecem decisões de implementação. Eventos devem carregar valor absoluto e versão para tolerar duplicação e reordenação.

## ADR-010 — Comandos e eventos de pedido

**Contexto:** o Laravel recebe a intenção de criar um pedido, mas o `orders-service` é quem persiste e cria o pedido.

**Decisão:** o Laravel publica um comando como `order.placement.requested`. O `orders-service` publica `order.created` depois de persistir o pedido.

**Consequências:** eventos representam fatos ocorridos; o Laravel não afirma que um pedido existe antes do serviço dono confirmá-lo.

## ADR-011 — RabbitMQ como comunicação de negócio

**Contexto:** o projeto precisa exercitar producers, consumers, retries, DLQ, idempotência e coreografia.

**Decisão:** comandos e eventos de processamento usam RabbitMQ. Leituras síncronas só existem quando justificadas, passam pelo Laravel e não fazem parte do caminho de alteração de estado da Saga.

**Consequências:** o frontend não espera a execução completa do pedido; telas exibem estado pendente e consultam uma leitura apropriada.

## ADR-012 — Topologia de exchange e filas

**Contexto:** várias filas precisarão consumir os mesmos eventos por domínio.

**Decisão:** usar uma exchange topic `ecommerce.events`, routing keys por domínio e uma fila por consumer group.

**Consequências:** novos consumers podem ser adicionados sem alterar producers; a separação em exchanges por serviço fica para uma necessidade futura.

## ADR-013 — Retry, DLQ e idempotência

**Contexto:** RabbitMQ entrega mensagens pelo menos uma vez e falhas transitórias não devem gerar perda nem retry infinito.

**Decisão:** usar retry por filas TTL+DLX (`5s`, `30s`, `2m`), DLQ terminal para erros permanentes ou esgotados e tabela `inbox(event_id, consumer_group)` para idempotência.

**Consequências:** consumers fazem ack somente após commit; reentrega pode ocorrer, mas não deve duplicar efeitos de negócio.

## ADR-014 — Envelope versionado de mensagens

**Contexto:** consumers independentes precisam rastrear, validar e evoluir eventos.

**Decisão:** toda mensagem contém `event_id`, `event_type`, `event_version`, `occurred_at`, `correlation_id` e `payload`.

**Consequências:** contratos podem coexistir em versões; schemas ficam em `packages/contracts`; contrato inválido é erro permanente e não deve entrar em retry infinito.

## ADR-015 — Valores monetários em centavos

**Contexto:** preços e pagamentos não podem depender de aritmética de ponto flutuante.

**Decisão:** persistir e transportar dinheiro como inteiros em centavos, com nomes como `price_cents`.

**Consequências:** conversões para exibição ficam na borda; cálculos de negócio permanecem determinísticos.

## ADR-016 — Falhas determinísticas de pagamento

**Contexto:** o projeto precisa testar fluxos felizes, retries e compensações sem aleatoriedade.

**Decisão:** usar tokens `card-success`, `card-transient-failure` e `card-rejected`.

**Consequências:** testes reproduzem falhas temporárias e permanentes; nenhum gateway de pagamento real é necessário.

## ADR-017 — Escala independente

**Contexto:** o Laravel, o catálogo e cada consumer podem ter perfis de carga diferentes.

**Decisão:** permitir múltiplas instâncias stateless do Laravel atrás de load balancer e escalar serviços Node/consumers por fila.

**Consequências:** sessões/cache devem ser compartilhados; arquivos locais não podem ser requisito; PostgreSQL, Redis e RabbitMQ tornam-se dependências compartilhadas e precisam ser tratados como tal.

## ADR-018 — Outbox em etapas

**Contexto:** introduzir Outbox antes de haver um fluxo de mensagens tornaria a primeira etapa maior sem benefício didático proporcional.

**Decisão:** a etapa 2 aceita temporariamente a janela commit + publish, mitigada por ack tardio e idempotência. A etapa 4 adiciona Outbox transacional aos serviços com estado e publicação persistente.

**Consequências:** a limitação é conhecida e testada; o Laravel pode permanecer fail-fast para comandos até existir necessidade de um Outbox próprio.

## ADR-019 — Modelo inicial do produto

**Contexto:** o catálogo precisa de uma identidade estável para atravessar as futuras fronteiras de serviço sem misturar publicação, estoque e histórico de pedidos.

**Decisão:** cada registro em `products` representa um único item vendável e usa UUIDv7 como identidade canônica. O SKU é único, normalizado em maiúsculas e permanece reservado durante o soft delete. O preço atual é persistido em `price_cents` como inteiro positivo em BRL. `is_active` representa somente publicação; `deleted_at` representa lixeira. Produtos restaurados voltam inativos.

**Consequências:** o `stock-service` e os itens de pedido referenciam o produto pelo UUIDv7 sem foreign keys entre bancos. Pedidos preservam snapshots de SKU, nome e preço. Quantidade, variantes, categorias, imagens e histórico de preços não pertencem ao modelo inicial.

## Decisões ainda abertas

- escolha final entre Blade puro e Inertia; ambas preservam a decisão de não ter API pública;
- TTL, formato exato das chaves Redis e estratégia de reidratação no cache miss;
- momento em que autenticação e autorização entrarão no projeto;
- necessidade de um read model persistente além do Redis.
