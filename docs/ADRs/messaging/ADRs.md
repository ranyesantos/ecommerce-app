# ADRs — messaging

Este arquivo é a fonte de verdade para as decisões de mensageria do projeto. Ele registra tanto o recorte que será implementado agora quanto decisões já aprovadas para a evolução com consumers. Itens futuros não autorizam antecipar sua implementação.

## Convenções de status

- **Aceita — agora:** faz parte da primeira implementação.
- **Aceita — futura:** a direção foi decidida, mas só será implementada quando o pré-requisito indicado existir.
- **Adiada:** a necessidade é conhecida, porém o desenho final será decidido depois.
- **Substituída:** foi discutida, mas deixou de ser a decisão vigente.
- **Concluída — histórica:** descreve um recorte já entregue, preservado apenas para explicar a evolução.

## Índice

| ID | Decisão | Status |
|---|---|---|
| ADR-MSG-001 | RabbitMQ com exchanges de comandos e eventos | Aceita — agora |
| ADR-MSG-002 | Topologia declarada centralmente | Aceita — agora |
| ADR-MSG-003 | Identidades, permissões e segredos | Aceita — agora |
| ADR-MSG-004 | Integração AMQP do Catálogo | Aceita — agora |
| ADR-MSG-005 | Contrato de `catalog.product.created` | Substituída |
| ADR-MSG-006 | Garantia e tratamento de falha do primeiro producer | Substituída |
| ADR-MSG-007 | Filas e bindings por consumer group | Aceita — agora |
| ADR-MSG-008 | Exchanges operacionais de comandos, retry e dead letter | Aceita — agora |
| ADR-MSG-009 | Escada de retries | Aceita — agora |
| ADR-MSG-010 | DLQ terminal por consumer group | Aceita — agora |
| ADR-MSG-011 | ACK, publisher confirm e idempotência do consumer | Aceita — agora |
| ADR-MSG-012 | Outbox transacional | Aceita — agora |
| ADR-MSG-015 | Provisionamento Shell com rabbitmqadmin | Aceita - agora |
| ADR-MSG-013 | Disponibilidade do RabbitMQ | Aceita — agora/futura |
| ADR-MSG-014 | Verificação da primeira implementação | Concluída — histórica |

## ADR-MSG-001 — RabbitMQ com exchanges de comandos e eventos

**Contexto:** o projeto precisa de um broker para comunicação assíncrona entre os domínios. Comandos possuem um responsável lógico, enquanto eventos relatam fatos e podem ter vários consumidores.

**Decisão:** usar RabbitMQ em um vhost dedicado `ecommerce`, com exchanges topic duráveis e não auto-delete. Comandos são publicados em `ecommerce.commands`; eventos de negócio, em `ecommerce.events`. O primeiro segmento da routing key identifica o contexto dono da capacidade ou do fato: `stock.item.initialize` é um comando endereçado ao Estoque, e `stock.item.initialized` é um fato ocorrido no Estoque.

**Consequências:** comandos têm um único consumer lógico, embora várias instâncias possam competir na mesma fila. Eventos podem ser entregues a zero, um ou vários consumer groups por bindings independentes. O tipo da exchange e o tempo verbal da routing key distinguem instruções de fatos.

## ADR-MSG-002 — Topologia declarada centralmente

**Contexto:** exchanges, filas, bindings e policies precisam ter uma fonte de verdade única e reproduzível.

**Decisão:** toda a topologia RabbitMQ será gerenciada centralmente por `/infra/rabbitmq/definitions.json`. Aplicações não declararão sua própria topologia e a integração Laravel não receberá permissão de `configure`.

Com o primeiro consumer, o arquivo também declarará `ecommerce.commands`, `ecommerce.retries`, `ecommerce.dead-letter`, filas, bindings e policies aprovados para Catálogo e Stock.

Alterações compatíveis serão importadas no broker em execução por automação idempotente. Apenas editar o arquivo montado não recarrega as definições. Não é necessário parar o RabbitMQ para adicionar recursos ou redeclarar recursos com os mesmos atributos; mudanças em atributos imutáveis exigirão uma migração de topologia.

**Consequências:** a infraestrutura controla e revisa a topologia completa. A alternativa híbrida — infraestrutura dona apenas das exchanges e cada aplicação dona de suas filas e bindings — foi substituída por esta decisão.

