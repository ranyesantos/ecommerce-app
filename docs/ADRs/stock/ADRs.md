# ADRs — estoque

Este arquivo reúne as decisões específicas do domínio de Estoque. Decisões transversais de arquitetura ficam em [`../architecture/ADRs.md`](../architecture/ADRs.md), e decisões de mensageria ficam em [`../messaging/ADRs.md`](../messaging/ADRs.md).

## Índice

| ID | Decisão | Status |
|---|---|---|
| ADR-STK-001 | Ledger autoritativo com partidas duplas e projeção transacional | Aceita |
| ADR-STK-002 | Reserva atômica para todas as linhas do Pedido | Aceita |
| ADR-STK-003 | Contas operacionais nunca ficam negativas | Aceita |
| ADR-STK-004 | Item de Estoque nasce por inicialização explícita | Aceita |
| ADR-STK-005 | Recebimento incremental e Ajuste absoluto | Aceita |
| ADR-STK-006 | Estado da Reserva é derivado do Ledger | Aceita |
| ADR-STK-007 | Resultado de Reserva é idempotente por Pedido | Aceita |
| ADR-STK-008 | Primeiro incremento entrega a fundação administrativa | Aceita |
| ADR-STK-009 | Operações administrativas entram por comandos assíncronos | Aceita |
| ADR-STK-010 | CQRS pragmático com um único banco | Aceita |

## ADR-STK-001 — Ledger autoritativo com partidas duplas e projeção transacional

**Contexto:** o Estoque precisa preservar um histórico imutável e reconstruível sem permitir que duas operações concorrentes reservem a mesma quantidade. A quantidade exibida no Catálogo pode ser eventual, mas decisões de reserva exigem o estado mais recente.

**Decisão:** o Ledger de Estoque é a fonte de verdade e contém Transações de Estoque imutáveis em partidas duplas, cujos Lançamentos de Estoque sempre somam zero. Uma projeção operacional no PostgreSQL é atualizada na mesma transação que o Ledger e os demais efeitos locais, incluindo Reserva e Inbox quando aplicáveis. Operações concorrentes bloqueiam as projeções necessárias com `SELECT ... FOR UPDATE`; operações com vários Itens de Estoque adquirem os locks em ordem determinística. Prisma continua sendo a camada de acesso e transação.

O conjunto inicial de Contas de Estoque é fixo: Disponível, Reservado, Recebimento, Saída Confirmada, Ganho de Ajuste e Perda de Ajuste. A Quantidade Total em Estoque é a soma de Disponível e Reservado; a quantidade que pode ser mostrada no Catálogo e comprometida por uma nova Reserva é Disponível.

O Laravel usa uma Disponibilidade Projetada separada e eventualmente consistente em uma tabela no PostgreSQL do Catálogo apenas para leitura. Redis poderá ser adicionado futuramente como cache dessa projeção. Snapshots ou checkpoints poderão ser adicionados para acelerar a reconstrução da projeção operacional do Estoque; eles não participam do caminho normal de reserva.

**Consequências:** comandos usam estado atual consistente, enquanto leituras de Catálogo podem tolerar atraso. Ledger e projeção não divergem por falha parcial dentro do PostgreSQL, e o estado operacional pode ser reconstruído. A solução exige Contas, Transações, Lançamentos e uma projeção materializada, aumentando o modelo em troca de rastreabilidade e conservação verificável das quantidades.

## ADR-STK-002 — Reserva atômica para todas as linhas do Pedido

**Contexto:** uma solicitação pode conter vários Itens de Estoque e apenas parte deles pode ter quantidade disponível. Reservar parcialmente alteraria o conteúdo e o valor do Pedido e propagaria essa mudança para pagamento, descontos e entrega.

**Decisão:** `order.created` representa um Pedido já persistido após a confirmação do checkout e dispara a tentativa de Reserva no `stock-service`, antes de qualquer solicitação de pagamento. A Reserva é tudo ou nada para todas as linhas do Pedido. Cada `product_id` deve aparecer exatamente uma vez, com quantidade inteira positiva; linhas duplicadas constituem erro permanente de contrato e não são somadas ou processadas como indisponibilidade. O Estoque bloqueia os Itens de Estoque em ordem determinística e valida todas as quantidades na mesma transação. Se qualquer linha estiver indisponível, nenhum lançamento ou Reserva é persistido e `stock.unavailable` é publicado com todas as linhas indisponíveis; cada resultado informa `product_id`, quantidade solicitada, quantidade disponível observada e o motivo, inicialmente `ITEM_NOT_INITIALIZED` ou `INSUFFICIENT_QUANTITY`. Se todas estiverem disponíveis, as quantidades são reservadas juntas e `stock.reserved` é publicado apenas com `order_id`, `reserved_at` e `expires_at`; `reservation_id` e as linhas permanecem internos ao Estoque.

