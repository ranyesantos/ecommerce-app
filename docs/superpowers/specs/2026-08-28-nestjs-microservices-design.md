# Design tecnológico dos microsserviços NestJS

**Data:** 2026-08-28
**Status:** desenho aprovado
**Escopo:** padrão tecnológico, estrutural e operacional dos futuros serviços Node

## 1. Objetivo

Definir uma base reproduzível para criar `orders-service`, `stock-service` e `payments-service` sem repetir decisões de infraestrutura em cada implementação. O padrão deve ser simples para estudo, consistente entre serviços e capaz de evoluir sem exigir DDD ou uma plataforma interna prematura.

Os serviços serão criados manualmente dentro do pnpm workspace. O primeiro serviço validado será a referência prática para repetir a estrutura técnica nos demais.

## 2. Limites do sistema

- O Laravel em `platform/` continua sendo web app, dono do catálogo e BFF.
- O navegador não acessa os serviços Node diretamente.
- Pedidos, estoque e pagamentos pertencem a serviços independentes.
- Cada serviço é dono do próprio PostgreSQL.
- RabbitMQ transporta comandos assíncronos e eventos.
- HTTP interno atende consultas que exigem resposta imediata.
- Regras de negócio específicas de cada domínio serão definidas em especificações próprias.

## 3. Stack

- Node.js 24 LTS, no mínimo 24.15 para executar o tooling do NestJS 12.
- TypeScript em modo `strict`, com `module` e `moduleResolution` em `NodeNext`.
- NestJS 12 com adapter Express padrão.
- PostgreSQL e Prisma.
- Zod para contratos e configuração.
- `amqplib` diretamente, sem transporter RabbitMQ do NestJS.
- Jest e Supertest.
- pnpm workspaces.
- Pino para logs JSON.
- Health checks próprios e pequenos; `@nestjs/terminus` não será instalado enquanto não declarar suporte ao NestJS 12.

## 4. Organização do monorepo

```text
inventory-lab/
├── package.json
├── pnpm-workspace.yaml
├── pnpm-lock.yaml
├── platform/
├── services/
│   ├── orders-service/
│   ├── stock-service/
│   └── payments-service/
└── packages/
    └── contracts/
```

O workspace possui um único lockfile. Cada serviço mantém `package.json`, Prisma schema, migrations, build, Dockerfile e deploy independentes. Nx e Turborepo não serão introduzidos até existir um problema concreto de coordenação ou desempenho.

`packages/contracts` armazena envelopes e contratos compartilhados pelos serviços Node. Contratos usados pelo Laravel também são publicados em formatos independentes de linguagem, como OpenAPI e JSON Schema; Laravel não importa tipos TypeScript.

## 5. Arquitetura de um serviço

### 5.1 Dois processos

Cada serviço produz dois entrypoints:

```text
node dist/main-api.js
node dist/main-worker.js
```

API e worker compartilham regras, código compilado e imagem Docker, mas são processos independentes. Eles podem escalar, reiniciar e receber recursos separadamente. Um comando de desenvolvimento pode iniciar ambos, sem criar um processo combinado em produção.

Não haverá `CombinedAppModule`:

- `HttpAppModule` registra controllers, configuração HTTP, OpenAPI, filter global e health da API;
- `WorkerAppModule` registra consumers, conexão RabbitMQ, tratamento centralizado de consumo e health administrativo.

Migrations usam `prisma migrate deploy` em uma etapa única do deploy antes das réplicas. API e worker nunca disputam a execução automática das migrations.

### 5.2 Slice by Feature pragmático

```text
src/
├── main-api.ts
├── main-worker.ts
├── http-app.module.ts
├── worker-app.module.ts
├── shared/
│   ├── config/
│   ├── database/
│   ├── errors/
│   ├── health/
│   ├── logging/
│   └── messaging/
└── features/
    └── example/
        ├── example.module.ts
        ├── example.service.ts
        ├── http/
        ├── consumers/
        ├── schemas/
        └── tests/
```

Uma feature reúne entradas, schemas, caso de uso e testes. Controller e consumer validam a entrada e delegam ao service. O service pode usar `PrismaService` diretamente. Repository, entidades ricas, value objects e novas camadas só surgem quando uma regra concreta justificar o custo.

`shared/` contém infraestrutura transversal e não pode virar um depósito de regras de negócio.