## ADR-MSG-003 — Identidades, permissões e segredos

**Contexto:** o producer não precisa administrar o broker nem consumir mensagens, e segredos não devem aparecer no `definitions.json` ou no Git.

**Decisão:** usar uma identidade por serviço. O Catálogo conecta como `catalog`, publica em `ecommerce.commands` e consome somente sua fila de resultados do Estoque. O Stock conecta como `stock`, consome somente sua fila de comandos e publica em `ecommerce.events`. Nenhuma aplicação recebe permissão de `configure`; a identidade administrativa usada no bootstrap e na Management UI é separada.

Credenciais locais ficam no `.env` da raiz para a orquestração e no `platform/.env` para o Laravel executado no host; ambos devem ser ignorados pelo Git. Arquivos `.env.example` contêm apenas placeholders não secretos. Em ambientes implantados, os segredos serão fornecidos pela pipeline ou por um secret manager.

Como `definitions.json` não interpola variáveis de ambiente, um serviço one-shot do Compose aguardará o healthcheck do RabbitMQ e usará a Management HTTP API para criar ou atualizar a identidade `catalog`, importar as definições e aplicar as permissões. As operações serão idempotentes e falharão explicitamente se a API não confirmar alguma etapa.

**Consequências:** comprometer a credencial de uma aplicação não concede controle da topologia. Adicionar um serviço exigirá criar sua identidade e a menor permissão necessária, além de atualizar as definições quando ele tiver recursos próprios.

## ADR-MSG-004 — Integração AMQP do Catálogo

**Contexto:** RabbitMQ transportará eventos de integração, mas não será o backend dos jobs internos do Laravel.

**Decisão:** manter `config/queue.php` e o driver `database` para jobs Laravel. A integração usa `php-amqplib/php-amqplib`, configuração própria em `platform/config/rabbitmq.php` e portas pequenas de publicação e consumo. Controllers e regras de negócio não acessam primitivas AMQP diretamente. O publisher existente orientado a eventos será generalizado para publicar as mensagens imutáveis da Outbox.

A configuração será alimentada por `RABBITMQ_HOST`, `RABBITMQ_PORT`, `RABBITMQ_VHOST`, `RABBITMQ_USER`, `RABBITMQ_PASSWORD`, `RABBITMQ_CONNECTION_TIMEOUT`, `RABBITMQ_READ_TIMEOUT`, `RABBITMQ_WRITE_TIMEOUT` e `RABBITMQ_PUBLISHER_CONFIRM_TIMEOUT`.

Não haverá feature flag. Inbox e Outbox usam o PostgreSQL local do Catálogo; o driver de jobs do Laravel continua separado dessas mensagens de integração.

**Consequências:** produtores gravam intenções na Outbox em vez de publicar dentro da requisição, e consumers atualizam estado local antes do ACK.

## ADR-MSG-005 — Contrato de `catalog.product.created`

> **Substituída:** enquanto não existir consumidor concreto, `catalog.product.created` deixa de ser publicado. O cadastro grava `stock.item.initialize` na Outbox; o contrato abaixo permanece apenas como registro histórico da primeira integração.

**Contexto:** consumers independentes precisam identificar, rastrear e evoluir eventos sem depender do modelo interno do Laravel.

**Decisão:** publicar JSON UTF-8 com `content_type=application/json`, mensagem persistente (`delivery_mode=2`) e o seguinte envelope versionado:

```json
{
  "event_id": "0198...",
  "event_type": "catalog.product.created",
  "event_version": 1,
  "occurred_at": "2026-08-24T17:30:00.000Z",
  "correlation_id": "0198...",
  "payload": {
    "product_id": "0198...",
    "sku": "TEC-001",
    "name": "Teclado mecânico",
    "description": "Teclado ABNT2",
    "price_cents": 29990,
    "is_active": false
  }
}
```

`event_id` identifica a ocorrência; `occurred_at` usa UTC em ISO 8601; `correlation_id` aproveita um contexto propagado ou é criado para a operação. O payload é um snapshot do produto e não contém quantidade de estoque. Dinheiro é transportado como inteiro em centavos.

