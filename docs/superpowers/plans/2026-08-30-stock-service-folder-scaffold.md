# Stock Service Folder Scaffold Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Criar e versionar somente a estrutura inicial de diretórios do `stock-service` com placeholders `.gitkeep`.

**Status:** executado nos commits `ec4bb3a` e `02dd156`.

**Architecture:** O scaffold segue Feature by Slice puro: cada caso de uso do primeiro incremento fica diretamente em `src/features`, enquanto infraestrutura transversal fica em `src/shared`. Não há agrupamentos globais de commands/queries, pastas próprias de Ledger/projeções, código executável ou configuração.

**Tech Stack:** Git e estrutura de diretórios do monorepo; nenhum runtime, framework ou dependência será introduzido.

**Spec:** `docs/superpowers/specs/2026-08-30-stock-service-folder-scaffold-design.md`

## Global Constraints

- Criar somente diretórios versionáveis e arquivos `.gitkeep` dentro de `stock-service/` na raiz do repositório.
- Não criar TypeScript, `package.json`, lockfile, configuração NestJS, Prisma Schema, migrations, Dockerfile, testes executáveis, módulos, entrypoints ou contratos.
- Não alterar workspace, Compose, Laravel ou arquivos existentes fora deste scaffold.
- Preservar todas as mudanças preexistentes no worktree.
- Não criar `features/stock`, pastas globais `commands` ou `queries`, nem pastas próprias para Ledger ou projeções.

---

### Task 1: Criar e verificar a árvore versionável

**Files:**
- Create: `stock-service/prisma/migrations/.gitkeep`
- Create: `stock-service/src/shared/config/.gitkeep`
- Create: `stock-service/src/shared/database/.gitkeep`
- Create: `stock-service/src/shared/errors/.gitkeep`
- Create: `stock-service/src/shared/health/.gitkeep`
- Create: `stock-service/src/shared/logging/.gitkeep`
- Create: `stock-service/src/shared/messaging/.gitkeep`
- Create: `stock-service/src/features/initialize-stock/consumers/.gitkeep`
- Create: `stock-service/src/features/initialize-stock/schemas/.gitkeep`
- Create: `stock-service/src/features/initialize-stock/tests/.gitkeep`
- Create: `stock-service/src/features/receive-stock/consumers/.gitkeep`
- Create: `stock-service/src/features/receive-stock/schemas/.gitkeep`
- Create: `stock-service/src/features/receive-stock/tests/.gitkeep`
- Create: `stock-service/src/features/adjust-stock/consumers/.gitkeep`
- Create: `stock-service/src/features/adjust-stock/schemas/.gitkeep`
- Create: `stock-service/src/features/adjust-stock/tests/.gitkeep`

**Interfaces:**
- Consumes: a estrutura aprovada na especificação; nenhum artefato de runtime.
- Produces: 16 placeholders `.gitkeep` que materializam todos os diretórios-folha aprovados.

- [x] **Step 1: Confirmar que o destino ainda não existe**

Run:

```powershell
Test-Path -LiteralPath 'stock-service'
git status --short
```

Expected: `Test-Path` imprime `False`. O status pode conter mudanças preexistentes fora de `stock-service`; elas devem permanecer intocadas.

- [x] **Step 2: Criar os placeholders com um único patch**

Use `apply_patch` para adicionar exatamente os 16 arquivos vazios listados em **Files**. A criação dos arquivos materializa automaticamente todos os diretórios-pai. Não adicione conteúdo aos `.gitkeep`.

- [x] **Step 3: Verificar a lista exata de arquivos**

Run:

```powershell
$expectedStockScaffoldFiles = @(
  'stock-service\prisma\migrations\.gitkeep'
  'stock-service\src\features\adjust-stock\consumers\.gitkeep'
  'stock-service\src\features\adjust-stock\schemas\.gitkeep'
  'stock-service\src\features\adjust-stock\tests\.gitkeep'
  'stock-service\src\features\initialize-stock\consumers\.gitkeep'
  'stock-service\src\features\initialize-stock\schemas\.gitkeep'
  'stock-service\src\features\initialize-stock\tests\.gitkeep'
  'stock-service\src\features\receive-stock\consumers\.gitkeep'
  'stock-service\src\features\receive-stock\schemas\.gitkeep'
  'stock-service\src\features\receive-stock\tests\.gitkeep'
  'stock-service\src\shared\config\.gitkeep'
  'stock-service\src\shared\database\.gitkeep'
  'stock-service\src\shared\errors\.gitkeep'
  'stock-service\src\shared\health\.gitkeep'
  'stock-service\src\shared\logging\.gitkeep'
  'stock-service\src\shared\messaging\.gitkeep'
) | Sort-Object
$actualStockScaffoldFiles = @(rg --files --hidden 'stock-service') | Sort-Object
Compare-Object $expectedStockScaffoldFiles $actualStockScaffoldFiles
$actualStockScaffoldFiles.Count
```

Expected: `Compare-Object` não imprime diferenças e a contagem é `16`.

- [x] **Step 4: Verificar que o scaffold não contém outros artefatos**

Run:

```powershell
$unexpectedStockScaffoldFiles = @(rg --files --hidden 'stock-service') |
  Where-Object { [System.IO.Path]::GetFileName($_) -ne '.gitkeep' }
$unexpectedStockScaffoldFiles
git status --short --untracked-files=all -- 'stock-service'
```

Expected: `$unexpectedStockScaffoldFiles` não imprime nada. O status mostra exatamente 16 arquivos não rastreados, todos chamados `.gitkeep` nos caminhos listados.

- [x] **Step 5: Versionar somente o scaffold**

Run:

```powershell
git add -- 'stock-service'
git diff --cached --check
git diff --cached --name-only
git commit -m "chore: scaffold stock service directories"
```

Expected: `git diff --cached --check` não relata erros; `git diff --cached --name-only` lista somente os 16 `.gitkeep`; o commit é criado sem incluir mudanças preexistentes.