## 6. Fluxos de dados

### 6.1 Consulta HTTP

```text
Navegador → Laravel → API interna NestJS
→ Zod → feature service → Prisma → PostgreSQL
```

Laravel usa clients da própria feature, e não chamadas HTTP espalhadas em controllers. Um serviço não acessa o banco de outro serviço.

### 6.2 Mensagem assíncrona

```text
Laravel ou serviço → RabbitMQ → worker NestJS
→ Zod → feature service → Prisma
→ eventual publicação confirmada → ACK
```

O consumidor pressupõe entrega at-least-once. Efeitos locais precisam ser idempotentes. Quando o primeiro consumer real for implementado, a Inbox terá unicidade por identidade da mensagem e consumer group. O ACK ocorre depois que os efeitos locais necessários forem persistidos. Publicações usam publisher confirms.

Retry com TTL/dead-letter e DLQ continuam seguindo a topologia RabbitMQ central já adotada. Outbox permanece adiada; enquanto não existir, a janela entre commit e publicação deve ser reconhecida nos fluxos que fazem ambos.

## 7. Contratos HTTP

- Base path: `/internal/v1`.
- Recursos usam substantivos plurais.
- JSON no wire usa `snake_case`; o código TypeScript usa `camelCase` e mapeia as fronteiras explicitamente.
- Paginação inicial usa `page` e `per_page`, com limite máximo configurado pelo serviço.
- Filtros são definidos por endpoint; não será criada uma DSL genérica.
- Erros usam RFC 9457 e content type `application/problem+json`.
- Além de `type`, `title`, `status`, `detail` e `instance`, a resposta inclui `code` estável e pode incluir `errors` para validação.
- Respostas nunca expõem stack, segredos ou detalhes de infraestrutura.
- Timeouts do client Laravel são curtos e configuráveis.
- Retry automático é limitado e permitido apenas para operações idempotentes, como `GET`.
- Comandos usam RabbitMQ por padrão. Uma escrita HTTP futura exige análise própria e chave de idempotência.

## 8. Zod e documentação de contratos

Zod é a fonte dos schemas de request, response, configuração, envelope e payload de mensagem.

- `StandardSchemaValidationPipe` do NestJS 12 valida requests.
- `StandardSchemaSerializerInterceptor` valida e serializa responses.
- `@nestjs/swagger` com `zod-openapi` produz OpenAPI.
- `z.toJSONSchema()` produz os artefatos JSON Schema de RabbitMQ.
- `@nestjs/config` carrega configuração, e um schema Zod por processo valida as variáveis no startup.

O serviço falha cedo se sua configuração obrigatória for inválida. Features recebem configuração tipada e não acessam `process.env` diretamente.

## 9. Tratamento centralizado de erros

Cada serviço define um contrato simples de erro de aplicação com:

- `code` estável para consumidores e testes;
- `kind`: `validation`, `not_found`, `conflict`, `business` ou `transient`;
- mensagem segura;
- detalhes seguros opcionais;
- causa interna opcional para logging.

Na API, um exception filter global converte erros conhecidos para RFC 9457. O mapeamento inicial é `validation` para 400, `not_found` para 404, `conflict` para 409, `business` para 422 e `transient` para 503. Um erro desconhecido é registrado com stack e retorna `500` genérico.

No worker, todo consumer é executado por um wrapper central. Ele valida e classifica a falha, registra o erro uma vez e decide ACK, retry ou DLQ segundo as regras de mensageria. Mensagens estruturalmente inválidas e erros permanentes ou de negócio seguem para DLQ sem loop; erros transitórios percorrem a política de retry. Uma recusa de negócio esperada deve ser modelada como resultado e, quando aplicável, produzir seu evento correspondente em vez de lançar exceção.

Controllers e consumers não repetem `try/catch`. Captura local é permitida somente para acrescentar contexto relevante, traduzir uma falha conhecida ou realizar compensação.

## 10. Health, logging e lifecycle

API e worker expõem:

- `/health/live`: estado do processo, sem depender de serviços externos;
- `/health/ready`: capacidade de assumir trabalho.

Os endpoints usam controllers e indicadores próprios. Essa substituição evita forçar `@nestjs/terminus@11.1.1`, cujo peer dependency aceita apenas NestJS 10 e 11. A implementação deve preservar interfaces pequenas para permitir migração futura sem alterar os endpoints.