Campos opcionais compatíveis podem ser adicionados à versão 1 desde que consumers ignorem campos desconhecidos. Mudanças incompatíveis criam uma nova versão; versões antiga e nova coexistem durante a migração. A routing key e o `event_type` continuam `catalog.product.created`.

**Consequências:** evolução de schema não exige trocar o endereço do evento. Consumers precisam validar as versões que suportam.

## ADR-MSG-006 — Garantia e tratamento de falha do primeiro producer

> **Substituída por ADR-MSG-012:** a publicação best-effort deixou de ser aceitável quando o primeiro fluxo dependente de entrega foi aprovado.

**Contexto:** sem Outbox existe uma janela entre o commit do banco e a publicação no broker.

**Decisão:** publicar sincronamente somente depois do commit PostgreSQL e aguardar publisher confirm. O `mandatory` ficará `false` porque a ausência de bindings é esperada nesta etapa.

Publisher confirm é solicitado e aguardado pela aplicação; o broker responde com confirmação positiva ou negativa. Ele comprova que o broker aceitou a publicação, mas não comprova consumo e, sem fila correspondente, não comprova armazenamento da mensagem.

Se conexão, publicação ou confirmação falhar depois do commit, o produto permanece criado e a resposta de criação continua bem-sucedida. A aplicação registra erro estruturado com `event_id`, `product_id`, `correlation_id`, exchange, routing key e exceção. Não há compensação nem recuperação automática.

**Consequências:** a entrega inicial é best-effort e um evento pode ser perdido. Essa limitação é aceita somente enquanto nenhum consumer depende do evento.

## ADR-MSG-007 — Filas e bindings por consumer group

**Contexto:** um evento pode interessar a vários serviços, comandos possuem um responsável lógico e instâncias do mesmo consumer group devem competir pela mesma fila.

**Decisão:** criar uma fila durável por consumer group e bindings explícitos. Para entregar a mesma routing key a duas filas, cada fila terá seu próprio binding para essa key. Várias instâncias do mesmo grupo compartilham uma única fila. O primeiro fluxo usa `stock.commands`, ligada a `stock.item.initialize`, e `catalog.stock-events`, ligada aos resultados da inicialização.

**Consequências:** fan-out é controlado pelos bindings, e filas representam consumidores lógicos em vez de tipos de mensagem.

## ADR-MSG-008 — Exchanges operacionais de comandos, retry e dead letter

**Contexto:** eventos novos são roteados pelo tipo de fato e podem ter vários destinos; uma tentativa de retry deve voltar ao consumer group específico que falhou.

**Decisão:** separar responsabilidades em quatro exchanges:

- `ecommerce.commands`, topic, para comandos endereçados ao contexto responsável;
- `ecommerce.events`, topic, para eventos de negócio;
- `ecommerce.retries`, direct, compartilhada entre os serviços, para transportar tentativas ao consumer group de destino;
- `ecommerce.dead-letter`, direct, compartilhada, para encaminhamento terminal à DLQ do consumer group.

A regra de “um destino” no retry será garantida pela topologia: cada routing key de retorno terá exatamente um binding. Uma exchange direct tecnicamente permite mais de um binding igual, portanto não garante unicidade sozinha.

**Consequências:** uma única exchange seria tecnicamente possível, mas misturaria endereçamento por evento com endereçamento operacional. O uso da exchange default para o retorno também foi descartado em favor de uma exchange nomeada e explícita.

## ADR-MSG-009 — Escada de retries

**Contexto:** falhas transitórias devem esperar antes de uma nova tentativa, sem bloquear a fila principal nem criar retry infinito.

**Decisão:** cada consumer group terá três filas de retry, nomeadas por estágio — `.retry.1`, `.retry.2` e `.retry.3` — com janelas de `5s`, `30s` e `2m`. Os TTLs serão aplicados centralmente por policies do broker, permitindo alterar a duração sem renomear filas.

Para cada consumer group, a aplicação publica em `ecommerce.retries` usando `<consumer>.retry.1`, `.retry.2` ou `.retry.3`. Depois do TTL, a mensagem volta pela mesma exchange com a routing key da fila principal. O tipo da mensagem permanece inalterado; routing keys de retry são endereços de transporte. O header `retry_attempt`, controlado pela aplicação, assume `1`, `2` ou `3`.

