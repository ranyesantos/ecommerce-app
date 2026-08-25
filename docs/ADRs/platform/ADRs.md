# ADRs — platform

Este arquivo consolida as decisões arquiteturais tomadas para o projeto. Cada seção registra uma decisão pequena e independente; alterações futuras devem atualizar o status e documentar a substituição.

## Índice

| ID | Decisão | Status |
|---|---|---|
| ADR-001 | Laravel como web app, BFF e ponto inicial | Aceita |
| ADR-003 | Sem API pública no primeiro slice | Aceita |
| ADR-004 | Feature by slice no Laravel | Aceita |
| ADR-PLT-001 | Infraestrutura compartilhada na raiz do monorepo | Aceita |


## ADR-001 — Laravel como web app, BFF e ponto inicial

**Contexto:** o Laravel servirá o frontend e será a entrada usada pelo navegador. Os domínios distribuídos continuarão em serviços Node.

**Decisão:** `catalog-app` será uma aplicação web tradicional Laravel, dona do catálogo e BFF/ponto inicial para as interações do usuário.

**Consequências:** o Laravel valida formulários, renderiza páginas e adapta integrações; não duplica as regras de pedidos, estoque ou pagamentos; o sistema completo não é um monólito, embora o Laravel seja uma aplicação modular.

## ADR-003 — Sem API pública no primeiro slice

**Contexto:** o primeiro objetivo é entregar um CRUD de catálogo para um frontend servido pela própria aplicação.

**Decisão:** usar rotas web, formulários, controllers, Form Requests e respostas server-side. Não criar endpoints REST públicos para o catálogo nesta etapa.

**Consequências:** o contrato inicial é a interface web; uma futura API poderá reutilizar Actions e regras da slice sem tornar a camada HTTP inicial mais complexa.

## ADR-004 — Feature by slice no Laravel

**Contexto:** manter controllers, requests, modelos, Actions e testes de uma feature próximos facilita a evolução e uma eventual extração.

**Decisão:** colocar o catálogo em `app/Features/Catalog`, com views em `resources/views/catalog` e testes em `tests/Feature/Catalog` e `tests/Unit/Features/Catalog`.

**Consequências:** o namespace `App\\` continua funcionando sem pacote de módulos; alguns `make:*` do Artisan usam destinos convencionais diferentes e podem exigir namespace explícito, movimentação ou generators locais simples.

As decisões específicas do domínio de Catálogo estão no catálogo de [catalog/ADRs.md](../catalog/ADRs.md).

## ADR-PLT-001 — Infraestrutura compartilhada na raiz do monorepo

**Contexto:** o repositório abrigará a aplicação Laravel, serviços futuros, broker e observabilidade. A infraestrutura compartilhada não pertence exclusivamente ao diretório `platform/`.

**Decisão:** o `compose.yaml` principal e o diretório `infra/` ficam na raiz do repositório. A estrutura aprovada é:

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
├── platform/
├── <serviços futuros>/
└── <observabilidade futura>/
```

O Compose da raiz orquestra PostgreSQL, RabbitMQ e, futuramente, os demais serviços e a observabilidade. O conteúdo do `platform/compose.yaml` atual será incorporado ao arquivo da raiz. O volume PostgreSQL existente será preservado por nome explícito, reutilizando `platform_catalog_db_data` quando ele já existir.

Segredos de orquestração local ficam no `.env` da raiz, ignorado pelo Git; `.env.example` contém apenas placeholders. Configurações específicas de uma aplicação continuam em seu próprio diretório quando ela roda diretamente no host.

**Consequências:** todos os componentes compartilhados podem ser iniciados de um único ponto, sem tratar `platform/` como dono do broker ou da observabilidade. Serviços continuam em diretórios de primeiro nível e podem ter builds e ciclos de execução independentes.

## Decisões ainda abertas

- escolha final entre Blade puro e Inertia; ambas preservam a decisão de não ter API pública.
