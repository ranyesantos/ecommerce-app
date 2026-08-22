# Catalog Product CRUD Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. A marca `[x]` indica implementação evidenciada no código ou no histórico; `[ ]` identifica uma verificação que não foi executada nesta revisão documental.

**Goal:** Implementar o CRUD web inicial de produtos no Laravel, com UUIDv7, SKU normalizado e único, preço inteiro em centavos, ativação explícita e lixeira com soft delete.

**Architecture:** Manter o catálogo em uma feature slice sob `App\Features\Catalog`, com Form Requests na borda HTTP, Actions para escritas e transições, Eloquent como persistência e Blade para a interface server-side. No fluxo HTTP suportado, os Form Requests normalizam e validam antes de os controllers chamarem as Actions; as Actions assumem atributos vindos de `validated()` e não repetem toda a validação; os mutators do modelo normalizam os campos textuais. As constraints existentes no PostgreSQL permanecem como salvaguardas adicionais para estados inválidos ou conflitos que escapem desse fluxo. Os testes Pest usam PostgreSQL como infraestrutura do app, sem testar o mecanismo interno do banco.

**Tech Stack:** PHP 8.5, Laravel 13.17, PostgreSQL, Blade, Tailwind CSS 4, Pest 5, Pint 1.30, Larastan 3.10 e Rector 2.6.

**Spec:** [`docs/superpowers/specs/2026-08-21-catalog-product-model-design.md`](../specs/2026-08-21-catalog-product-model-design.md)

**Status:** Implementado no estado atual do repositório. As tarefas e os commits abaixo preservam a sequência histórica; smoke tests e quality gates só ficam marcados quando há evidência registrada nesta revisão ou no histórico.

## Global Constraints

- O Laravel atual está na raiz de `platform/`; não criar outro projeto Laravel nem mover o scaffold neste trabalho.
- Usar Pest, conforme a spec e a configuração já versionada, mesmo que a regra gerada do Boost ainda mencione PHPUnit.
- Gerar IDs com `Illuminate\Database\Eloquent\Concerns\HasUuids`; no Laravel 13 instalado, esse trait usa `Str::uuid7()`.
- Não adicionar quantidade, estoque, variantes, moeda configurável, custo, histórico de preço, autenticação, API pública ou exclusão física.
- Não aceitar `is_active` nos formulários de criação/edição. Ativação, desativação, exclusão e restauração são comandos separados.
- Manter `price_cents` como inteiro recebido pelo formulário e armazenado em BRL; não introduzir parser decimal nesta etapa.
- No fluxo HTTP suportado, a regra de unicidade de SKU inclui registros com soft delete. Não usar `withoutTrashed()` na regra `unique`.
- Usar PostgreSQL nos testes Pest como infraestrutura real do app (`RefreshDatabase`), sem testes diretos de `CHECK`, `UNIQUE` ou de qualquer outro mecanismo interno do banco.
- Manter responsabilidades explícitas: Form Requests validam o fluxo HTTP, Actions operam sobre dados já validados, mutators normalizam os campos textuais e Actions de ciclo de vida aplicam as transições suportadas. As constraints existentes são salvaguardas adicionais, não prova de que todo caminho de escrita passe pelas mesmas validações.
- Preservar `application.md`, atualmente não rastreado e fora do escopo.
- Usar `php artisan make:* --no-interaction` quando houver generator adequado e `apply_patch` para completar/mover o conteúdo.
- Antes de cada commit PHP, executar o teste focal e `vendor/bin/pint --dirty --format agent`.

## Histórico de implementação preservado

- `e0d6036` — configurou PostgreSQL para desenvolvimento e testes.
- `780ebaa` — adicionou persistência do produto, factory e migration.
- `cd88412` — adicionou a validação de entrada na aplicação; os testes específicos dessa camada foram removidos posteriormente por serem obsoletos.
- `e81a967` — adicionou as Actions de escrita e a tradução de conflitos de SKU.
- `1dd2d39` — adicionou as transições de ciclo de vida.
- `fa42e7a` e `9e8ff84` — adicionaram fluxos HTTP, rotas e telas Blade.
- `ee2924b` — documentou a operação do projeto.
- `3f0e55f`, `1d7320f`, `688be1c`, `b0c2838` e `a08160c` — endureceram restauração/validação, factory, tipos, Rector e dependências.
- `d3cbe40` — substituiu as chamadas invocáveis por métodos `handle()` nas Actions e nos consumidores.
- `6261a5f` — removeu os testes obsoletos de validação e de constraints; não recriar esses arquivos.

