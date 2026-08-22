# Catálogo — Modelagem inicial de produto

**Data:** 2026-08-21
**Status:** aprovado
**Escopo:** etapa 1 — CRUD web do catálogo em Laravel

## 1. Objetivo

Definir o modelo inicial de produto e suas invariantes sem antecipar estoque, variantes, histórico de preços ou outras capacidades previstas para etapas posteriores.

Cada produto representa um único item vendável com um SKU e um preço. O Laravel é dono desse modelo e persiste os produtos em `catalog_db`. No fluxo HTTP suportado, as regras de entrada são responsabilidade dos Form Requests e as transições de estado são responsabilidade das Actions. A camada PostgreSQL oferece salvaguardas adicionais, mas não define as regras de negócio.

A implementação atual está organizada na feature `app/Features/Catalog`, com model, Form Requests, Actions e controllers separados. O fluxo web é server-rendered e não há API pública nesta etapa.

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

O campo `id` contém um UUIDv7 gerado pelo model `Product` por meio de `HasUuids`. Ele é a identidade canônica do produto em URLs, mensagens e referências mantidas por outros serviços.

## 3. Responsabilidade da aplicação e defesa adicional do PostgreSQL

As responsabilidades estão distribuídas nos seguintes pontos:

- no fluxo HTTP suportado, `StoreProductRequest` e `UpdateProductRequest` normalizam os campos e validam formato, obrigatoriedade, unicidade, tipo, texto simples e faixa dos valores antes da persistência;
- o model `Product` normaliza SKU, nome e descrição, mas seus mutators não validam formato, unicidade, texto simples nem preço positivo;
- `CreateProduct` aceita somente os campos do produto e sempre força `is_active = false`;
- `UpdateProduct` aceita somente os campos editáveis e preserva o estado de ativação existente;
- `CreateProduct` e `UpdateProduct` assumem que os atributos recebidos já foram validados e não repetem as regras dos Form Requests;
- `ActivateProduct`, `DeactivateProduct`, `TrashProduct` e `RestoreProduct` são os únicos fluxos de mudança de estado previstos;
- `ProductSkuConflict` traduz a violação da constraint `products_sku_unique` em erro de validação compreensível para `sku`.

A migration atual também cria estas proteções adicionais no PostgreSQL:

```text
unique (sku)
check (price_cents > 0)
check (deleted_at is null or is_active = false)
```

Essas proteções ajudam a preservar dados diante de caminhos de escrita inesperados, mas não definem o contrato de negócio. Há uma dependência específica e intencional na unicidade concorrente: depois da validação prévia do Form Request, uma corrida entre gravações ainda é detectada pela constraint `UNIQUE`; `ProductSkuConflict` traduz essa falha para o contrato de validação da aplicação. Os `CHECK` permanecem apenas como defesa adicional.

A spec não exige testes que acessem essas constraints diretamente, que dependam de seus nomes para provar o mecanismo do banco ou que tentem validar funcionalidades internas do PostgreSQL. Os testes devem verificar o comportamento observável da aplicação, inclusive a tradução de um conflito de SKU para erro de validação.

A unicidade do SKU inclui registros excluídos logicamente. Um produto na lixeira continua reservando seu SKU para preservar a identidade comercial e permitir restauração sem conflito. O Form Request consulta os produtos existentes, inclusive os que estão na lixeira. Se duas gravações concorrentes passarem por essa consulta, a `UNIQUE` detecta a colisão remanescente e a aplicação a apresenta como erro de validação.

Não há inicialmente índice isolado em `is_active`. Índices de listagem e busca serão adicionados quando o volume ou os planos de consulta demonstrarem necessidade.

## 4. Regras dos campos

### 4.1 SKU

- obrigatório e único no catálogo, inclusive durante soft delete;
- até 32 caracteres;
- aceita apenas `A-Z`, `0-9`, `_` e `-` depois da normalização;
- tem espaços externos removidos;
- é convertido para maiúsculas;
- pode ser alterado após a criação porque integrações usam o UUIDv7 como vínculo.

No fluxo HTTP suportado, os Form Requests fazem trim, conversão para maiúsculas e validação de formato e unicidade. O model repete somente a normalização; ele não substitui a validação. As Actions assumem atributos validados. Durante uma corrida, a constraint `UNIQUE` funciona como salvaguarda de concorrência e `ProductSkuConflict` traduz sua violação em `ValidationException`, preservando o contrato da aplicação.

### 4.2 Nome e descrição

`name` é obrigatório, não é único, aceita acentos e capitalização e tem espaços externos removidos. O limite de entrada é 255 caracteres.

`description` é texto simples opcional. No fluxo HTTP de criação e edição, a regra `PlainText` dos Form Requests rejeita HTML e conteúdo rico. O mutator do model somente aplica trim e converte uma descrição vazia em `null`; as Actions não revalidam texto recebido. As views exibem o valor escapado.

### 4.3 Preço

`price_cents` armazena o preço atual de venda como inteiro em centavos de BRL. Por exemplo, R$ 19,90 é persistido como `1990`.

O valor deve ser inteiro e maior que zero. Não se usa `float` ou `double`. No fluxo HTTP suportado, o Form Request aplica a regra antes da persistência. As Actions assumem esse valor validado e não repetem a verificação; o `CHECK` da migration é defesa adicional para gravações inválidas que alcancem o banco. Histórico de preços não faz parte do modelo inicial; pedidos futuros preservarão o preço negociado em seus próprios itens.

### 4.4 Ativação e lixeira

`is_active` indica somente publicação no catálogo, não disponibilidade de estoque. Um produto ativo pode estar sem estoque, e mudanças de estoque nunca alteram esse campo.

