# ADRs — catálogo

Este arquivo reúne as decisões específicas do domínio de Catálogo. Decisões sobre a aplicação Laravel ficam em [`../platform/ADRs.md`](../platform/ADRs.md); decisões de mensageria ficam em [`../messaging/ADRs.md`](../messaging/ADRs.md).

## Índice

| ID | Decisão | Status |
|---|---|---|
| ADR-008 | Quantidade não aparece na listagem | Aceita |
| ADR-019 | Modelo inicial do produto | Aceita |

## ADR-008 — Quantidade não aparece na listagem

**Contexto:** não é necessário exibir estoque em cada card da página de produtos.

**Decisão:** listagens consultam somente dados do catálogo. Quantidade pode aparecer em detalhe, carrinho e checkout.

**Consequências:** não há consulta de estoque por produto em uma listagem; o frontend recebe quantidade somente quando a tela realmente precisa dela.

## ADR-019 — Modelo inicial do produto

**Contexto:** o catálogo precisa de uma identidade estável para atravessar as futuras fronteiras de serviço sem misturar publicação, estoque e histórico de pedidos.

**Decisão:** cada registro em `products` representa um único item vendável e usa UUIDv7 como identidade canônica. O SKU é único, normalizado em maiúsculas e permanece reservado durante o soft delete. O preço atual é persistido em `price_cents` como inteiro positivo em BRL. `is_active` representa somente publicação; `deleted_at` representa lixeira. Produtos restaurados voltam inativos.

**Consequências:** o `stock-service` e os itens de pedido referenciam o produto pelo UUIDv7 sem foreign keys entre bancos. Pedidos preservam snapshots de SKU, nome e preço. Quantidade, variantes, categorias, imagens e histórico de preços não pertencem ao modelo inicial.