## File Structure

```text
platform/
├── app/Features/Catalog/
│   ├── Actions/
│   │   ├── ActivateProduct.php
│   │   ├── CreateProduct.php
│   │   ├── DeactivateProduct.php
│   │   ├── RestoreProduct.php
│   │   ├── TrashProduct.php
│   │   └── UpdateProduct.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── ProductController.php
│   │   │   ├── ProductStatusController.php
│   │   │   └── ProductTrashController.php
│   │   └── Requests/
│   │       ├── StoreProductRequest.php
│   │       └── UpdateProductRequest.php
│   ├── Models/Product.php
│   ├── Rules/PlainText.php
│   └── Support/ProductSkuConflict.php
├── database/
│   ├── factories/ProductFactory.php
│   └── migrations/*_create_products_table.php
├── infra/docker/postgres/init-test-database.sql
├── resources/views/
│   ├── catalog/products/_form.blade.php
│   ├── catalog/products/create.blade.php
│   ├── catalog/products/edit.blade.php
│   ├── catalog/products/index.blade.php
│   ├── catalog/products/show.blade.php
│   ├── catalog/products/trash.blade.php
│   └── layouts/app.blade.php
├── tests/Feature/Catalog/
│   ├── ProductCrudTest.php
│   ├── ProductLifecycleTest.php
│   └── ProductPersistenceTest.php
├── compose.yaml
├── phpunit.xml
├── .env.example
├── README.md
└── routes/web.php
```

---

### Task 1: Provide a real PostgreSQL test environment

**Files:**
- Create: `platform/compose.yaml`
- Create: `platform/infra/docker/postgres/init-test-database.sql`
- Modify: `platform/.env.example`
- Modify: `platform/phpunit.xml`

**Interfaces:**
- Consumes: Docker Compose; environment variables `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
- Produces: PostgreSQL databases `catalog_db` and `catalog_db_testing`; Pest connection `pgsql` against `catalog_db_testing`.

- [x] **Step 1: Define the two PostgreSQL databases**

Create `infra/docker/postgres/init-test-database.sql`:

```sql
CREATE DATABASE catalog_db_testing;
```

Create `compose.yaml` with one database service and a named volume:

```yaml
services:
  catalog_db:
    image: postgres:18-alpine
    environment:
      POSTGRES_DB: catalog_db
      POSTGRES_USER: catalog
      POSTGRES_PASSWORD: catalog
    ports:
      - '5432:5432'
    healthcheck:
      test: ['CMD-SHELL', 'pg_isready -U catalog -d catalog_db']
      interval: 5s
      timeout: 5s
      retries: 10
    volumes:
      - catalog_db_data:/var/lib/postgresql/data
      - ./infra/docker/postgres/init-test-database.sql:/docker-entrypoint-initdb.d/init-test-database.sql:ro

volumes:
  catalog_db_data:
```

- [x] **Step 2: Make PostgreSQL the documented local default**

Replace the SQLite block in `.env.example` with:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=catalog_db
DB_USERNAME=catalog
DB_PASSWORD=catalog
```

In `phpunit.xml`, replace the SQLite variables with:

```xml
<env name="DB_CONNECTION" value="pgsql"/>
<env name="DB_HOST" value="127.0.0.1"/>
<env name="DB_PORT" value="5432"/>
<env name="DB_DATABASE" value="catalog_db_testing"/>
<env name="DB_USERNAME" value="catalog"/>
<env name="DB_PASSWORD" value="catalog"/>
<env name="DB_URL" value=""/>
```

- [ ] **Step 3: Smoke-test Docker — não executado nesta máquina**

Run:

```powershell
docker compose up -d catalog_db
docker compose exec catalog_db pg_isready -U catalog -d catalog_db_testing
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'pgsql'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '5432'
$env:DB_DATABASE = 'catalog_db_testing'
$env:DB_USERNAME = 'catalog'
$env:DB_PASSWORD = 'catalog'
php artisan migrate:fresh --force
```

Success criteria when executed: PostgreSQL reports `accepting connections`; the scaffold migrations complete in `catalog_db_testing`.

