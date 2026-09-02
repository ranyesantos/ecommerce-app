# Estoque

Vocabulário canônico do contexto de Estoque deste e-commerce.

**Item de Estoque**:
Representação mantida pelo contexto de Estoque para controlar um único estoque lógico global associado a exatamente um Produto do Catálogo, identificado por `product_id`.
_Evitar_: Produto em Estoque, Saldo

**Inicialização de Estoque**:
Instrução explícita e única que cria o Item de Estoque de um Produto com uma quantidade inicial maior ou igual a zero; sua reentrega não repete efeitos, e outra inicialização do mesmo Item é um conflito.
_Evitar_: Criação Automática, Primeiro Recebimento

**Ledger de Estoque**:
Registro cronológico, imutável e autoritativo das Transações de Estoque; qualquer visão do estado atual deve ser derivável desse registro.
_Evitar_: Log de Auditoria, Event Store

**Conta de Estoque**:
Posição operacional ou fronteira pertencente a um conjunto fixo e explícito, à qual uma quantidade é atribuída pelos Lançamentos de Estoque.
_Evitar_: Bucket, Coluna de Saldo, Conta Configurável

**Disponível**:
Conta de Estoque com a parcela da Quantidade Total em Estoque que pode ser comprometida por uma nova Reserva.
_Evitar_: Saldo Livre

**Reservado**:
Conta de Estoque com a parcela da Quantidade Total em Estoque já comprometida por uma Reserva e ainda não retirada definitivamente.
_Evitar_: Bloqueado

**Recebimento**:
Conta de fronteira que origina quantidades inicializadas ou repostas no estoque.
_Evitar_: Fornecedor

**Recebimento de Estoque**:
Instrução repetível que acrescenta à conta Disponível uma quantidade positiva de unidades efetivamente recebidas para um Item de Estoque já inicializado.
_Evitar_: Reposição Absoluta, Ajuste Positivo

**Saída Confirmada**:
Conta de fronteira que recebe quantidades retiradas definitivamente do estoque.
_Evitar_: Vendido, Consumido

**Ganho de Ajuste**:
Conta de fronteira que origina quantidades acrescentadas por uma correção de estoque.
_Evitar_: Entrada Manual

**Perda de Ajuste**:
Conta de fronteira que recebe quantidades removidas por uma correção de estoque.
_Evitar_: Saída Manual

**Ajuste de Estoque**:
Instrução que declara uma nova Quantidade Total em Estoque verificada e deixa o Estoque calcular e registrar a diferença como ganho ou perda. É recusada quando a quantidade declarada é menor que Reservado, até que as Reservas bloqueadoras sejam liberadas.
_Evitar_: Delta Manual, Definição de Disponível

**Quantidade Total em Estoque**:
Quantidade atualmente controlada para um Item de Estoque, calculada pela soma das contas Disponível e Reservado, independentemente da natureza do Produto.
_Evitar_: Quantidade Física, Quantidade em Mãos, Saldo Total

**Quantidade Disponível**:
Quantidade da conta Disponível que pode ser comprometida por uma nova Reserva.
_Evitar_: Quantidade em Estoque

**Disponibilidade Projetada**:
Cópia eventualmente consistente da Quantidade Disponível mantida pelo Catálogo exclusivamente para leitura, atualizada por fatos publicados pelo Estoque e acompanhada de versão.
_Evitar_: Estoque do Catálogo, Quantidade Autorizativa

**Transação de Estoque**:
Transferência atômica de quantidade entre Contas de Estoque, composta por Lançamentos de Estoque cuja soma é sempre zero.
_Evitar_: Alteração de Saldo, Movimento Isolado

**Lançamento de Estoque**:
Registro imutável que atribui uma quantidade positiva ou negativa a uma Conta de Estoque como parte de exatamente uma Transação de Estoque.
_Evitar_: Delta Avulso

**Reserva**:
Compromisso com identidade própria, associado a exatamente um Pedido, que reúne atomicamente as quantidades solicitadas para todos os seus Itens de Estoque. Nasce Ativa somente quando todas as quantidades são reservadas e termina como Confirmada, Liberada ou Expirada; uma tentativa sem estoque não cria uma Reserva.
_Evitar_: Reserva Parcial, Identidade do Pedido, Reserva Rejeitada

**Reserva Ativa**:
Reserva cujas quantidades permanecem na conta Reservado aguardando Confirmação, Liberação ou Expiração.

**Reserva Confirmada**:
Reserva encerrada porque suas quantidades foram retiradas definitivamente da Quantidade Total em Estoque.

**Reserva Liberada**:
Reserva encerrada por cancelamento, com suas quantidades devolvidas de Reservado para Disponível.

**Reserva Expirada**:
Reserva encerrada por esgotamento do prazo, com suas quantidades devolvidas de Reservado para Disponível.

**Resultado de Reserva**:
Decisão definitiva da primeira tentativa de reservar estoque para um Pedido, identificada pelo `order_id` e acompanhada das linhas normalizadas que originaram a decisão. Pode indicar sucesso ou indisponibilidade sem transformar uma tentativa malsucedida em Reserva.
_Evitar_: Reserva Rejeitada, Nova Tentativa do Mesmo Pedido

**Produto**:
Item vendável pertencente ao contexto de Catálogo, que mantém sua identidade e seus dados comerciais.
_Evitar_: Item de Estoque