Erros transitórios percorrem a escada. Erros inicialmente desconhecidos são tratados como transitórios. Depois da falha na terceira tentativa, a mensagem vai para DLQ.

**Consequências:** todos os serviços começam com as mesmas janelas, mas mantêm filas independentes por consumer group. Retry não reenvia o evento a outros consumers que já o processaram.

## ADR-MSG-010 — DLQ terminal por consumer group

**Contexto:** mensagens inválidas ou com retries esgotados precisam sair do fluxo automático sem desaparecer.

**Decisão:** criar uma DLQ durável, persistente, sem auto-delete e inicialmente sem TTL para cada consumer group. Erros permanentes — payload, schema ou versão inválidos e campos obrigatórios ausentes — vão diretamente para a DLQ. Erros transitórios chegam à DLQ apenas depois da terceira tentativa.

Não haverá consumer automático de DLQ. O procedimento e a ferramenta de inspeção, correção e replay serão decididos somente depois que houver mensagens reais em DLQ.

**Consequências:** isolamento e diagnóstico ficam específicos por consumidor. Replay manual foi deliberadamente adiado e não faz parte da implementação atual.

## ADR-MSG-011 — ACK, publisher confirm e idempotência do consumer

**Contexto:** um consumer pode cair durante o processamento ou durante a transferência de uma mensagem para retry.

**Decisão:** consumers usarão entrega at-least-once e ACK manual. No sucesso ou em uma recusa de negócio definitiva, o ACK ocorrerá somente depois do commit dos efeitos locais. Cada serviço mantém `inbox_messages`, com unicidade por `(message_id, consumer_name)`, além de tipo, versão, hash canônico do conteúdo e instante de processamento. A mesma identidade e o mesmo conteúdo são uma reentrega idempotente; a mesma identidade com outro conteúdo produz `MESSAGE_PAYLOAD_MISMATCH` e segue para DLQ.

Em falha transitória, a ordem segura será:

1. receber a mensagem original;
2. publicar a tentativa na exchange de retry;
3. aguardar o publisher confirm dessa nova publicação;
4. somente então dar ACK na mensagem original.

O consumer atua como publisher no passo de retry. Publisher confirm e consumer ACK são confirmações diferentes: a primeira vem do broker para quem publicou; a segunda vem do consumer para o broker.

**Consequências:** se o consumer cair antes do ACK, o RabbitMQ reentrega a original. Duplicatas continuam possíveis, portanto a Inbox é obrigatória quando o primeiro consumer for implementado.

## ADR-MSG-012 — Outbox transacional

**Contexto:** a publicação best-effort não elimina a janela de dual-write entre banco e broker.

**Decisão:** implementar `outbox_messages` no Laravel e no Stock agora que existe um fluxo que depende da entrega. A Outbox é gravada na mesma transação do estado de negócio e armazena a mensagem completa e imutável: identidade, exchange, routing key, tipo, versão, body, headers, criação, próxima tentativa, publicação, número de tentativas e último erro. Um dispatcher aguarda publisher confirm antes de preencher `published_at`.

Uma falha entre o confirm e a marcação de `published_at` ainda pode duplicar a publicação. O mesmo `message_id` e consumers idempotentes tratam esse caso. Inbox e Outbox não possuem limpeza automática neste incremento.

**Consequências:** a entrega deixa de depender do processo web ou da transação de negócio permanecer aberta durante acesso ao broker. Cada serviço possui suas próprias tabelas no próprio PostgreSQL; não existe banco de mensageria compartilhado.

## ADR-MSG-013 — Disponibilidade do RabbitMQ

**Contexto:** desenvolvimento local e produção têm exigências diferentes de disponibilidade.

**Decisão:** usar um único nó RabbitMQ nesta etapa local, com volume persistente, healthcheck, porta AMQP `5672` e Management UI em `15672`. Esse ambiente não oferece alta disponibilidade.

Se alta disponibilidade for exigida futuramente, avaliar um cluster de pelo menos três nós em domínios de falha distintos e quorum queues para as filas que precisarem de replicação. Três nós toleram a falha de um membro; a regra `2f + 1` é específica de sistemas baseados em quórum e não é uma regra universal para toda redundância.

**Consequências:** a queda do único broker interrompe novas publicações e consumos até sua recuperação. A queda de um consumer não perde mensagens já enfileiradas e não confirmadas, que poderão ser reentregues quando houver consumer novamente.