Status nesta revisão: **NÃO EXECUTADO nesta máquina**. O projeto foi usado com PostgreSQL local em `127.0.0.1:5432`, inclusive com `catalog_db_testing` como infraestrutura de testes; isso não comprova o smoke test do serviço Docker Compose.

- [x] **Step 4: Commit the environment boundary**

```powershell
git add platform/compose.yaml platform/infra/docker/postgres/init-test-database.sql platform/.env.example platform/phpunit.xml
git commit -m "chore: configure PostgreSQL for catalog development"
```

---

### Task 2: Add the product persistence model and application invariants

**Files:**
- Modify: `platform/tests/Feature/Catalog/ProductPersistenceTest.php`
- Create: `platform/app/Features/Catalog/Models/Product.php`
- Create: `platform/database/factories/ProductFactory.php`
- Create: `platform/database/migrations/*_create_products_table.php`

**Interfaces:**
- Consumes: `Product::factory()->create(array $attributes = [])` through the application API.
- Produces: `Product` with UUIDv7 string key, normalized scalar attributes and soft-delete scope. The migration's existing PostgreSQL constraints remain defense-in-depth only; application code must remain correct without testing their internal mechanism.

- [x] **Step 1: Record the current persistence and application-level write tests**

The current `ProductPersistenceTest.php` uses `RefreshDatabase` and covers these exact observations:

```php
it('creates a product inactive and normalizes its text fields', function (): void {
    $product = Product::factory()->create([
        'sku' => '  abc-123  ',
        'name' => '  Café especial  ',
        'description' => '   ',
        'price_cents' => 1990,
    ]);

    expect($product)
        ->sku->toBe('ABC-123')
        ->name->toBe('Café especial')
        ->description->toBeNull()
        ->price_cents->toBe(1990)
        ->is_active->toBeFalse()
        ->deleted_at->toBeNull();
});

it('hides soft deleted products from normal queries', function (): void {
    $product = Product::factory()->create();
    $product->delete();

    expect(Product::query()->find($product->id))->toBeNull()
        ->and(Product::withTrashed()->find($product->id))->not->toBeNull();
});
```

Do not add a test dedicated to UUIDv7 generation: the revised spec removed that redundant case, while the model contract still requires `HasUuids`.

- [x] **Step 2: Verify persistence and write actions in the current full suite**

```powershell
php artisan test --compact tests/Feature/Catalog/ProductPersistenceTest.php
```

Current evidence: covered by `php artisan test --compact`, which passed **28/28 tests with 104 assertions** against the local PostgreSQL test database.

- [x] **Step 3: Generate and implement the migration**

Run:

```powershell
php artisan make:migration create_products_table --create=products --no-interaction
```

Implement `up()` with explicit timezone-aware, non-null timestamps. Keep the existing named PostgreSQL constraints as defense-in-depth, not as the source of business behavior or a dedicated test target:

```php
Schema::create('products', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->string('sku', 32)->unique();
    $table->string('name');
    $table->text('description')->nullable();
    $table->integer('price_cents');
    $table->boolean('is_active')->default(false);
    $table->timestampTz('created_at');
    $table->timestampTz('updated_at');
    $table->softDeletesTz();
});

DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_cents_positive CHECK (price_cents > 0)');
DB::statement('ALTER TABLE products ADD CONSTRAINT products_deleted_inactive CHECK (deleted_at IS NULL OR is_active = false)');
```

`down()` must call `Schema::dropIfExists('products')`.

- [x] **Step 4: Implement the model and factory**

`Product` must use `HasFactory<ProductFactory>`, `HasUuids` and `SoftDeletes`, declare `#[Fillable(['sku', 'name', 'description', 'price_cents'])]`, cast `price_cents` to `integer` and `is_active` to `boolean`, and expose these mutators:

```php
protected function sku(): Attribute
{
    return Attribute::make(
        set: fn (string $value): string => Str::upper(trim($value)),
    );
}

protected function name(): Attribute
{
    return Attribute::make(
        set: fn (string $value): string => trim($value),
    );
}

protected function description(): Attribute
{
    return Attribute::make(
        set: function (?string $value): ?string {
            $description = trim($value ?? '');

            return $description === '' ? null : $description;
        },
    );
}
```

`ProductFactory::definition()` must return valid defaults and keep activation opt-in:

```php
return [
    'sku' => Str::upper(fake()->unique()->bothify('SKU-####-??')),
    'name' => fake()->words(3, true),
    'description' => fake()->optional()->sentence(),
    'price_cents' => fake()->numberBetween(1, 1_000_000),
    'is_active' => false,
];
```

Add `active(): static` and `trashed(): static` factory states. `trashed()` must set both `is_active => false` and `deleted_at => now()`.

- [x] **Step 6: Preserve the historical persistence commit**

```powershell
git add platform/app/Features/Catalog/Models/Product.php platform/database/factories/ProductFactory.php platform/database/migrations platform/tests/Feature/Catalog/ProductPersistenceTest.php
git commit -m "feat: add catalog product persistence"
```

Historical evidence: `780ebaa`. Current evidence: the persistence cases are covered by the passing full suite (**28/28 tests, 104 assertions**).

---

### Task 3: Validate and normalize product form input

**Files:**
- Create: `platform/app/Features/Catalog/Rules/PlainText.php`
- Create: `platform/app/Features/Catalog/Http/Requests/StoreProductRequest.php`
- Create: `platform/app/Features/Catalog/Http/Requests/UpdateProductRequest.php`

**Interfaces:**
- Consumes: HTTP fields `sku`, `name`, `description`, `price_cents`; optional route-bound `Product` on update.
- Produces: `validated(): array{sku: string, name: string, description: ?string, price_cents: int}`; validation errors keyed by the submitted field.

- [x] **Step 1: Implement application-side normalization and validation**

Implement the existing Form Requests and `PlainText` rule. Do not create a separate validation test file: `ProductCrudTest.php` cobre hoje erros HTTP de nome obrigatório e preço não positivo, enquanto `ProductPersistenceTest.php` cobre a normalização feita pelos mutators. A regra `PlainText` existe no código, mas a suíte atual não testa a rejeição de HTML na entrada; o teste de CRUD cobre somente a saída HTML escapada. Os testes dedicados removidos não devem ser recriados.

- [x] **Step 2: Implement the plain-text rule**

Implement `PlainText implements ValidationRule`:

```php
public function validate(string $attribute, mixed $value, Closure $fail): void
{
    if (is_string($value) && strip_tags($value) !== $value) {
        $fail('O campo :attribute deve conter apenas texto simples.');
    }
}
```

- [x] **Step 3: Implement both Form Requests**

Both requests return `true` from `authorize()` and normalize the same fields in `prepareForValidation()`:

```php
$description = trim((string) $this->input('description', ''));

$this->merge([
    'sku' => Str::upper(trim((string) $this->input('sku', ''))),
    'name' => trim((string) $this->input('name', '')),
    'description' => $description === '' ? null : $description,
]);
```

Store rules:

```php
return [
    'sku' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('products', 'sku')],
    'name' => ['required', 'string', 'max:255'],
    'description' => ['nullable', 'string', new PlainText()],
    'price_cents' => ['required', 'integer', 'min:1'],
    'is_active' => ['prohibited'],
];
```

Update uses the same rules, replacing only the unique rule with:

```php
Rule::unique('products', 'sku')->ignore($this->route('product'))
```

Add Portuguese messages for `sku.regex`, `sku.unique`, `price_cents.integer`, `price_cents.min` and `is_active.prohibited`.

- [x] **Step 4: Preserve the historical input-validation commit**

```powershell
git add platform/app/Features/Catalog/Http/Requests platform/app/Features/Catalog/Rules
git commit -m "feat: validate catalog product input"
```

Historical evidence: `cd88412`. The dedicated validation tests were removed later by `6261a5f`; no replacement test file is part of the current suite.

---

### Task 4: Implement product creation and editing actions

**Files:**
- Modify: `platform/tests/Feature/Catalog/ProductPersistenceTest.php`
- Create: `platform/app/Features/Catalog/Actions/CreateProduct.php`
- Create: `platform/app/Features/Catalog/Actions/UpdateProduct.php`
- Create: `platform/app/Features/Catalog/Support/ProductSkuConflict.php`

**Interfaces:**
- Consumes: `CreateProduct::handle(array $attributes): Product`; `UpdateProduct::handle(Product $product, array $attributes): Product`.
- Produces: persisted, refreshed `Product`; quando PostgreSQL retorna `23505` para `products_sku_unique`, `ProductSkuConflict` traduz o conflito em `ValidationException` para `sku`; outras `QueryException` são relançadas. O teste atual cobre duplicidade sequencial, não uma corrida concorrente.

