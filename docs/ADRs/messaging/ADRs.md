# ADRs — messaging

Este arquivo é a fonte de verdade para as decisões de mensageria do projeto. Ele registra tanto o recorte que será implementado agora quanto decisões já aprovadas para a evolução com consumers. Itens futuros não autorizam antecipar sua implementação.

## Convenções de status

- **Aceita — agora:** faz parte da primeira implementação.
- **Aceita — futura:** a direção foi decidida, mas só será implementada quando o pré-requisito indicado existir.
- **Adiada:** a necessidade é conhecida, porém o desenho final será decidido depois.
- **Substituída:** foi discutida, mas deixou de ser a decisão vigente.

## Índice

| ID | Decisão | Status |
|---|---|---|
| ADR-MSG-001 | RabbitMQ e exchange central de eventos | Aceita — agora |
| ADR-MSG-002 | Topologia declarada centralmente | Aceita — agora |
| ADR-MSG-003 | Identidades, permissões e segredos | Aceita — agora |
| ADR-MSG-004 | Integração AMQP do Catálogo | Aceita — agora |
| ADR-MSG-005 | Contrato de `catalog.product.created` | Aceita — agora |
| ADR-MSG-006 | Garantia e tratamento de falha do primeiro producer | Aceita — agora |
| ADR-MSG-007 | Filas e bindings por consumer group | Aceita — futura |
| ADR-MSG-008 | Exchanges operacionais de retry e dead letter | Aceita — futura |
| ADR-MSG-009 | Escada de retries | Aceita — futura |
| ADR-MSG-010 | DLQ terminal por consumer group | Aceita — futura |
| ADR-MSG-011 | ACK, publisher confirm e idempotência do consumer | Aceita — futura |
| ADR-MSG-012 | Outbox transacional | Adiada |
| ADR-MSG-015 | Provisionamento Shell com rabbitmqadmin | Aceita - agora |
| ADR-MSG-013 | Disponibilidade do RabbitMQ | Aceita — agora/futura |
| ADR-MSG-014 | Verificação da primeira implementação | Aceita — agora |

## ADR-MSG-001 — RabbitMQ e exchange central de eventos

**Contexto:** o projeto precisa de um broker para comunicação assíncrona entre os domínios. Nesta primeira implementação existe apenas um producer: o Catálogo.

**Decisão:** usar RabbitMQ em um vhost dedicado `ecommerce`. Todos os eventos de negócio serão publicados na exchange topic durável, não auto-delete, `ecommerce.events`, usando routing keys por tipo de evento. A primeira routing key será `catalog.product.created`.

Nesta etapa o broker ficará em execução e a exchange existirá, mas nenhuma fila, binding ou consumer será criado. Portanto, o evento aceito pela exchange será não roteável e descartado; isso é intencional até existir um consumer real.

**Consequências:** novos consumer groups poderão receber o mesmo evento por bindings independentes, sem alteração no producer. Não haverá retenção, publicação retroativa nem replay automático dos eventos publicados antes da primeira fila existir.

## ADR-MSG-002 — Topologia declarada centralmente

**Contexto:** exchanges, filas, bindings e policies precisam ter uma fonte de verdade única e reproduzível.

**Decisão:** toda a topologia RabbitMQ será gerenciada centralmente por `/infra/rabbitmq/definitions.json`. Aplicações não declararão sua própria topologia e a integração Laravel não receberá permissão de `configure`.

Nesta etapa o arquivo declarará apenas o vhost `ecommerce` e a exchange `ecommerce.events`. Filas, bindings, exchanges operacionais e policies futuras serão adicionadas ao mesmo arquivo quando entrarem no escopo.

Alterações compatíveis serão importadas no broker em execução por automação idempotente. Apenas editar o arquivo montado não recarrega as definições. Não é necessário parar o RabbitMQ para adicionar recursos ou redeclarar recursos com os mesmos atributos; mudanças em atributos imutáveis exigirão uma migração de topologia.

**Consequências:** a infraestrutura controla e revisa a topologia completa. A alternativa híbrida — infraestrutura dona apenas das exchanges e cada aplicação dona de suas filas e bindings — foi substituída por esta decisão.