**Consequências:** o Pedido nunca fica parcialmente reservado e os serviços seguintes recebem um resultado único. O fluxo de pagamento somente pode começar após `stock.reserved`; `stock.unavailable` rejeita o Pedido sem solicitar pagamento. Informar todas as linhas indisponíveis permite corrigir o Pedido de uma vez, embora as quantidades relatadas sejam um retrato do instante da tentativa e não uma promessa futura. Duplicidade de Produto evidencia um defeito no produtor e segue o tratamento de mensagem inválida, sem contaminar as métricas de falta de estoque. A indisponibilidade de uma linha impede a compra das demais. Se vendedores, depósitos ou grupos de entrega forem introduzidos futuramente, cada grupo deverá originar um Pedido ou solicitação de Reserva independente.

## ADR-STK-003 — Contas operacionais nunca ficam negativas

**Contexto:** aceitar uma Reserva acima da Quantidade Disponível produziria overselling e exigiria backorder ou compensação posterior. O projeto precisa garantir que uma mesma unidade da Quantidade Total em Estoque não seja prometida a mais de um Pedido.

**Decisão:** as contas operacionais Disponível e Reservado nunca podem ficar negativas. Uma Reserva cuja quantidade solicitada exceda Disponível é recusada integralmente. O domínio não oferece backorder nem permite saldo negativo temporário.

**Consequências:** cada Reserva aceita possui Quantidade Total em Estoque correspondente, e concorrência não cria estoque inexistente. Pedidos sem quantidade suficiente recebem indisponibilidade em vez de aguardar reposição. Uma futura introdução de backorder exigirá uma nova decisão e um fluxo explícito, não apenas a remoção de uma validação.

## ADR-STK-004 — Item de Estoque nasce por inicialização explícita

**Contexto:** a criação de um Produto no Catálogo não informa se o Estoque deve controlá-lo nem qual é sua quantidade inicial. Criar estoque zero automaticamente a partir de `catalog.product.created` apagaria a diferença entre um Produto ainda não inicializado e um Item de Estoque inicializado sem disponibilidade.

**Decisão:** o Item de Estoque nasce somente por uma Inicialização de Estoque explícita, contendo o `product_id` canônico e uma quantidade inicial inteira maior ou igual a zero. Quantidade zero cria o Item de Estoque e sua projeção sem criar lançamentos de valor zero. Quantidade positiva cria também uma Transação de Estoque de Recebimento para Disponível. O evento `catalog.product.created` não cria estoque automaticamente.

Cada Produto pode ser inicializado uma única vez. A reentrega da mesma mensagem é idempotente e não cria novos lançamentos. Uma nova mensagem de Inicialização para um `product_id` já controlado retorna conflito, mesmo quando repete a quantidade original; reposições e correções usam operações próprias.

Quando `order.created` referencia um Produto sem Item de Estoque inicializado, a tentativa termina deterministicamente com `stock.unavailable` e motivo `ITEM_NOT_INITIALIZED`. Nenhuma Reserva ou lançamento é criado, a mensagem não entra em retry por esse motivo e o Item de Estoque não é inicializado implicitamente. O resultado pode alimentar um alerta operacional.

**Consequências:** o Laravel precisa solicitar a inicialização quando desejar que o Estoque passe a controlar um Produto. A ausência do Item de Estoque significa “não inicializado”, enquanto um Item existente com Quantidade Disponível zero significa “inicializado sem disponibilidade”; ambas impedem a Reserva, mas permanecem distinguíveis no resultado. A unicidade por `product_id` protege contra duas inicializações concorrentes, e a Inbox protege contra reentrega da mesma mensagem.

## ADR-STK-005 — Recebimento incremental e Ajuste absoluto

**Contexto:** unidades efetivamente recebidas e correções após uma conferência representam fatos diferentes. Tratar ambos como deltas manuais dificultaria auditoria e obrigaria o cliente a conhecer Disponível e Reservado. Uma correção também pode revelar menos unidades do que aquelas já comprometidas por Reservas.

**Decisão:** Recebimento de Estoque acrescenta uma quantidade inteira positiva à conta Disponível de um Item de Estoque já inicializado. Cada nova mensagem representa um novo recebimento; reentregar a mesma mensagem não repete os lançamentos.

Ajuste de Estoque declara a nova Quantidade Total em Estoque, e o serviço calcula a diferença sob lock da projeção. Diferença positiva transfere de Ganho de Ajuste para Disponível; diferença negativa transfere de Disponível para Perda de Ajuste. Reservado não é alterado implicitamente.

Quando a quantidade declarada é menor que Reservado, o Ajuste é recusado. Um administrador escolhe um Pedido para cancelamento por meio do `orders-service`; o Estoque libera a Reserva ao processar o cancelamento e o Ajuste é então reenviado. O `stock-service` não escolhe automaticamente qual cliente perde sua Reserva.