- [x] **Step 1: Record the current action tests**

The current `ProductPersistenceTest.php` covers creation state, update state and a sequential duplicate SKU:

```php
$product = resolve(CreateProduct::class)->handle(validProductAttributes(['is_active' => true]));
expect($product->is_active)->toBeFalse();

$active = Product::factory()->active()->create();
$updated = resolve(UpdateProduct::class)->handle($active, validProductAttributes(['name' => 'Novo nome']));
expect($updated->name)->toBe('Novo nome')->and($updated->is_active)->toBeTrue();

Product::factory()->create(['sku' => 'DUPLICATE']);
expect(fn () => resolve(CreateProduct::class)->handle(validProductAttributes(['sku' => 'DUPLICATE'])))
    ->toThrow(ValidationException::class);
```

The helper must return only `sku`, `name`, `description` and `price_cents`; the explicit `is_active` probe verifies that creation discards unauthorized state.

- [x] **Step 2: Verify the current action tests in the full suite**

```powershell
php artisan test --compact tests/Feature/Catalog/ProductPersistenceTest.php
```

- [x] **Step 3: Implement `23505` duplicate-conflict translation**

`ProductSkuConflict` exposes:

```php
public static function rethrow(QueryException $exception): never
```

It checks `($exception->errorInfo[0] ?? null) === '23505'` and that the exception message contains `products_sku_unique`. On match, throw:

```php
throw ValidationException::withMessages([
    'sku' => 'Este SKU já está em uso.',
]);
```

Otherwise, `throw $exception`.

- [x] **Step 4: Implement minimal write actions**

`CreateProduct` must whitelist fields and force inactivity:

```php
public function handle(array $attributes): Product
{
    try {
        $product = new Product(Arr::only($attributes, ['sku', 'name', 'description', 'price_cents']));
        $product->forceFill(['is_active' => false])->save();

        return $product;
    } catch (QueryException $exception) {
        ProductSkuConflict::rethrow($exception);
    }
}
```

`UpdateProduct` uses the same whitelist, calls `update()`, and returns `refresh()`. Both actions catch only `QueryException` and delegate to `ProductSkuConflict`.

- [x] **Step 5: Preserve the historical write-action commit**

```powershell
git add platform/app/Features/Catalog/Actions/CreateProduct.php platform/app/Features/Catalog/Actions/UpdateProduct.php platform/app/Features/Catalog/Support/ProductSkuConflict.php platform/tests/Feature/Catalog/ProductPersistenceTest.php
git commit -m "feat: add product write actions"
```

Historical evidence: `e81a967`. Current evidence: the action cases are covered by the passing full suite (**28/28 tests, 104 assertions**).

---

### Task 5: Implement activation, trash and restore transitions

**Files:**
- Create: `platform/tests/Feature/Catalog/ProductLifecycleTest.php`
- Create: `platform/app/Features/Catalog/Actions/ActivateProduct.php`
- Create: `platform/app/Features/Catalog/Actions/DeactivateProduct.php`
- Create: `platform/app/Features/Catalog/Actions/TrashProduct.php`
- Create: `platform/app/Features/Catalog/Actions/RestoreProduct.php`

**Interfaces:**
- Consumes: each Action receives one `Product` and returns the refreshed `Product`.
- Produces: explicit transitions `active`, `inactive`, `trashed + inactive`, and `restored + inactive`; trash and restore run in one database transaction each.

- [x] **Step 1: Record the current lifecycle test matrix**

The current `ProductLifecycleTest.php` covers:

```php
$activated = resolve(ActivateProduct::class)->handle($product);
expect($activated->is_active)->toBeTrue();

$deactivated = resolve(DeactivateProduct::class)->handle($activated);
expect($deactivated->is_active)->toBeFalse()->and($deactivated->trashed())->toBeFalse();

$trashed = resolve(TrashProduct::class)->handle(Product::factory()->active()->create());
expect($trashed->is_active)->toBeFalse()->and($trashed->trashed())->toBeTrue();

$restored = resolve(RestoreProduct::class)->handle($trashed);
expect($restored->trashed())->toBeFalse()->and($restored->is_active)->toBeFalse();
```