## ADR-MSG-003 — Identidades, permissões e segredos

**Contexto:** o producer não precisa administrar o broker nem consumir mensagens, e segredos não devem aparecer no `definitions.json` ou no Git.

**Decisão:** usar uma identidade por serviço. O Catálogo conecta como `catalog`, no vhost `ecommerce`, com `write` limitado a `ecommerce.events` e sem permissões de `configure` ou `read`. A identidade administrativa usada no bootstrap e na Management UI é separada.

Credenciais locais ficam no `.env` da raiz para a orquestração e no `platform/.env` para o Laravel executado no host; ambos devem ser ignorados pelo Git. Arquivos `.env.example` contêm apenas placeholders não secretos. Em ambientes implantados, os segredos serão fornecidos pela pipeline ou por um secret manager.

Como `definitions.json` não interpola variáveis de ambiente, um serviço one-shot do Compose aguardará o healthcheck do RabbitMQ e usará a Management HTTP API para criar ou atualizar a identidade `catalog`, importar as definições e aplicar as permissões. As operações serão idempotentes e falharão explicitamente se a API não confirmar alguma etapa.

**Consequências:** comprometer a credencial de uma aplicação não concede controle da topologia. Adicionar um serviço exigirá criar sua identidade e a menor permissão necessária, além de atualizar as definições quando ele tiver recursos próprios.

## ADR-MSG-004 — Integração AMQP do Catálogo

**Contexto:** RabbitMQ transportará eventos de integração, mas não será o backend dos jobs internos do Laravel.

**Decisão:** manter `config/queue.php` e o driver `database` para jobs Laravel. A publicação de eventos usará `php-amqplib/php-amqplib`, configuração própria em `platform/config/rabbitmq.php` e uma porta pequena `EventPublisher`, implementada por `RabbitMqEventPublisher`. Controllers e regras de negócio não acessarão primitivas AMQP diretamente.

A configuração será alimentada por `RABBITMQ_HOST`, `RABBITMQ_PORT`, `RABBITMQ_VHOST`, `RABBITMQ_USER`, `RABBITMQ_PASSWORD`, `RABBITMQ_CONNECTION_TIMEOUT`, `RABBITMQ_READ_TIMEOUT`, `RABBITMQ_WRITE_TIMEOUT` e `RABBITMQ_PUBLISHER_CONFIRM_TIMEOUT`.

Não haverá feature flag, `IntegrationEventRecorder`, fila intermediária, consumer ou Outbox nesta implementação.

**Consequências:** o adaptador poderá ser reutilizado por um futuro worker de Outbox sem alterar o contrato de negócio. A solução inicial permanece pequena e explícita.

## ADR-MSG-005 — Contrato de `catalog.product.created`

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

**Contexto:** sem Outbox existe uma janela entre o commit do banco e a publicação no broker.

**Decisão:** publicar sincronamente somente depois do commit PostgreSQL e aguardar publisher confirm. O `mandatory` ficará `false` porque a ausência de bindings é esperada nesta etapa.

Publisher confirm é solicitado e aguardado pela aplicação; o broker responde com confirmação positiva ou negativa. Ele comprova que o broker aceitou a publicação, mas não comprova consumo e, sem fila correspondente, não comprova armazenamento da mensagem.

Se conexão, publicação ou confirmação falhar depois do commit, o produto permanece criado e a resposta de criação continua bem-sucedida. A aplicação registra erro estruturado com `event_id`, `product_id`, `correlation_id`, exchange, routing key e exceção. Não há compensação nem recuperação automática.

**Consequências:** a entrega inicial é best-effort e um evento pode ser perdido. Essa limitação é aceita somente enquanto nenhum consumer depende do evento.

## ADR-MSG-007 — Filas e bindings por consumer group

**Contexto:** um evento pode interessar a vários serviços, enquanto instâncias do mesmo consumer group devem competir pela mesma fila.

**Decisão:** quando existir o primeiro consumer, criar uma fila durável por consumer group e bindings explícitos. Para entregar a mesma routing key a duas filas, cada fila terá seu próprio binding para essa key. Várias instâncias do mesmo grupo compartilham uma única fila.

