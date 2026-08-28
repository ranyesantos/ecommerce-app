****# Decisões dos microsserviços Node

**Atualizado em:** 2026-08-28
**Status:** desenho tecnológico global aprovado; implementação ainda não iniciada
**Objetivo:** permitir a continuação em outra sessão sem depender do histórico da conversa.

## 1. Contexto

Este repositório é um monorepo de estudo de arquitetura distribuída e RabbitMQ aplicado a um e-commerce.

- `platform/` é a aplicação Laravel, dona do catálogo, responsável pelas páginas web e pelo papel de BFF.
- `orders-service` será dono de pedidos.
- `stock-service` será dono de estoque.
- `payments-service` será dono de pagamentos simulados.
- O navegador acessa somente o Laravel.
- Cada serviço possui seu próprio PostgreSQL e não acessa tabelas de outro domínio.
- RabbitMQ é o meio preferencial para comandos assíncronos e eventos.
- HTTP interno é usado quando uma consulta exige resposta imediata.

As decisões de negócio e mensageria dos ADRs existentes continuam válidas. A especificação tecnológica detalhada está em [`docs/superpowers/specs/2026-08-28-nestjs-microservices-design.md`](docs/superpowers/specs/2026-08-28-nestjs-microservices-design.md).

## 2. Stack aprovada

| Tema | Decisão | Para que serve |
|---|---|---|
| Runtime | Node.js 24 LTS | Uniformizar uma versão suportada nos serviços. |
| Linguagem | TypeScript `strict` com `NodeNext` | Manter tipos e resolução de módulos explícitos. |
| Framework | NestJS 12 | Fornecer módulos, injeção de dependência e lifecycle padronizados. |
| HTTP | Express padrão do NestJS | Maximizar compatibilidade e previsibilidade. |
| Banco | PostgreSQL por serviço | Preservar propriedade e isolamento dos dados. |
| ORM | Prisma | Fornecer client tipado e migrations. |
| Validação | Zod | Ser a fonte dos contratos HTTP, de configuração e mensageria. |
| RabbitMQ | `amqplib` diretamente | Controlar ACK, confirms, retry, DLQ, canais e topologia. |
| Testes | Jest + Supertest | Executar testes de feature e exercitar HTTP. |
| Workspace | pnpm workspaces | Centralizar instalação e manter um lockfile. |
| Logs | Pino em JSON | Produzir logs estruturados em stdout. |
| Health checks | `@nestjs/terminus` | Expor liveness e readiness uniformes. |
| Estrutura | Slice by Feature pragmático | Agrupar código pela funcionalidade sem DDD formal. |

## 3. Repositório e gerador

Os serviços Node ficam neste monorepo:

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

Regras do workspace:

- Cada serviço possui `package.json`, Prisma, build, banco, imagem e deploy próprios.
- Existe somente um `pnpm-lock.yaml`, na raiz.
- Não executar `npm install` ou `bun install` dentro dos serviços.
- Não usar Nx ou Turborepo inicialmente.

O gerador não ficará neste repositório. Ele terá um repositório Git separado e conterá seu código e os arquivos de template:

```text
nestjs-microservice-generator/
├── src/
├── templates/service/
├── package.json
└── README.md
```

Regras do gerador:

- versões estáveis recebem tags como `v1.0.0`;
- o serviço gerado registra a origem em `.template-version`;
- o resultado não contém `.git` nem lockfile próprio;
- uma feature demonstrativa removível documenta HTTP, Prisma, RabbitMQ, erros e testes;
- a suíte do gerador comprova que o resultado instala, compila e passa nos testes.

## 4. Estrutura de cada serviço

API e worker usam a mesma base de código e imagem, mas executam como processos separados desde o início.

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

Convenções:

- Controllers e consumers são adaptadores finos.
- O service da feature contém o caso de uso e pode ser chamado pelas duas entradas.
- Uma feature pode usar `PrismaService` diretamente.
- Repository só é criado quando consultas ou testes justificarem a abstração.
- `shared/` contém apenas infraestrutura transversal, nunca regras de negócio.
- Não criar camadas de DDD, Clean Architecture, CQRS ou interfaces sem necessidade concreta.
- Não haverá `CombinedAppModule`.
- `pnpm run dev` pode iniciar API e worker juntos para conveniência local, ainda como processos diferentes.
- Migrations rodam uma vez como etapa do deploy, nunca em cada réplica.

## 5. Comunicação e contratos

### HTTP interno

- Prefixo e versão na URI: `/internal/v1`.
- Recursos no plural, como `/internal/v1/orders`.
- JSON externo em `snake_case`; TypeScript interno em `camelCase`, com mapeamento explícito.
- Paginação inicial com `page` e `per_page`, impondo limite máximo.
- Erros em `application/problem+json`, conforme RFC 9457.
- Erros possuem `type`, `title`, `status`, `detail`, `instance` e extensão `code`.
- Timeouts são curtos e configuráveis.
- Retry automático é limitado a operações idempotentes, como `GET`.
- Escritas HTTP são exceção; comandos usam preferencialmente RabbitMQ.
- Se uma escrita HTTP futura for necessária, ela deverá adotar chave de idempotência.

### Zod

- NestJS 12 usa `StandardSchemaValidationPipe` para requisições.
- NestJS 12 usa `StandardSchemaSerializerInterceptor` para respostas.
- OpenAPI usa `@nestjs/swagger` com conversão por `zod-openapi`.
- Mensagens publicam JSON Schema gerado por `z.toJSONSchema()`.
- Configuração usa `@nestjs/config` e schema Zod; configuração inválida impede o startup.