O worker abre uma porta HTTP administrativa interna sem endpoints de negócio. Sua readiness verifica PostgreSQL, conexão/canal RabbitMQ e registro dos consumers. A API verifica somente dependências indispensáveis às rotas servidas.

Pino escreve JSON em stdout. Os campos mínimos são serviço, papel (`api` ou `worker`), nível, mensagem e timestamp. IDs de correlação são propagados entre Laravel, requests HTTP e mensagens. Payloads sensíveis e segredos não são registrados.

No encerramento, a API deixa de aceitar tráfego e o worker cancela novos consumos, aguarda o trabalho em andamento dentro do limite configurado e então fecha canais RabbitMQ e Prisma. Falhas fatais encerram o processo para que o supervisor o reinicie.

Prometheus, OpenTelemetry, Loki, Grafana e Jaeger/Tempo não fazem parte do primeiro serviço Node.

## 11. Estratégia de testes

O scaffold inicial padroniza testes de feature com Jest. Esses testes exercitam uma slice completa, mas substituem dependências externas:

- módulo NestJS real da feature;
- Prisma fake em memória;
- publisher, consumer e canal RabbitMQ fake;
- APIs externas mockadas;
- Supertest para o endpoint HTTP.

A feature demonstrativa deve cobrir:

1. request válido persiste e responde no contrato esperado;
2. request inválido retorna Problem Details;
3. recurso ausente retorna erro centralizado;
4. mensagem válida persiste e publica o evento esperado;
5. mensagem duplicada não duplica efeitos;
6. sucesso permite ACK;
7. falha transitória solicita retry;
8. erro permanente ou payload inválido não entra em retry infinito.

PostgreSQL e RabbitMQ reais não são iniciados pela suíte inicial. Testes de integração, Testcontainers, Compose de testes e E2E serão introduzidos somente quando um requisito justificar seu custo.

## 12. Conteúdo obrigatório do scaffold manual

Cada serviço deve ser criado com:

- entrypoints e módulos separados para API e worker;
- Slice by Feature e feature demonstrativa removível;
- Prisma e estrutura de migrations;
- conexão e abstrações `amqplib`;
- Zod, OpenAPI e JSON Schema;
- configuração tipada;
- Pino;
- health checks;
- tratamento centralizado de erros HTTP e RabbitMQ;
- idempotência demonstrada com fakes e ponto de extensão para Inbox real;
- graceful shutdown;
- Jest, Supertest, fakes e testes de feature;
- Dockerfile e `.env.example`;
- scripts de desenvolvimento, build, execução, teste e migration;
- README para configurar, executar e remover/adaptar a feature demonstrativa.

O resultado não inclui observabilidade avançada, autenticação interna, Outbox, Nx, Turborepo, Kubernetes ou abstrações de domínio genéricas.

## 13. Critérios de aceitação do scaffold

1. O serviço está em `services/<nome>-service` e possui seu próprio `package.json`.
2. O serviço não contém Git ou lockfile aninhado.
3. O serviço é reconhecido pelo pnpm workspace do monorepo.
4. TypeScript compila em modo strict.
5. API e worker iniciam separadamente.
6. Ambos respondem aos health checks correspondentes.
7. A feature de exemplo demonstra HTTP, Prisma, RabbitMQ e erros centralizados.
8. Todos os testes de feature passam sem Docker.
9. OpenAPI e JSON Schema podem ser produzidos de forma reproduzível.

## 14. Decisões futuras

Estas escolhas não bloqueiam a implementação do primeiro serviço Node:

- autenticação e autorização entre aplicações;
- ambiente de deploy, secrets e política detalhada de CI/CD;
- observabilidade avançada e SLOs;
- client Laravel gerado;
- ambiente de testes de integração;
- Outbox;
- cache e projeções;
- modelos, endpoints, eventos, filas e regras de cada domínio.

## 15. Compatibilidade documental

Esta especificação substitui, para a stack dos serviços Node, as referências a Node 20, Fastify, `pg`, Vitest e `packages/core-node` em `2026-08-20-platform-design.md`. Os ADRs de mensageria continuam válidos. Um adendo de ADR deverá registrar formalmente a substituição tecnológica antes de implementar o primeiro serviço real.