**Consequências:** recebimentos preservam sua natureza incremental, enquanto correções expressam o total verificado sem expor a estrutura das contas ao cliente. Nenhum Ajuste cria saldo negativo ou invalida Reservas silenciosamente. A divergência permanece sem aplicação no Ledger até a resolução operacional, exigindo uma futura CLI ou interface administrativa para esse fluxo.

## ADR-STK-006 — Estado da Reserva é derivado do Ledger

**Contexto:** o Estoque precisa localizar as quantidades comprometidas por um Pedido e acompanhar sua liberação, confirmação ou expiração. Manter uma Reserva mutável como segunda fonte de verdade poderia divergir do Ledger autoritativo.

**Decisão:** a Reserva possui identidade e ciclo de vida próprios, mas seu estado é derivado das Transações de Estoque relacionadas. Cada Reserva possui um `reservation_id` do domínio de Estoque, referencia exatamente um `order_id` externo e a projeção impõe no máximo uma Reserva por Pedido. Eventos externos, incluindo `payment.captured`, correlacionam o compromisso por `order_id`; o `reservation_id` permanece uma identidade interna do Estoque. Uma Reserva nasce `ATIVA` somente quando todas as linhas são reservadas e pode terminar como `CONFIRMADA`, `LIBERADA` ou `EXPIRADA`. Uma tentativa sem estoque não cria Reserva nem lançamentos; produz apenas um resultado de indisponibilidade. Uma Reserva terminal não é reaberta, e uma nova tentativa comercial exige um novo Pedido.

O `stock-service` define o prazo no instante em que cria a Reserva e publica o `expires_at` efetivamente aplicado em `stock.reserved`. Todas as Reservas usam inicialmente uma única duração configurável, com padrão de 15 minutos. Ao alcançar esse prazo, uma Reserva ainda `ATIVA` é expirada pelo próprio Estoque, que transfere suas quantidades de Reservado para Disponível e publica `stock.reservation.expired`. O incremento que introduzir Reservas não implementará cancelamento explícito nem consumirá `order.cancelled`; a transição para `LIBERADA` permanece no modelo-alvo para uma etapa futura.

O mecanismo operacional que detectará o vencimento — polling no PostgreSQL, mensagem atrasada ou scheduler externo — permanece deliberadamente adiado para a especificação de implementação. Esta ADR define o prazo e o efeito da expiração, não o mecanismo de disparo.

Quando a integração com Pagamentos for introduzida, se `payment.captured` chegar para uma Reserva já `EXPIRADA`, o Estoque recusa a confirmação com `RESERVATION_EXPIRED` e não cria lançamentos. Como a cobrança já foi capturada, o modelo-alvo exige uma compensação financeira pelo `payments-service`; o mecanismo de estorno fica fora do escopo inicial do Estoque. Uma evolução futura poderá tentar recuperar atomicamente todas as quantidades disponíveis antes de solicitar o estorno, mas isso exigirá revisar a terminalidade de `EXPIRADA` e não faz parte desta decisão.

Uma projeção materializada de Reservas oferece consulta e validação eficientes e é atualizada na mesma transação da projeção de quantidades e do Ledger. Ela pode ser reconstruída integralmente a partir do Ledger.

**Consequências:** confirmar uma Reserva transfere suas quantidades de Reservado para Saída Confirmada. Futuramente, cancelar um Pedido encerrará a Reserva como `LIBERADA`; no incremento que introduzir Reservas, somente a expiração automática devolve uma Reserva não confirmada de Reservado para Disponível. Uma captura tardia não pode consumir Disponível nem reabrir a Reserva e precisará ser compensada fora do Estoque. A aplicação consulta o estado atual sem replay no caminho normal. `reservation_id` identifica o compromisso internamente, enquanto `order_id` permite correlação e idempotência entre contextos. Correções nunca editam diretamente a projeção; acrescentam uma Transação de Estoque válida e projetam o novo estado.

## ADR-STK-007 — Resultado de Reserva é idempotente por Pedido

**Contexto:** a Inbox impede efeitos repetidos para o mesmo `message_id`, mas um produtor pode republicar o mesmo Pedido em outro envelope. Se apenas o identificador da mensagem fosse considerado, um Pedido inicialmente indisponível poderia reservar após uma reposição, contrariando a regra de que uma nova tentativa comercial exige um novo Pedido.

**Decisão:** a primeira tentativa válida de cada `order_id` produz um Resultado de Reserva definitivo, de sucesso ou indisponibilidade. O Estoque persiste esse resultado com as linhas normalizadas da intenção, compostas por `product_id` e quantidade, sem considerar ordem das linhas ou metadados do envelope. Uma nova mensagem para o mesmo Pedido e com a mesma intenção não repete locks, lançamentos ou Reserva; a publicação original permanece garantida pela Outbox. A mesma identidade com linhas diferentes produz o erro permanente `ORDER_PAYLOAD_MISMATCH`, sem alterar o Estoque.

