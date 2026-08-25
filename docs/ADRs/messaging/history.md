# Registros históricos de mensageria

Estas ADRs preservam as decisões genéricas que antecederam o catálogo detalhado de mensageria. Elas não são a fonte de verdade atual; as decisões vigentes estão em [`ADRs.md`](ADRs.md).

## ADR-011 — RabbitMQ como comunicação de negócio

**Status:** Substituída por ADR-MSG-001.

**Contexto:** o projeto precisa exercitar producers, consumers, retries, DLQ, idempotência e coreografia.

**Decisão:** comandos e eventos de processamento usam RabbitMQ. Leituras síncronas só existem quando justificadas, passam pelo Laravel e não fazem parte do caminho de alteração de estado da Saga.

**Consequências:** o frontend não espera a execução completa do pedido; telas exibem estado pendente e consultam uma leitura apropriada.

## ADR-012 — Topologia de exchange e filas

**Status:** Substituída por ADR-MSG-002, ADR-MSG-007 e ADR-MSG-008.

**Contexto:** várias filas precisarão consumir os mesmos eventos por domínio.

**Decisão:** usar uma exchange topic `ecommerce.events`, routing keys por domínio e uma fila por consumer group.

**Consequências:** novos consumers podem ser adicionados sem alterar producers; a separação em exchanges por serviço fica para uma necessidade futura.

## ADR-013 — Retry, DLQ e idempotência

**Status:** Substituída por ADR-MSG-009, ADR-MSG-010 e ADR-MSG-011.

**Contexto:** RabbitMQ entrega mensagens pelo menos uma vez e falhas transitórias não devem gerar perda nem retry infinito.

**Decisão:** usar retry por filas TTL+DLX (`5s`, `30s`, `2m`), DLQ terminal para erros permanentes ou esgotados e tabela `inbox(event_id, consumer_group)` para idempotência.

**Consequências:** consumers fazem ack somente após commit; reentrega pode ocorrer, mas não deve duplicar efeitos de negócio.

## ADR-014 — Envelope versionado de mensagens

**Status:** Substituída por ADR-MSG-005.

**Contexto:** consumers independentes precisam rastrear, validar e evoluir eventos.

**Decisão:** toda mensagem contém `event_id`, `event_type`, `event_version`, `occurred_at`, `correlation_id` e `payload`.

**Consequências:** contratos podem coexistir em versões; schemas ficam em `packages/contracts`; contrato inválido é erro permanente e não deve entrar em retry infinito.

## ADR-018 — Outbox em etapas

**Status:** Substituída por ADR-MSG-012.

**Contexto:** introduzir Outbox antes de haver um fluxo de mensagens tornaria a primeira etapa maior sem benefício didático proporcional.

**Decisão:** a etapa 2 aceita temporariamente a janela commit + publish, mitigada por ack tardio e idempotência. A etapa 4 adiciona Outbox transacional aos serviços com estado e publicação persistente.

**Consequências:** a limitação é conhecida e testada; o Laravel pode permanecer fail-fast para comandos até existir necessidade de um Outbox próprio.