`deleted_at` implementa a lixeira. Desativação e exclusão lógica são estados diferentes:

- produto inativo continua visível na administração e pode ser reativado;
- produto excluído fica oculto das consultas normais e pode ser restaurado;
- exclusão lógica força `is_active = false` e define `deleted_at` na mesma operação transacional;
- restauração limpa `deleted_at` e força `is_active = false`;
- exclusão física não existe no fluxo comum.

O código da aplicação mantém a regra de que um produto na lixeira não pode ser ativado nem restaurado como ativo. O `CHECK` correspondente no PostgreSQL é somente uma salvaguarda adicional.

## 5. Transições e operações

```text
Criar      -> persiste produto inativo
Editar     -> altera SKU, nome, descrição e preço, preservando is_active
Ativar     -> is_active = true
Desativar  -> is_active = false
Excluir    -> is_active = false + deleted_at = instante atual
Restaurar  -> deleted_at = null + is_active = false
```

As transições são implementadas pelas Actions da feature:

- `CreateProduct` ignora `is_active` recebido e cria sempre um produto inativo;
- `UpdateProduct` não altera `is_active`, inclusive quando o produto está ativo;
- `ActivateProduct` rejeita produtos na lixeira com `LogicException`;
- `TrashProduct` desativa e exclui logicamente dentro de uma transação;
- `RestoreProduct` só aceita um produto na lixeira e o restaura inativo dentro de uma transação;
- `DeactivateProduct` somente desativa, sem excluir logicamente.

Alterações de SKU, nome, descrição e preço valem imediatamente para novas compras. A etapa 1 usa a política de concorrência “última gravação vence”; não há versionamento do agregado nem optimistic locking.

## 6. CRUD web e rotas

O CRUD é exposto por rotas nomeadas server-rendered:

```text
GET     /products                             products.index
GET     /products/create                      products.create
POST    /products                             products.store
GET     /products/{product}                   products.show
GET     /products/{product}/edit              products.edit
PUT     /products/{product}                   products.update
DELETE  /products/{product}                   products.destroy
PATCH   /products/{product}/activate          products.activate
PATCH   /products/{product}/deactivate        products.deactivate
GET     /products-trash                       products.trash.index
PATCH   /products-trash/{product}/restore     products.trash.restore
```

As rotas normais usam o binding padrão do Eloquent e não encontram produtos na lixeira. A rota de restauração usa `withTrashed()` para permitir que a Action valide e execute a transição correta. Não há endpoint de exclusão física.

As telas de criação e edição expõem somente `sku`, `name`, `description` e `price_cents`. Não há campo de quantidade nem de `is_active` no formulário. A listagem normal omite produtos excluídos; a tela de lixeira lista somente produtos excluídos e oferece restauração.

## 7. Fronteiras com estoque e pedidos

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

## 8. Tratamento de erros

- validações de entrada devem falhar antes da persistência quando possível;
- erros de validação retornam ao formulário com mensagens por campo e preservação dos dados submetidos;
- uma colisão concorrente de SKU detectada pela constraint `UNIQUE` é traduzida por `ProductSkuConflict` em erro de validação compreensível para `sku`;
- exceções de banco não relacionadas ao SKU não são mascaradas pela aplicação;
- tentativas de ativar produto na lixeira falham com erro de domínio;
- tentativa de restaurar produto que não está na lixeira resulta em `404` no fluxo web;
- conflitos de edição não recebem tratamento especial na etapa 1 além da política “última gravação vence”.

## 9. Estratégia de testes

Os testes Pest cobrem o comportamento da aplicação através de suas Actions, rotas, requests e views. A suíte atual usa `RefreshDatabase` e PostgreSQL em `catalog_db_testing`, conforme `phpunit.xml`.

Os cenários existentes devem permanecer focados nestes contratos observáveis:

- `ProductPersistenceTest`: criação sempre inativa, preservação do estado ativo na edição, tradução de SKU duplicado para erro de validação, normalização de SKU/nome/descrição e ocultação de soft delete nas consultas normais;
- `ProductLifecycleTest`: ativação, desativação, rejeição de ativação na lixeira, exclusão lógica com desativação, ocultação da lixeira e restauração inativa;
- `ProductCrudTest`: listagem e telas, campos permitidos, criação e edição via HTTP, nome obrigatório, preço inválido, transições de status, lixeira, restauração, binding e paginação.

A rejeição de HTML em `description` está implementada pela regra `PlainText` nos Form Requests, mas a suíte atual não possui um cenário dedicado que cubra essa rejeição. O teste que usa uma descrição com `<script>` verifica o escape na view de detalhe, não a validação de entrada.

Novos testes devem continuar verificando efeitos visíveis para quem usa a aplicação: dados retornados, estado do model, redirecionamentos, mensagens e erros de validação. Não devem testar diretamente `CHECK`, `UNIQUE`, nomes de constraints, códigos internos do PostgreSQL ou qualquer outra funcionalidade interna do banco. O cenário de SKU duplicado deve permanecer formulado como contrato da Action — retornar erro de validação — sem tentar provar isoladamente o funcionamento da constraint. O uso do PostgreSQL no ambiente de testes exercita o fluxo real, inclusive a salvaguarda de concorrência existente, mas não transforma as constraints em fonte primária das regras.

## 10. Fora de escopo

- quantidade e movimentações de estoque;
- variantes e opções de produto;
- categorias, imagens e busca avançada;
- múltiplas moedas;
- custo de aquisição;
- histórico de preços;
- exclusão física pela aplicação;
- optimistic locking e auditoria de alterações;
- inicialização do `stock-service` na etapa 1;
- API pública do catálogo;
- testes de implementação interna ou de constraints do PostgreSQL.
