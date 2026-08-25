# ADRs — e-commerce

Este arquivo reúne decisões de fluxo de negócio do e-commerce que atravessam mais de um domínio. Decisões transversais de arquitetura ficam em [`../architecture/ADRs.md`](../architecture/ADRs.md), decisões do Catálogo em [`../catalog/ADRs.md`](../catalog/ADRs.md), e decisões de mensageria em [`../messaging/ADRs.md`](../messaging/ADRs.md).

## Índice

| ID | Decisão | Status |
|---|---|---|
| ADR-009 | Disponibilidade em cache/projeção | Aceita |
| ADR-010 | Comandos e eventos de pedido | Aceita |
| ADR-015 | Valores monetários em centavos | Aceita |
| ADR-016 | Falhas determinísticas de pagamento | Aceita |
| ADR-018 | Outbox em etapas | Histórico; detalhada por ADR-MSG-012 |

## ADR-009 — Disponibilidade em cache/projeção

**Contexto:** a quantidade exibida não precisa ser absolutamente precisa e o `stock-service` não deve ser consultado diretamente pelo navegador.

**Decisão:** o Laravel mantém disponibilidade projetada em Redis. O `stock-service` publica mudanças com quantidade absoluta e versão; um consumer atualiza o cache. Reservas, liberações, expirações, reposições e ajustes podem mudar o valor.

**Consequências:** a tela pode exibir informação eventual; a reserva real sempre é validada pelo `stock-service`; TTL e comportamento de cache miss permanecem decisões de implementação. Eventos devem carregar valor absoluto e versão para tolerar duplicação e reordenação.

## ADR-010 — Comandos e eventos de pedido

**Contexto:** o Laravel recebe a intenção de criar um pedido, mas o `orders-service` é quem persiste e cria o pedido.

**Decisão:** o Laravel publica um comando como `order.placement.requested`. O `orders-service` publica `order.created` depois de persistir o pedido.

**Consequências:** eventos representam fatos ocorridos; o Laravel não afirma que um pedido existe antes do serviço dono confirmá-lo.

## ADR-015 — Valores monetários em centavos

**Contexto:** preços e pagamentos não podem depender de aritmética de ponto flutuante.

**Decisão:** persistir e transportar dinheiro como inteiros em centavos, com nomes como `price_cents`.

**Consequências:** conversões para exibição ficam na borda; cálculos de negócio permanecem determinísticos.

## ADR-016 — Falhas determinísticas de pagamento

**Contexto:** o projeto precisa testar fluxos felizes, retries e compensações sem aleatoriedade.

**Decisão:** usar tokens `card-success`, `card-transient-failure` e `card-rejected`.

**Consequências:** testes reproduzem falhas temporárias e permanentes; nenhum gateway de pagamento real é necessário.

## ADR-018 — Outbox em etapas

> Registro histórico. A decisão operacional detalhada e vigente está em [ADR-MSG-012](../messaging/ADRs.md).

**Contexto:** introduzir Outbox antes de haver um fluxo de mensagens tornaria a primeira etapa maior sem benefício didático proporcional.

**Decisão:** a etapa 2 aceita temporariamente a janela commit + publish, mitigada por ack tardio e idempotência. A etapa 4 adiciona Outbox transacional aos serviços com estado e publicação persistente.

**Consequências:** a limitação é conhecida e testada; o Laravel pode permanecer fail-fast para comandos até existir necessidade de um Outbox próprio.
