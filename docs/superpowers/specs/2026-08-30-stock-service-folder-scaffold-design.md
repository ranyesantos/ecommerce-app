# Estrutura inicial de pastas do stock-service

**Data:** 2026-08-30
**Status:** desenho aprovado
**Escopo:** somente diretórios versionáveis; sem código, configuração ou dependências

## Objetivo

Criar o esqueleto físico mínimo do `stock-service` em `stock-service/` na raiz do repositório, alinhado às convenções dos serviços NestJS e ao CQRS pragmático já decidido. Como o Git não versiona diretórios vazios, cada diretório-folha conterá apenas um arquivo `.gitkeep`.

## Estrutura

```text
stock-service/
├── prisma/
│   └── migrations/
└── src/
    ├── shared/
    │   ├── config/
    │   ├── database/
    │   ├── errors/
    │   ├── health/
    │   ├── logging/
    │   └── messaging/
    └── features/
        ├── initialize-stock/
        │   ├── consumers/
        │   ├── schemas/
        │   └── tests/
        ├── receive-stock/
        │   ├── consumers/
        │   ├── schemas/
        │   └── tests/
        └── adjust-stock/
            ├── consumers/
            ├── schemas/
            └── tests/
```

`shared` reserva os pontos de extensão para infraestrutura transversal. Cada diretório diretamente abaixo de `features` é uma slice vertical que reúne a entrada, os schemas e os testes de um único caso de uso. O serviço inteiro já pertence ao domínio de Estoque, portanto não existe um agrupamento redundante `features/stock`.

O CQRS permanece pragmático e conceitual: `initialize-stock`, `receive-stock` e `adjust-stock` são commands por seu comportamento. Uma futura query nascerá como sua própria slice, por exemplo `features/get-stock-item`, sem pastas globais `commands` e `queries`.

Não serão criadas pastas próprias para Ledger ou projeções. Essas responsabilidades ainda não possuem componentes concretos; uma abstração só será extraída quando a implementação revelar lógica compartilhada real.

## Fora do escopo

Este scaffold não cria arquivos TypeScript, `package.json`, lockfile, configuração NestJS, Prisma Schema, migrations, Dockerfile, testes executáveis, módulos, entrypoints ou contratos. Também não altera o workspace, o Compose nem a aplicação Laravel.

## Verificação

A entrega estará correta quando todos os diretórios-folha existirem, cada um contiver somente `.gitkeep`, `git status` mostrar exclusivamente os placeholders esperados dentro de `stock-service/`, e nenhum arquivo executável ou de configuração tiver sido introduzido pelo scaffold.