Um resultado indisponível não é uma Reserva rejeitada. Ele preserva a decisão do Pedido para idempotência de negócio. Restrições únicas por `order_id` serializam mensagens concorrentes da mesma identidade, enquanto a Inbox continua tratando reentregas do mesmo `message_id`.

**Consequências:** reposição posterior não revive um Pedido anteriormente indisponível, e um novo checkout precisa criar outro Pedido. Reenvios semanticamente equivalentes permanecem seguros mesmo quando recebem outro envelope. O serviço precisa persistir resultados negativos e a intenção normalizada, além das Reservas bem-sucedidas, e distinguir falha de contrato de indisponibilidade de negócio.

## ADR-STK-008 — Primeiro incremento entrega a fundação administrativa

**Contexto:** o modelo-alvo inclui administração de quantidades, Reservas, confirmação, expiração e integrações com Pedidos e Pagamentos. Entregar tudo no nascimento do serviço aumentaria o número de fluxos concorrentes antes de o Ledger e suas projeções estarem validados.

**Decisão:** o primeiro incremento do `stock-service` implementa Inicialização de Estoque, Recebimento de Estoque, Ajuste de Estoque, Ledger de partidas duplas e projeções operacionais. Cada mudança confirmada publica, via Outbox, a Quantidade Disponível absoluta e sua versão para a Disponibilidade Projetada do Catálogo. Ele não consome `order.created`, não cria Reservas e não integra com Pagamentos. O fluxo mínimo de Reserva passa a ser um incremento posterior.

**Consequências:** a fundação contábil, administrativa e de projeção eventual pode ser testada antes da concorrência entre Pedidos. O primeiro incremento também introduz um consumer no Laravel e uma tabela de projeção no banco do Catálogo. Esse fatiamento acrescenta uma etapa anterior à etapa 2c descrita no desenho histórico da plataforma; as decisões de Reserva permanecem como modelo-alvo, sem fazer parte da primeira entrega.

## ADR-STK-009 — Operações administrativas entram por comandos assíncronos

**Contexto:** Inicialização, Recebimento e Ajuste alteram o estado autoritativo do Estoque, mas são iniciados por interações no Laravel. Uma gravação síncrona nos bancos de Catálogo e Estoque produziria um dual write sem transação distribuída.

**Decisão:** o Laravel envia Inicialização de Estoque, Recebimento de Estoque e Ajuste de Estoque como comandos assíncronos pelo RabbitMQ. Na criação de Produto, o Produto e o comando de Inicialização são persistidos na mesma transação local por meio da Outbox do Laravel. O `stock-service` processa cada comando com Inbox e publica o novo valor absoluto e a versão da Quantidade Disponível por sua própria Outbox. O estado de sincronização da Disponibilidade Projetada permanece pendente até o Catálogo consumir a confirmação.

**Consequências:** nenhuma requisição precisa gravar atomicamente em dois bancos e reentregas não repetem movimentos. A interface apresenta um estado intermediário enquanto o Estoque processa o comando, e falhas permanentes precisam ficar visíveis para operação. Laravel passa a atuar como produtor dos comandos e consumer dos fatos de disponibilidade, em processos separados da requisição web quando aplicável.

## ADR-STK-010 — CQRS pragmático com um único banco

**Contexto:** comandos do Estoque possuem invariantes e escrita transacional no Ledger, enquanto consultas podem usar projeções próprias. Separar bancos de leitura e escrita permitiria escalá-los independentemente, mas a maior parte das leituras do usuário será atendida pela Disponibilidade Projetada do Catálogo e não existe demanda mensurável que justifique consistência eventual e operação adicionais dentro do `stock-service`.

**Decisão:** o `stock-service` separa comandos e consultas na organização dos casos de uso, mas mantém um único `stock_db` e um único Prisma Client. Ledger, estado operacional, projeções, Inbox e Outbox permanecem no mesmo PostgreSQL. Não serão usados `@nestjs/cqrs`, Command Bus, Query Bus nem bancos separados. Comandos podem ler e bloquear o estado operacional necessário às invariantes na mesma transação; consultas não alteram estado.

**Consequências:** o código preserva a distinção entre intenção de escrita e leitura sem introduzir infraestrutura de CQRS completo. Ledger, projeção operacional e efeitos locais continuam atomicamente consistentes, e o serviço não escala leitura e escrita de forma independente. Bancos separados só serão reconsiderados diante de necessidade observável e exigirão uma nova decisão; índices, otimização de consultas e réplica de leitura são alternativas anteriores a essa separação.