O nome `inventory.catalog` foi adotado como exemplo de fila normal do consumer group de inventário para eventos do Catálogo. Ele é nome de fila/endereço de transporte, não `event_type`. O nome anteriormente discutido `inventory.catalog.main` foi substituído por `inventory.catalog`.

**Consequências:** fan-out é controlado pelos bindings. A fila `inventory.catalog` só será criada quando o serviço consumidor correspondente for implementado.

## ADR-MSG-008 — Exchanges operacionais de retry e dead letter

**Contexto:** eventos novos são roteados pelo tipo de fato e podem ter vários destinos; uma tentativa de retry deve voltar ao consumer group específico que falhou.

**Decisão:** quando retries e DLQ entrarem no escopo, separar responsabilidades em três exchanges:

- `ecommerce.events`, topic, para eventos de negócio;
- `ecommerce.retries`, direct, compartilhada entre os serviços, para transportar tentativas ao consumer group de destino;
- `ecommerce.dead-letter`, direct, compartilhada, para encaminhamento terminal à DLQ do consumer group.

A regra de “um destino” no retry será garantida pela topologia: cada routing key de retorno terá exatamente um binding. Uma exchange direct tecnicamente permite mais de um binding igual, portanto não garante unicidade sozinha.

**Consequências:** uma única exchange seria tecnicamente possível, mas misturaria endereçamento por evento com endereçamento operacional. O uso da exchange default para o retorno também foi descartado em favor de uma exchange nomeada e explícita.

## ADR-MSG-009 — Escada de retries

**Contexto:** falhas transitórias devem esperar antes de uma nova tentativa, sem bloquear a fila principal nem criar retry infinito.

**Decisão:** cada consumer group terá três filas de retry, nomeadas por estágio — `.retry.1`, `.retry.2` e `.retry.3` — com janelas de `5s`, `30s` e `2m`. Os TTLs serão aplicados centralmente por policies do broker, permitindo alterar a duração sem renomear filas.

Para o exemplo `inventory.catalog`, a aplicação publica em `ecommerce.retries` usando `inventory.catalog.retry.1`, `.retry.2` ou `.retry.3`. Depois do TTL, a mensagem volta pela mesma exchange com routing key `inventory.catalog`, ligada somente à fila principal. O `event_type` permanece `catalog.product.created`; routing keys de retry são endereços de transporte. O header `retry_attempt`, controlado pela aplicação, assume `1`, `2` ou `3`.

Erros transitórios percorrem a escada. Erros inicialmente desconhecidos são tratados como transitórios. Depois da falha na terceira tentativa, a mensagem vai para DLQ.

**Consequências:** todos os serviços começam com as mesmas janelas, mas mantêm filas independentes por consumer group. Retry não reenvia o evento a outros consumers que já o processaram.

## ADR-MSG-010 — DLQ terminal por consumer group

**Contexto:** mensagens inválidas ou com retries esgotados precisam sair do fluxo automático sem desaparecer.

**Decisão:** criar uma DLQ durável, persistente, sem auto-delete e inicialmente sem TTL para cada consumer group. Erros permanentes — payload, schema ou versão inválidos e campos obrigatórios ausentes — vão diretamente para a DLQ. Erros transitórios chegam à DLQ apenas depois da terceira tentativa.

Não haverá consumer automático de DLQ. O procedimento e a ferramenta de inspeção, correção e replay serão decididos somente depois que houver mensagens reais em DLQ.

**Consequências:** isolamento e diagnóstico ficam específicos por consumidor. Replay manual foi deliberadamente adiado e não faz parte da implementação atual.

## ADR-MSG-011 — ACK, publisher confirm e idempotência do consumer

**Contexto:** um consumer pode cair durante o processamento ou durante a transferência de uma mensagem para retry.

**Decisão:** consumers usarão entrega at-least-once e ACK manual. No sucesso, o ACK ocorrerá somente depois do commit dos efeitos locais. Cada consumer registrará uma Inbox com unicidade por `(event_id, consumer_group)` para tornar reentregas idempotentes.

Em falha transitória, a ordem segura será:

1. receber a mensagem original;
2. publicar a tentativa na exchange de retry;
3. aguardar o publisher confirm dessa nova publicação;
4. somente então dar ACK na mensagem original.