Also assert that the trashed product disappears from `Product::query()` and remains in `Product::onlyTrashed()`.

- [x] **Step 2: Verify the current lifecycle tests in the full suite**

```powershell
php artisan test --compact tests/Feature/Catalog/ProductLifecycleTest.php
```

- [x] **Step 3: Implement activation and deactivation**

Each action updates only `is_active`, then returns `$product->refresh()`. `ActivateProduct` must reject a trashed model by calling `$product->trashed()` and throwing `LogicException('Um produto na lixeira não pode ser ativado.')`.

- [x] **Step 4: Implement transactional trash and restore**

`TrashProduct`:

```php
return DB::transaction(function () use ($product): Product {
    $product->forceFill(['is_active' => false])->save();
    $product->delete();

    return $product->refresh();
});
```

`RestoreProduct`:

```php
return DB::transaction(function () use ($product): Product {
    $product->forceFill(['is_active' => false]);
    $product->restore();

    return $product->refresh();
});
```

The application sets the product inactive before trashing and restores it inactive inside the transaction. Existing database constraints may reject accidental invalid states as an additional safeguard; no test targets that database behavior.

- [x] **Step 5: Preserve the historical lifecycle commit**

```powershell
git add platform/app/Features/Catalog/Actions/ActivateProduct.php platform/app/Features/Catalog/Actions/DeactivateProduct.php platform/app/Features/Catalog/Actions/TrashProduct.php platform/app/Features/Catalog/Actions/RestoreProduct.php platform/tests/Feature/Catalog/ProductLifecycleTest.php
git commit -m "feat: manage product lifecycle"
```

Historical evidence: `1dd2d39`. Current evidence: the lifecycle cases are covered by the passing full suite (**28/28 tests, 104 assertions**).

---

### Task 6: Expose the catalog through web controllers and named routes

**Files:**
- Create: `platform/tests/Feature/Catalog/ProductCrudTest.php`
- Create: `platform/app/Features/Catalog/Http/Controllers/ProductController.php`
- Create: `platform/app/Features/Catalog/Http/Controllers/ProductStatusController.php`
- Create: `platform/app/Features/Catalog/Http/Controllers/ProductTrashController.php`
- Modify: `platform/routes/web.php`

**Interfaces:**
- Consumes: traditional web requests and route-bound `Product` UUIDs.
- Produces: named routes `products.*`, redirects with session key `status`, normal listing without trashed records, and a separate trash listing.

- [x] **Step 1: Record the current HTTP flow tests**

The current `ProductCrudTest.php` covers the exact route behavior:

- `GET products.index` lists normal products and omits trashed products;
- `GET products.show`, `products.create` and `products.edit` return 200;
- `POST products.store` creates inactive and redirects to `products.show`;
- invalid store/update redirects back with field errors and preserves input;
- `PUT products.update` edits an active product without deactivating it;
- `PATCH products.activate` and `products.deactivate` call their explicit transitions;
- `DELETE products.destroy` soft-deletes and redirects to index;
- `GET products.trash.index` lists only trashed products;
- `PATCH products.trash.restore` restores inactive;
- normal product routes return 404 for a trashed product, while restore resolves it with `withTrashed()` binding.

Use application-level persistence assertions for every mutation, including `assertSoftDeleted('products', ['id' => $product->id, 'is_active' => false])`; these verify the app result and do not test PostgreSQL internals.

- [x] **Step 2: Inspect the current named routes**

```powershell
php artisan route:list --path=products --except-vendor
```

- [x] **Step 3: Register the routes**

Replace the welcome route with:

```php
Route::redirect('/', '/products');

Route::resource('products', ProductController::class);
Route::patch('products/{product}/activate', [ProductStatusController::class, 'activate'])
    ->name('products.activate');
Route::patch('products/{product}/deactivate', [ProductStatusController::class, 'deactivate'])
    ->name('products.deactivate');
Route::get('products-trash', [ProductTrashController::class, 'index'])
    ->name('products.trash.index');
Route::patch('products-trash/{product}/restore', [ProductTrashController::class, 'restore'])
    ->withTrashed()
    ->name('products.trash.restore');
```

- [x] **Step 4: Implement the controllers**

`ProductController` contracts:

```php
public function index(): View
public function create(): View
public function store(StoreProductRequest $request, CreateProduct $createProduct): RedirectResponse
public function show(Product $product): View
public function edit(Product $product): View
public function update(UpdateProductRequest $request, Product $product, UpdateProduct $updateProduct): RedirectResponse
public function destroy(Product $product, TrashProduct $trashProduct): RedirectResponse
```

`index()` uses `Product::query()->latest()->paginate(15)`. Store/update pass `$request->validated()` to the Actions. Mutations redirect with Portuguese status messages.

`ProductStatusController` exposes `activate(Product, ActivateProduct)` and `deactivate(Product, DeactivateProduct)`, both returning to `products.show`.

`ProductTrashController::index()` passes `Product::onlyTrashed()->latest('deleted_at')->paginate(15)` to `catalog.products.trash`; `restore()` invokes `RestoreProduct` and redirects to `products.edit`.

- [x] **Step 5: Preserve the historical web-flow commit**

```powershell
git add platform/app/Features/Catalog/Http/Controllers platform/routes/web.php platform/tests/Feature/Catalog/ProductCrudTest.php
git commit -m "feat: expose catalog product web flows"
```

Historical evidence: `fa42e7a`. Current evidence: route inspection passed and `ProductCrudTest.php` is covered by the passing full suite (**28/28 tests, 104 assertions**).

---

### Task 7: Build the server-rendered catalog screens

**Files:**
- Create: `platform/resources/views/layouts/app.blade.php`
- Create: `platform/resources/views/catalog/products/_form.blade.php`
- Create: `platform/resources/views/catalog/products/index.blade.php`
- Create: `platform/resources/views/catalog/products/create.blade.php`
- Create: `platform/resources/views/catalog/products/edit.blade.php`
- Create: `platform/resources/views/catalog/products/show.blade.php`
- Create: `platform/resources/views/catalog/products/trash.blade.php`
- Modify: `platform/tests/Feature/Catalog/ProductCrudTest.php`

**Interfaces:**
- Consumes: `$products`, `$product`, validation errors and `session('status')` supplied by controllers.
- Produces: accessible HTML forms using named routes, CSRF protection, PUT/PATCH/DELETE method spoofing and escaped plain-text output.

- [x] **Step 1: Record the current view-content assertions**

The current `ProductCrudTest.php` asserts:

- index shows SKU, name, formatted BRL price and status;
- show renders description escaped, never with `{!! !!}`;
- create/edit contain inputs named `sku`, `name`, `description`, `price_cents`, but no quantity or `is_active` input;
- trash view shows restore controls and does not offer physical deletion;
- pagination renders when 16 products exist.

Use `Number::currency($product->price_cents / 100, in: 'BRL', locale: 'pt_BR')` as the single display convention.

- [x] **Step 2: Verify the current view and HTTP tests in the full suite**

```powershell
php artisan test --compact tests/Feature/Catalog/ProductCrudTest.php
```

- [x] **Step 3: Create the shared layout and form**

The layout must load `resources/css/app.css` and `resources/js/app.js` with `@vite`, provide links to `products.index`, `products.create` and `products.trash.index`, render `session('status')`, and expose `@yield('content')` inside a centered `<main>`.

`_form.blade.php` must contain labels and fields with these exact contracts:

```blade
<input name="sku" maxlength="32" required value="{{ old('sku', $product?->sku) }}">
<input name="name" maxlength="255" required value="{{ old('name', $product?->name) }}">
<textarea name="description">{{ old('description', $product?->description) }}</textarea>
<input name="price_cents" type="number" min="1" step="1" required value="{{ old('price_cents', $product?->price_cents) }}">
```

Render each field error with `@error`. The create view passes `['product' => null]`; edit passes its model. Neither view includes activation or quantity fields.

- [x] **Step 4: Create list, detail and trash screens**

`index.blade.php` renders a table with SKU, name, BRL price, `Ativo`/`Inativo`, and links to show/edit. `show.blade.php` renders product details plus separate forms for activate/deactivate and delete. `trash.blade.php` renders deleted timestamp and a PATCH restore form only. All forms include `@csrf`; non-POST forms include the matching `@method`.

- [x] **Step 5: Preserve the historical view commit**

```powershell
git add platform/resources/views platform/tests/Feature/Catalog/ProductCrudTest.php
git commit -m "feat: add catalog product views"
```

Historical evidence: `9e8ff84`. Current evidence: HTTP/view cases are covered by the passing full suite (**28/28 tests, 104 assertions**), Pint's check passed and the production asset build passed.

