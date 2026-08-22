# Catálogo — Modelagem inicial de produto

**Data:** 2026-08-21
**Status:** aprovado
**Escopo:** etapa 1 — CRUD web do catálogo em Laravel

## 1. Objetivo

Definir o modelo inicial de produto e suas invariantes sem antecipar estoque, variantes, histórico de preços ou outras capacidades previstas para etapas posteriores.

Cada produto representa um único item vendável com um SKU e um preço. O Laravel é dono desse modelo e persiste os produtos em `catalog_db`.

## 2. Tabela `products`

```text
products
- id              uuid primary key
- sku             varchar(32) not null
- name            varchar(255) not null
- description     text null
- price_cents     integer not null
- is_active       boolean not null default false
- created_at      timestamptz not null
- updated_at      timestamptz not null
- deleted_at      timestamptz null
```

O campo `id` contém um UUIDv7 gerado pela aplicação. Ele é a identidade canônica do produto em URLs, mensagens e referências mantidas por outros serviços.

## 3. Restrições do banco

```text
unique (sku)
check (price_cents > 0)
check (deleted_at is null or is_active = false)
```

A unicidade do SKU inclui registros excluídos logicamente. Um produto na lixeira continua reservando seu SKU para preservar a identidade comercial e garantir que possa ser restaurado sem conflito.

Não haverá inicialmente índice isolado em `is_active`. Índices de listagem e busca serão adicionados quando o volume ou os planos de consulta demonstrarem necessidade.

## 4. Regras dos campos

### 4.1 SKU

- obrigatório e único;
- até 32 caracteres;
- aceita apenas `A-Z`, `0-9`, `_` e `-`;
- tem espaços externos removidos;
- é convertido para maiúsculas no model;
- pode ser alterado após a criação porque integrações usam o UUIDv7 como vínculo.

O Form Request valida formato e tamanho. O model centraliza trim e conversão para maiúsculas. O índice único do PostgreSQL é a proteção definitiva contra duplicidade e condições de corrida.

### 4.2 Nome e descrição

`name` é obrigatório, não é único e preserva acentos e capitalização. Espaços externos são removidos.

`description` é texto simples opcional. HTML e conteúdo rico não são aceitos na etapa 1. Depois do trim, uma descrição vazia é persistida como `null`.

### 4.3 Preço

`price_cents` armazena o preço atual de venda como inteiro em centavos de BRL. Por exemplo, R$ 19,90 é persistido como `1990`.

O valor deve ser maior que zero. Não se usa `float` ou `double`. Histórico de preços não faz parte do modelo inicial; pedidos futuros preservarão o preço negociado em seus próprios itens.

### 4.4 Ativação e lixeira

`is_active` indica somente publicação no catálogo, não disponibilidade de estoque. Um produto ativo pode estar sem estoque, e mudanças de estoque nunca alteram esse campo.

`deleted_at` implementa a lixeira. Desativação e exclusão lógica são estados diferentes:

- produto inativo continua visível na administração e pode ser reativado;
- produto excluído fica oculto das consultas normais e pode ser restaurado;
- exclusão lógica define `is_active = false` e `deleted_at` na mesma transação;
- restauração limpa `deleted_at`, mas mantém `is_active = false`;
- exclusão física não existe no fluxo comum.

## 5. Transições e operações

```text
Criar      -> persiste produto inativo
Editar     -> altera diretamente os dados, mesmo quando ativo
Ativar     -> is_active = true
Desativar  -> is_active = false
Excluir    -> is_active = false + deleted_at = instante atual
Restaurar  -> deleted_at = null + is_active = false
```

Alterações de SKU, nome, descrição e preço valem imediatamente para novas compras. A etapa 1 usa a política de concorrência “última gravação vence”; não há versionamento do agregado nem optimistic locking.

## 6. Fronteiras com estoque e pedidos

Quantidade não pertence a `products` e não aparece no formulário da etapa 1.

Quando o `stock-service` existir, o formulário poderá receber a quantidade inicial, mas o Laravel não a persistirá em `catalog_db`. Ele enviará um comando explícito, como `stock.item.initialize`, contendo o UUIDv7 do produto e a quantidade inicial. O `stock-service` criará seu registro de forma idempotente. O evento `catalog.product.created` não inventará automaticamente um estoque com quantidade zero.

Não haverá foreign keys entre bancos. O estoque manterá o UUIDv7 do catálogo como referência externa única.

Itens de pedido preservarão snapshots para que mudanças posteriores no catálogo não alterem o histórico:

```text
product_id       UUIDv7 canônico
sku              SKU no momento da compra
name             nome no momento da compra
unit_price_cents preço unitário negociado
```

## 7. Tratamento de erros

- validações de entrada devem falhar antes da persistência quando possível;
- violação do índice único de SKU deve ser convertida em erro de validação compreensível;
- a restrição de preço positivo protege gravações que não passam pelo formulário;
- a restrição entre `deleted_at` e `is_active` impede produtos excluídos ativos;
- conflitos de edição não recebem tratamento especial na etapa 1 além da política “última gravação vence”.

## 8. Estratégia de testes

Os testes Pest devem cobrir:

- geração de UUIDv7;
- criação de produto inativo;
- normalização do SKU para maiúsculas;
- rejeição de SKU duplicado com capitalização diferente;
- rejeição de caracteres inválidos no SKU;
- nome obrigatório e descrição opcional;
- preço inteiro e positivo;
- edição direta de produto ativo;
- desativação sem exclusão;
- exclusão lógica com desativação na mesma operação;
- ausência de excluídos nas consultas normais;
- restauração para o estado inativo;
- reserva do SKU durante o soft delete;
- ausência de qualquer leitura ou gravação de quantidade pelo catálogo.

Restrições específicas do PostgreSQL terão testes de integração no banco real. SQLite não será considerado prova suficiente dessas garantias.

## 9. Fora de escopo

- quantidade e movimentações de estoque;
- variantes e opções de produto;
- categorias, imagens e busca avançada;
- múltiplas moedas;
- custo de aquisição;
- histórico de preços;
- exclusão física pela aplicação;
- optimistic locking e auditoria de alterações;
- inicialização do `stock-service` na etapa 1.