### RabbitMQ

- `amqplib` fica encapsulado em providers de infraestrutura.
- Entrega é at-least-once.
- ACK manual acontece somente após persistir os efeitos locais necessários.
- Publicação usa publisher confirms.
- Mensagens possuem envelope e payload versionados.
- A identidade estável da mensagem — `event_id` nos eventos atuais — e uma Inbox por consumer group garantem idempotência quando o primeiro consumer real nascer.
- Falhas transitórias seguem retry e depois DLQ.
- Mensagens inválidas ou falhas permanentes não entram em retry infinito.
- A topologia continua centralizada nos artefatos RabbitMQ existentes.
- Outbox transacional permanece adiada até existir requisito de garantia de publicação.

## 6. Tratamento centralizado de erros

O template inclui tratamento centralizado como regra obrigatória.

- Features lançam erros tipados com `code`, `kind`, mensagem segura e detalhes seguros opcionais.
- Categorias iniciais: `validation`, `not_found`, `conflict`, `business` e `transient`.
- Um exception filter HTTP global converte erros conhecidos para RFC 9457.
- O mapeamento HTTP inicial é: `validation` → 400, `not_found` → 404, `conflict` → 409, `business` → 422 e `transient` → 503.
- Um wrapper global de consumers decide entre ACK, retry e DLQ.
- No worker, `transient` segue retry; erros inválidos, de negócio ou permanentes seguem para DLQ. Uma recusa de negócio esperada deve ser modelada como resultado/evento, não como exceção.
- Erros desconhecidos são registrados com stack internamente e expostos como `500` genérico.
- Controllers e consumers não repetem `try/catch`, salvo quando precisam acrescentar contexto ou compensar uma operação.
- O erro é registrado uma vez na fronteira responsável.
- Stack traces, segredos e detalhes internos nunca aparecem para clientes.

## 7. Health, logs e lifecycle

- Pino envia logs JSON para stdout.
- IDs de correlação são propagados entre Laravel, HTTP e mensagens.
- API e worker expõem `/health/live` e `/health/ready`.
- O worker usa uma porta administrativa interna e não expõe endpoints de negócio.
- Liveness verifica somente se o processo está vivo.
- Readiness da API verifica apenas dependências essenciais ao tráfego HTTP.
- Readiness do worker verifica PostgreSQL, conexão/canal RabbitMQ e registro dos consumers.
- No graceful shutdown, a API para de aceitar tráfego e o worker para de consumir antes de fechar RabbitMQ e Prisma.

Prometheus, OpenTelemetry, Loki, Grafana e Jaeger/Tempo estão explicitamente adiados.

## 8. Estratégia inicial de testes

A suíte inicial usa testes de feature, sem Docker:

- instancia o módulo NestJS real da feature;
- substitui Prisma por fake em memória;
- substitui RabbitMQ e APIs externas por fakes/mocks;
- usa Supertest para endpoints HTTP;
- cobre sucesso, validação, não encontrado, conflito, duplicidade/idempotência, ACK, retry e tratamento centralizado de erros.

Testes de integração com PostgreSQL/RabbitMQ reais e testes E2E estão adiados. Testcontainers e um Compose de testes não entram no scaffold inicial.

## 9. Decisões que não bloqueiam o scaffold

As decisões tecnológicas globais estão fechadas. Estes itens serão decididos quando houver requisito concreto:

- autenticação e autorização entre aplicações;
- ambiente e plataforma de deploy;
- gestão de segredos;
- política detalhada de CI/CD;
- SLOs, métricas, traces, dashboards e alertas;
- geração automática de clients Laravel;
- Testcontainers ou Compose para uma futura suíte de integração;
- Outbox transacional;
- cache e projeções adicionais;
- contratos, banco, endpoints e filas específicos de cada domínio.

## 10. Documentos históricos que precisam de alinhamento

`docs/superpowers/specs/2026-08-20-platform-design.md` ainda menciona Node 20, Fastify, `pg`, Vitest e `packages/core-node`. Essa stack foi substituída por esta decisão. O documento histórico não deve ser usado como fonte tecnológica para novos serviços até receber um adendo.

Os ADRs continuam divididos em:

- `docs/ADRs/architecture/ADRs.md`;
- `docs/ADRs/platform/ADRs.md`;
- `docs/ADRs/catalog/ADRs.md`;
- `docs/ADRs/ecommerce/ADRs.md`;
- `docs/ADRs/messaging/ADRs.md`.

## 11. Como continuar em outra sessão

1. Ler este arquivo e a especificação detalhada vinculada na seção 1.
2. Não reabrir decisões aprovadas sem um requisito novo.
3. Revisar e aprovar a especificação escrita.
4. Criar o plano de implementação do repositório externo do gerador.
5. Implementar e validar o gerador e seu template.
6. Gerar o primeiro serviço Node e integrá-lo ao pnpm workspace.
7. Criar especificações separadas para o comportamento de cada domínio.

Prompt sugerido:

> Leia `ARCHITECTURE-DECISIONS.md` e `docs/superpowers/specs/2026-08-28-nestjs-microservices-design.md`. Continue sem reabrir decisões aprovadas. Se a especificação estiver aprovada, crie um plano para implementar primeiro o repositório externo do gerador NestJS e seu template.