---

### Task 8: Document operation and run the full quality gate

**Files:**
- Modify: `platform/README.md`
- Verify: all files changed by Tasks 1–7

**Interfaces:**
- Consumes: fresh clone with PHP, Composer, Node, Docker and Docker Compose.
- Produces: instruções reproduzíveis de setup e os comandos do quality gate; o plano não declara esses comandos aprovados sem saída atual ou evidência histórica explícita.

- [x] **Step 1: Replace scaffold-only README instructions with project commands**

Document, in this order:

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
docker compose up -d catalog_db
php artisan migrate
npm install
npm run build
php artisan serve
```

Also document the test and quality commands:

```powershell
php artisan test --compact
vendor/bin/phpstan analyse
vendor/bin/rector process --dry-run
vendor/bin/pint --test
```

State explicitly that prices are entered as integer BRL cents and that the test suite requires `catalog_db_testing` in PostgreSQL.

- [x] **Step 2: Run the full test suite against PostgreSQL**

```powershell
php artisan config:clear
php artisan test --compact
```

Current evidence: `php artisan test --compact` passed **28/28 tests with 104 assertions** using local PostgreSQL as the application test infrastructure.

- [x] **Step 3: Run static and automated quality checks**

```powershell
vendor/bin/pint --test
vendor/bin/phpstan analyse
vendor/bin/rector process --dry-run
npm run build
```

Current evidence: PHPStan finished with **0 errors**; Rector dry-run finished with **0 changes and 0 errors**; `vendor/bin/pint --test` passed; and `npm run build` passed.

- [x] **Step 4: Review acceptance boundaries manually**

Run:

```powershell
php artisan route:list --path=products --except-vendor
git diff --check
git status --short
```

Confirm there are no API routes, stock/quantity fields, physical-delete endpoints, isolated `is_active` index, floating-point price columns, or accidental changes to `application.md`.

Evidence nesta revisão: `route:list` mostrou 11 rotas web de produtos; buscas em `app`, `routes`, views e migrations não encontraram API, estoque/quantidade, exclusão física, índice isolado de `is_active` ou coluna de preço em ponto flutuante; `git diff --check` passou. `application.md` já estava não rastreado e permaneceu fora do escopo.

- [x] **Step 5: Commit documentation separately**

Historical evidence: `ee2924b` committed the README workflow. This plan correction must remain uncommitted in the current task.

```powershell
git add platform/README.md
git commit -m "docs: document catalog development workflow"
```

## Final Acceptance Checklist

- [x] `CreateProduct::handle()` descarta `is_active` recebido e cria o produto inativo; o teste atual cobre esse comportamento da Action.
- [x] No fluxo HTTP suportado, os Form Requests normalizam e validam o SKU, e `Rule::unique` inclui registros na lixeira; chamadas diretas às Actions assumem atributos vindos de `validated()`.
- [x] Os mutators removem espaços externos de nome/SKU e convertem descrição em branco para `null`; o teste atual cobre essa normalização.
- [ ] A regra `PlainText` rejeita HTML no código, mas a suíte atual não testa essa rejeição na entrada; `ProductCrudTest.php` testa apenas a renderização escapada.
- [x] Os Form Requests exigem `price_cents` inteiro e positivo no fluxo HTTP; o teste HTTP atual cobre `price_cents = 0`, e as Actions assumem dados validados.
- [x] Active products can be edited without implicit deactivation.
- [x] Deactivation does not soft-delete.
- [x] Trash atomically deactivates and soft-deletes; normal queries omit the row.
- [x] Restore returns the product inactive.
- [x] Existing PostgreSQL constraints remain defense-in-depth only; no dedicated test proves their internal behavior.
- [x] O teste atual cobre SKU duplicado sequencial na Action; `ProductSkuConflict` traduz `23505` de `products_sku_unique` em erro de validação, sem afirmar cobertura de corrida concorrente.
- [x] CRUD, lifecycle, trash and restore are available through server-rendered web routes.
- [x] No stock quantity or future-service integration was introduced.
- [x] Gate atual concluído: Pest **28/28 tests, 104 assertions**; PHPStan/Larastan **0 errors**; Rector dry-run **0 changes and 0 errors**; Pint `--test` passou; Vite `npm run build` passou.
