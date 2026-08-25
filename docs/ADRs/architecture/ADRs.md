# ADRs — arquitetura distribuída

Este arquivo reúne decisões transversais às aplicações e aos domínios. As decisões específicas do Laravel ficam em [`../platform/ADRs.md`](../platform/ADRs.md), as do Catálogo em [`../catalog/ADRs.md`](../catalog/ADRs.md), e as de mensageria em [`../messaging/ADRs.md`](../messaging/ADRs.md).

## Índice

| ID | Decisão | Status |
|---|---|---|
| ADR-005 | Fronteiras dos domínios | Aceita |
| ADR-006 | Database-per-service | Aceita |
| ADR-007 | Navegador acessa somente o Laravel | Aceita |
| ADR-017 | Escala independente | Aceita |

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

## ADR-017 — Escala independente

**Contexto:** o Laravel, o catálogo e cada consumer podem ter perfis de carga diferentes.

**Decisão:** permitir múltiplas instâncias stateless do Laravel atrás de load balancer e escalar serviços Node/consumers por fila.

**Consequências:** sessões/cache devem ser compartilhados; arquivos locais não podem ser requisito; PostgreSQL, Redis e RabbitMQ tornam-se dependências compartilhadas e precisam ser tratados como tal.
