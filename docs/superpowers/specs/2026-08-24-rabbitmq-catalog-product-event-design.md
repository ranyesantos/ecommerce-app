# RabbitMQ e evento de criação de produto — design

**Data:** 2026-08-24
**Status:** aprovado para planejamento
**Escopo:** broker RabbitMQ local e publicação de `catalog.product.created` pelo Catálogo

## 1. Objetivo

Este incremento coloca um broker RabbitMQ no ambiente local e faz o Laravel publicar um evento quando um produto é criado. Ele estabelece a infraestrutura e o contrato do primeiro producer sem antecipar consumers, filas, bindings, retry, DLQ ou Outbox.

Esta especificação reduz deliberadamente o recorte “Etapa 2a — Broker no ar” de `2026-08-20-platform-design.md`. As decisões arquiteturais futuras sobre consumer groups, idempotência, retry, DLQ e Outbox continuam válidas, mas não fazem parte desta implementação.

## 2. Critérios de conclusão

O incremento está concluído quando:

1. `docker compose up` executado na raiz sobe o PostgreSQL existente e um RabbitMQ de nó único.
2. A Management UI e o healthcheck do RabbitMQ indicam que o broker está disponível.
3. O vhost `ecommerce` e a exchange topic durável `ecommerce.events` são aplicados a partir de `definitions.json`.
4. Criar um produto no Laravel publica `catalog.product.created` depois do commit do PostgreSQL.
5. A publicação usa JSON, mensagem persistente e publisher confirm.
6. A criação do produto continua bem-sucedida se a publicação falhar; a falha é registrada de forma estruturada.
7. Testes automatizados comprovam o contrato do evento, o acionamento após a criação e o tratamento de falha do publisher.

## 3. Escopo da infraestrutura

A orquestração compartilhada fica na raiz do monorepo:

```text
inventory-lab/
├── compose.yaml
├── .env
├── .env.example
├── infra/
│   ├── postgres/
│   │   └── init-test-database.sql
│   └── rabbitmq/
│       └── definitions.json
└── platform/
```

O `platform/compose.yaml` atual será incorporado ao `compose.yaml` da raiz. O volume PostgreSQL existente não será removido. O novo Compose usará nome explícito para reutilizar `platform_catalog_db_data` quando ele já existir e criá-lo quando não existir.

O RabbitMQ terá:

- uma imagem Management com versão fixada no plano de implementação;
- portas AMQP `5672` e Management `15672` expostas localmente;
- volume nomeado persistente;
- healthcheck;
- um único nó, adequado a desenvolvimento e sem promessa de alta disponibilidade.

Alta disponibilidade, cluster de três nós e quorum queues ficam fora deste incremento.

## 4. Topologia RabbitMQ

O arquivo versionado `/infra/rabbitmq/definitions.json` é a fonte de verdade da topologia. Nesta etapa ele contém apenas:

```text
vhost:     ecommerce
exchange:  ecommerce.events
type:      topic
durable:   true
auto_delete: false
```

Não serão declaradas filas nem bindings. Consequentemente, uma publicação aceita pela exchange será não roteável e descartada. Esse comportamento é intencional enquanto não existir um consumer group interessado.

Novas definições poderão ser importadas no broker em execução; modificar o arquivo montado não implica leitura automática. A automação de infraestrutura deverá importar a versão atualizada sem reiniciar o RabbitMQ.

## 5. Identidade e segredos

O Laravel conecta usando a identidade de serviço `catalog` no vhost `ecommerce`. A aplicação não recebe permissão para configurar topologia ou consumir filas. Sua permissão de escrita é limitada a `ecommerce.events`.

Credenciais não são versionadas em `definitions.json` nem em arquivos de exemplo:

- `/.env` contém segredos usados pela orquestração local e é ignorado pelo Git;
- `/platform/.env` contém a credencial usada pelo Laravel executado diretamente na máquina e já é ignorado pelo Git;
- os respectivos `.env.example` contêm somente nomes e valores não secretos;
- ambientes implantados recebem credenciais de um secret manager ou da pipeline.

Como o RabbitMQ não interpola variáveis dentro de `definitions.json`, a topologia pública será importada depois que o broker iniciar com uma identidade administrativa de bootstrap fornecida pelo ambiente. Um serviço one-shot do Compose aguardará o healthcheck e usará a HTTP API de Management para:

1. criar ou atualizar a identidade `catalog` com a senha injetada;
2. importar `/infra/rabbitmq/definitions.json`;
3. aplicar ao usuário `catalog` permissão vazia de `configure` e `read`, e permissão de `write` limitada a `ecommerce.events` no vhost `ecommerce`;
4. terminar com falha se qualquer operação não for confirmada pela API.

As credenciais administrativas servem apenas ao bootstrap e à Management UI local; o Laravel recebe somente a identidade restrita `catalog`. As operações serão idempotentes e o serviço poderá rodar em todo `docker compose up` sem reiniciar o broker nem recriar recursos compatíveis.

## 6. Integração Laravel

RabbitMQ não será configurado como driver de Jobs do Laravel. `config/queue.php` e a conexão `database` permanecem responsáveis por jobs internos.

O projeto adicionará `php-amqplib/php-amqplib` e uma integração AMQP pequena e explícita:

```text
CreateProductAction
        │ produto persistido e commit concluído
        ▼
ProductCreated
        ▼
EventPublisher
        ▼
RabbitMqEventPublisher
        ▼
ecommerce.events
```

`EventPublisher` impede que controllers e regras de negócio dependam diretamente de canais AMQP. Não haverá `IntegrationEventRecorder`, feature flag ou camada de Outbox neste incremento.

A configuração de conexão ficará em `platform/config/rabbitmq.php`, alimentada por:

```text
RABBITMQ_HOST
RABBITMQ_PORT
RABBITMQ_VHOST
RABBITMQ_USER
RABBITMQ_PASSWORD
RABBITMQ_CONNECTION_TIMEOUT
RABBITMQ_READ_TIMEOUT
RABBITMQ_WRITE_TIMEOUT
```

## 7. Contrato `catalog.product.created`

A routing key é:

```text
catalog.product.created
```

O envelope segue ADR-014:

```json
{
  "event_id": "018f...",
  "event_type": "catalog.product.created",
  "event_version": 1,
  "occurred_at": "2026-08-24T17:30:00.000Z",
  "correlation_id": "018f...",
  "payload": {
    "product_id": "018f...",
    "sku": "TEC-001",
    "name": "Teclado mecânico",
    "description": "Teclado ABNT2",
    "price_cents": 29990,
    "is_active": false
  }
}
```

Regras do contrato:

- `event_id` identifica unicamente a ocorrência;
- `event_type` permanece `catalog.product.created` durante todo o ciclo de vida;
- `event_version` começa em `1` e aumenta em mudanças incompatíveis;
- `occurred_at` usa UTC em ISO 8601;
- `correlation_id` é criado para a operação quando ainda não existe contexto propagado;
- `payload` é um snapshot do produto no momento da criação;
- dinheiro permanece inteiro em `price_cents`;
- quantidade de estoque não pertence ao evento.

O conteúdo será codificado como JSON UTF-8 com `content_type=application/json`, `delivery_mode=2` e identificadores também expostos nas propriedades/cabeçalhos AMQP quando isso facilitar rastreamento sem duplicar decisões de negócio.

## 8. Publicação e falhas

A ordem é:

1. validar os dados;
2. persistir o produto;
3. concluir o commit PostgreSQL;
4. construir o envelope;
5. publicar em `ecommerce.events` com routing key `catalog.product.created`;
6. aguardar publisher confirm;
7. finalizar a resposta web.

`mandatory` ficará desabilitado nesta etapa porque a ausência de bindings é esperada. Publisher confirm comprova que o broker aceitou a publicação; não comprova consumo nem armazenamento quando nenhuma fila corresponde.

Se a conexão, publicação ou confirmação falhar depois do commit:

- o produto permanece criado;
- a resposta web mantém o resultado de criação;
- o erro é registrado com `event_id`, `product_id`, `correlation_id`, exchange, routing key e exceção;
- não há tentativa de compensação ou recuperação automática.

Essa é uma publicação best-effort conscientemente limitada. Existe uma janela de dual-write e o evento pode ser perdido. Outbox é a evolução prevista quando houver um consumidor que dependa da entrega.

## 9. Estratégia de testes

Os testes cobrem:

- serialização exata do envelope v1;
- UUIDs, timestamp UTC, tipo, versão e snapshot do produto;
- publicação somente depois de uma criação persistida;
- exchange e routing key corretas;
- configuração de mensagem persistente e publisher confirm no adaptador;
- falha do publisher não desfaz nem oculta o produto criado;
- log estruturado contém os identificadores necessários;
- configuração Docker válida e healthcheck do RabbitMQ;
- smoke test do broker comprovando vhost e exchange declarados pelo `definitions.json`.

Testes de consumer, ACK, retry, DLQ, inbox e Outbox não pertencem a esta etapa.

## 10. Fora de escopo

### Adendo de implementacao

Nesta primeira implementacao, definitions.json sera importado por rabbitmqadmin no servico one-shot apos o broker estar saudavel. O script Shell tambem provisionara a identidade catalog e suas permissoes. O RabbitMQ nao dependera de arquivo Python, e credenciais nao serao gravadas nas definicoes.

- filas e bindings;
- qualquer consumer;
- `ecommerce.retries` e `ecommerce.dead-letter`;
- filas TTL, retry e DLQ;
- header `retry_attempt`;
- replay de DLQ;
- Inbox e idempotência de consumer;
- Outbox e worker de publicação;
- feature flags;
- observabilidade além de logs estruturados;
- cluster RabbitMQ, quorum queues e alta disponibilidade;
- publicação retroativa dos produtos existentes.

## 11. Evolução para o primeiro consumer

Quando um serviço consumidor for criado, a infraestrutura adicionará ao `definitions.json` sua fila e o binding para `catalog.product.created`. O RabbitMQ poderá receber essa atualização em execução. O produtor, a routing key e o envelope v1 permanecerão inalterados.

Retry, DLQ, `retry_attempt`, ACK manual e Inbox serão desenhados e implementados junto com esse consumer, quando existir processamento real que permita validar o fluxo de falha de ponta a ponta.