## ADR-MSG-014 — Verificação da primeira implementação

> **Registro histórico concluído:** estes critérios verificaram o producer órfão original. O incremento corrente segue a spec de inicialização do Stock e substitui esse recorte.

**Contexto:** a primeira entrega precisa comprovar tanto o contrato do producer quanto a configuração reproduzível do broker, mesmo sem uma fila consumidora.

**Decisão:** a implementação terá testes automatizados para serialização exata do envelope v1; identificadores e timestamp UTC; snapshot do produto; publicação somente depois da persistência; exchange e routing key; mensagem persistente e publisher confirm; manutenção do produto quando o publisher falha; e conteúdo mínimo do log estruturado.

O ambiente terá validação do `compose.yaml`, healthcheck do RabbitMQ e smoke test que comprove a existência do vhost `ecommerce` e da exchange `ecommerce.events` aplicados pelo `definitions.json`. A imagem RabbitMQ Management terá versão fixada na implementação.

Testes de consumer, ACK, retry, DLQ, Inbox e Outbox ficam para as etapas em que esses componentes existirem.

**Consequências:** a primeira implementação pode ser verificada sem criar uma fila artificial que alteraria o comportamento arquitetural aprovado.

## Decisões substituídas ou explicitamente descartadas

| Proposta discutida | Decisão vigente |
|---|---|
| Aplicações declaram suas filas e bindings | Toda a topologia fica em `definitions.json` |
| Uma única exchange para eventos, retries e DLQ | Exchanges separadas por responsabilidade quando o fluxo operacional existir |
| Usar a exchange default para devolver retries | Usar `ecommerce.retries` com routing key do consumer group |
| Nomes legados `inventory.catalog.main` ou `inventory.catalog` | Usar filas por responsabilidade, começando por `stock.commands` e `catalog.stock-events` |
| RabbitMQ como driver de Laravel Queue | Integração AMQP direta e isolada para mensagens entre contextos |
| Feature flag para ativar a publicação | Sem feature flag |
| Outbox antes de existir fluxo dependente | Outbox ativada junto ao primeiro fluxo dependente |
| Criar retry e DLQ antes de haver consumer | Criar junto com o primeiro consumer |
| Definir agora o comando de replay da DLQ | Decidir após existirem mensagens reais na DLQ |

## Escopo do incremento com o primeiro consumer

Incluído:

- broker RabbitMQ local em execução;
- vhost `ecommerce`;
- exchanges `ecommerce.commands`, `ecommerce.events`, `ecommerce.retries` e `ecommerce.dead-letter`;
- identidades restritas de Catálogo e Stock;
- filas `stock.commands`, `catalog.stock-events`, seus três estágios de retry e suas DLQs;
- comando `stock.item.initialize` e eventos de resultado da inicialização;
- Inbox, Outbox, ACK manual, mensagem persistente e publisher confirm;
- retries de `5s`, `30s` e `2m`.

Não incluído:

- feature flag;
- consumer ou publicação de `catalog.product.created`;
- limpeza automática de Inbox e Outbox;
- replay automático da DLQ;
- cluster, quorum queues e alta disponibilidade.

## ADR-MSG-015 -- Provisionamento Shell com rabbitmqadmin

A imagem Management Alpine pressupoe o binario rabbitmqadmin v2 nas plataformas amd64 e arm64. Essas sao as plataformas suportadas para o setup local nesta etapa; outras arquiteturas exigirao uma imagem auxiliar com o binario nativo correspondente.

Adendo vigente: a topologia continua tendo definitions.json como fonte de verdade, mas a primeira implementacao importa esse arquivo por um servico one-shot do Compose depois que o broker cria a identidade administrativa padrao. O mesmo servico executa provision-identity.sh com rabbitmqadmin para importar a topologia, criar ou atualizar catalog e stock e aplicar suas permissoes.

O projeto nao usara arquivo Python nem colocara senhas ou password_hash em definitions.json. A importacao nativa no boot foi descartada para este recorte porque um no vazio que importa definicoes no boot pode nao criar o usuario padrao necessario para autenticar o provisionador. Uma futura pipeline pode substituir o servico one-shot sem alterar o contrato do producer.