O consumer atua como publisher no passo de retry. Publisher confirm e consumer ACK são confirmações diferentes: a primeira vem do broker para quem publicou; a segunda vem do consumer para o broker.

**Consequências:** se o consumer cair antes do ACK, o RabbitMQ reentrega a original. Duplicatas continuam possíveis, portanto a Inbox é obrigatória quando o primeiro consumer for implementado.

## ADR-MSG-012 — Outbox transacional

**Contexto:** a publicação best-effort não elimina a janela de dual-write entre banco e broker.

**Decisão:** não implementar Outbox agora. Quando a entrega passar a ser requisito, criar `outbox_events` na mesma transação do estado de negócio e um worker que publique pelo mesmo `RabbitMqEventPublisher`, aguarde publisher confirm e marque o registro como publicado.

Uma falha entre o confirm e a marcação de `published_at` ainda pode duplicar a publicação. O mesmo `event_id` e consumers idempotentes tratarão esse caso.

**Consequências:** o desenho atual preserva o adaptador necessário para a evolução, mas não adiciona tabela, worker, feature flag ou mecanismo de replay prematuramente.

## ADR-MSG-013 — Disponibilidade do RabbitMQ

**Contexto:** desenvolvimento local e produção têm exigências diferentes de disponibilidade.

**Decisão:** usar um único nó RabbitMQ nesta etapa local, com volume persistente, healthcheck, porta AMQP `5672` e Management UI em `15672`. Esse ambiente não oferece alta disponibilidade.

Se alta disponibilidade for exigida futuramente, avaliar um cluster de pelo menos três nós em domínios de falha distintos e quorum queues para as filas que precisarem de replicação. Três nós toleram a falha de um membro; a regra `2f + 1` é específica de sistemas baseados em quórum e não é uma regra universal para toda redundância.

**Consequências:** a queda do único broker interrompe novas publicações e consumos até sua recuperação. A queda de um consumer não perde mensagens já enfileiradas e não confirmadas, que poderão ser reentregues quando houver consumer novamente.

## ADR-MSG-014 — Verificação da primeira implementação

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
| Fila/routing key de retorno `inventory.catalog.main` | Usar `inventory.catalog` |
| RabbitMQ como driver de Laravel Queue | Integração AMQP direta e isolada para eventos |
| Feature flag para ativar a publicação | Sem feature flag |
| Outbox na primeira implementação | Outbox adiada |
| Criar retry e DLQ antes de haver consumer | Criar junto com o primeiro consumer |
| Definir agora o comando de replay da DLQ | Decidir após existirem mensagens reais na DLQ |

## Escopo exato da primeira implementação

Incluído:

- broker RabbitMQ local em execução;
- vhost `ecommerce`;
- exchange topic durável `ecommerce.events`;
- identidade restrita do Catálogo;
- publicação de `catalog.product.created` após o commit;
- mensagem persistente, publisher confirm e log estruturado de falha.

Não incluído:

- filas e bindings;
- consumers;
- retry, DLQ e replay;
- Inbox e idempotência de consumer;
- Outbox;
- feature flag;
- cluster, quorum queues e alta disponibilidade.

## ADR-MSG-015 -- Provisionamento Shell com rabbitmqadmin

A imagem Management Alpine pressupoe o binario rabbitmqadmin v2 nas plataformas amd64 e arm64. Essas sao as plataformas suportadas para o setup local nesta etapa; outras arquiteturas exigirao uma imagem auxiliar com o binario nativo correspondente.

Adendo vigente: a topologia continua tendo definitions.json como fonte de verdade, mas a primeira implementacao importa esse arquivo por um servico one-shot do Compose depois que o broker cria a identidade administrativa padrao. O mesmo servico executa provision-identity.sh com rabbitmqadmin para importar a topologia, criar ou atualizar catalog e aplicar suas permissoes.

O projeto nao usara arquivo Python nem colocara senhas ou password_hash em definitions.json. A importacao nativa no boot foi descartada para este recorte porque um no vazio que importa definicoes no boot pode nao criar o usuario padrao necessario para autenticar o provisionador. Uma futura pipeline pode substituir o servico one-shot sem alterar o contrato do producer.
