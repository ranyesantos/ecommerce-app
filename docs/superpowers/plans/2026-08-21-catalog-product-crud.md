# Catalog Product CRUD Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implementar o CRUD web inicial de produtos no Laravel, com UUIDv7, SKU normalizado e único, preço inteiro em centavos, ativação explícita e lixeira com soft delete.

**Architecture:** Manter o catálogo em uma feature slice sob `App\Features\Catalog`, com Form Requests na borda HTTP, Actions para escritas e transições, Eloquent como persistência e Blade para a interface server-side. O PostgreSQL será a fonte definitiva das invariantes concorrentes e os testes Pest usarão uma base PostgreSQL real.

**Tech Stack:** PHP 8.5, Laravel 13.17, PostgreSQL, Blade, Tailwind CSS 4, Pest 5, Pint 1.30, Larastan 3.10 e Rector 2.6.

**Spec:** [`docs/superpowers/specs/2026-08-21-catalog-product-model-design.md`](../specs/2026-08-21-catalog-product-model-design.md)

## Global Constraints

- O Laravel atual está na raiz de `platform/`; não criar outro projeto Laravel nem mover o scaffold neste trabalho.
- Usar Pest, conforme a spec e a configuração já versionada, mesmo que a regra gerada do Boost ainda mencione PHPUnit.
- Gerar IDs com `Illuminate\Database\Eloquent\Concerns\HasUuids`; no Laravel 13 instalado, esse trait usa `Str::uuid7()`.
- Não adicionar quantidade, estoque, variantes, moeda configurável, custo, histórico de preço, autenticação, API pública ou exclusão física.
- Não aceitar `is_active` nos formulários de criação/edição. Ativação, desativação, exclusão e restauração são comandos separados.
- Manter `price_cents` como inteiro recebido pelo formulário e armazenado em BRL; não introduzir parser decimal nesta etapa.
- A unicidade de SKU inclui registros com soft delete. Não usar `withoutTrashed()` na regra `unique`.
- Testes que provam `CHECK constraints` devem rodar em PostgreSQL; SQLite não satisfaz a aceitação.
- Preservar `application.md`, atualmente não rastreado e fora do escopo.
- Usar `php artisan make:* --no-interaction` quando houver generator adequado e `apply_patch` para completar/mover o conteúdo.
- Antes de cada commit PHP, executar o teste focal e `vendor/bin/pint --dirty --format agent`.

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
│   ├── ProductDatabaseConstraintsTest.php
│   ├── ProductLifecycleTest.php
│   ├── ProductPersistenceTest.php
│   └── ProductValidationTest.php
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

- [ ] **Step 1: Define the two PostgreSQL databases**

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

- [ ] **Step 2: Make PostgreSQL the documented local default**

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

- [ ] **Step 3: Start and smoke-test the database**

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

Expected: PostgreSQL reports `accepting connections`; the scaffold migrations complete in `catalog_db_testing`.

- [ ] **Step 4: Commit the environment boundary**

```powershell
git add platform/compose.yaml platform/infra/docker/postgres/init-test-database.sql platform/.env.example platform/phpunit.xml
git commit -m "chore: configure PostgreSQL for catalog development"
```

---

### Task 2: Add the product persistence model and database invariants

**Files:**
- Create: `platform/tests/Feature/Catalog/ProductPersistenceTest.php`
- Create: `platform/tests/Feature/Catalog/ProductDatabaseConstraintsTest.php`
- Create: `platform/app/Features/Catalog/Models/Product.php`
- Create: `platform/database/factories/ProductFactory.php`
- Create: `platform/database/migrations/*_create_products_table.php`

**Interfaces:**
- Consumes: `Product::factory()->create(array $attributes = [])` and direct PostgreSQL writes.
- Produces: `Product` with UUIDv7 string key, normalized scalar attributes, soft-delete scope and database constraints `products_sku_unique`, `products_price_cents_positive` and `products_deleted_inactive`.

- [ ] **Step 1: Write failing persistence tests**

Create `ProductPersistenceTest.php` using `RefreshDatabase` and cover these exact observations:

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

- [ ] **Step 2: Run the focused test and confirm the expected failure**

```powershell
php artisan test --compact tests/Feature/Catalog/ProductPersistenceTest.php
```

Expected: failure because `App\Features\Catalog\Models\Product` does not exist.

- [ ] **Step 3: Generate and implement the migration**

Run:

```powershell
php artisan make:migration create_products_table --create=products --no-interaction
```

Implement `up()` with explicit timezone-aware, non-null timestamps and named PostgreSQL checks:

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

- [ ] **Step 4: Implement the model and factory**

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

- [ ] **Step 5: Prove the PostgreSQL constraints directly**

Create `ProductDatabaseConstraintsTest.php` with `RefreshDatabase`. Use `DB::table('products')->insert(...)` so model validation cannot mask the database behavior. Add three cases:

```php
it('rejects non-positive prices at the database boundary', function (int $price): void {
    expect(fn () => insertProduct(['price_cents' => $price]))
        ->toThrow(QueryException::class);
})->with([0, -1]);

it('rejects an active soft deleted product at the database boundary', function (): void {
    expect(fn () => insertProduct([
        'is_active' => true,
        'deleted_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('reserves the sku of a soft deleted product', function (): void {
    Product::factory()->trashed()->create(['sku' => 'RESERVED-1']);

    expect(fn () => Product::factory()->create(['sku' => 'RESERVED-1']))
        ->toThrow(QueryException::class);
});
```

Define a file-local `insertProduct(array $overrides = []): void` helper that merges a fresh `Str::uuid7()`, unique uppercase SKU, name, positive price, inactive state and `now()` timestamps.

- [ ] **Step 6: Verify and commit persistence**

```powershell
php artisan test --compact tests/Feature/Catalog/ProductPersistenceTest.php tests/Feature/Catalog/ProductDatabaseConstraintsTest.php
vendor/bin/pint --dirty --format agent
git add platform/app/Features/Catalog/Models/Product.php platform/database/factories/ProductFactory.php platform/database/migrations platform/tests/Feature/Catalog/ProductPersistenceTest.php platform/tests/Feature/Catalog/ProductDatabaseConstraintsTest.php
git commit -m "feat: add catalog product persistence"
```

---

### Task 3: Validate and normalize product form input

**Files:**
- Create: `platform/tests/Feature/Catalog/ProductValidationTest.php`
- Create: `platform/app/Features/Catalog/Rules/PlainText.php`
- Create: `platform/app/Features/Catalog/Http/Requests/StoreProductRequest.php`
- Create: `platform/app/Features/Catalog/Http/Requests/UpdateProductRequest.php`

**Interfaces:**
- Consumes: HTTP fields `sku`, `name`, `description`, `price_cents`; optional route-bound `Product` on update.
- Produces: `validated(): array{sku: string, name: string, description: ?string, price_cents: int}`; validation errors keyed by the submitted field.

- [ ] **Step 1: Write failing validation tests through temporary test routes**

In `ProductValidationTest.php`, register POST and PUT routes inside `beforeEach()` whose closures type-hint the corresponding Form Request and return `response()->json($request->validated())`. Cover:

- lowercase and outer whitespace become `ABC-123` before uniqueness validation;
- duplicate SKU is rejected case-insensitively, including when the existing product is trashed;
- update ignores the current product but not another product;
- SKU rejects spaces, accents, dots and `/`, and accepts only `[A-Z0-9_-]` after normalization;
- `name` is required and at most 255 characters;
- blank description becomes `null`;
- description containing an HTML tag is rejected;
- `price_cents` rejects zero, negative, decimal and non-numeric values;
- submitted `is_active` is prohibited.

Use assertions such as:

```php
$this->postJson('/_test/products', validProductPayload([
    'sku' => '  abc-123  ',
    'description' => '   ',
]))->assertOk()->assertExactJson([
    'sku' => 'ABC-123',
    'name' => 'Produto',
    'description' => null,
    'price_cents' => 1990,
]);
```

- [ ] **Step 2: Confirm the Form Requests are missing**

```powershell
php artisan test --compact tests/Feature/Catalog/ProductValidationTest.php
```

Expected: failure resolving `StoreProductRequest`.

- [ ] **Step 3: Implement the plain-text rule**

Implement `PlainText implements ValidationRule`:

```php
public function validate(string $attribute, mixed $value, Closure $fail): void
{
    if (is_string($value) && strip_tags($value) !== $value) {
        $fail('O campo :attribute deve conter apenas texto simples.');
    }
}
```

- [ ] **Step 4: Implement both Form Requests**

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

- [ ] **Step 5: Verify and commit input validation**

```powershell
php artisan test --compact tests/Feature/Catalog/ProductValidationTest.php
vendor/bin/pint --dirty --format agent
git add platform/app/Features/Catalog/Http/Requests platform/app/Features/Catalog/Rules platform/tests/Feature/Catalog/ProductValidationTest.php
git commit -m "feat: validate catalog product input"
```

---

### Task 4: Implement product creation and editing actions

**Files:**
- Modify: `platform/tests/Feature/Catalog/ProductPersistenceTest.php`
- Create: `platform/app/Features/Catalog/Actions/CreateProduct.php`
- Create: `platform/app/Features/Catalog/Actions/UpdateProduct.php`
- Create: `platform/app/Features/Catalog/Support/ProductSkuConflict.php`

**Interfaces:**
- Consumes: `CreateProduct::__invoke(array $attributes): Product`; `UpdateProduct::__invoke(Product $product, array $attributes): Product`.
- Produces: persisted, refreshed `Product`; a PostgreSQL `23505` on `products_sku_unique` becomes `ValidationException` for `sku`; unrelated `QueryException` is rethrown unchanged.

- [ ] **Step 1: Add failing action tests**

Add cases proving:

```php
$product = app(CreateProduct::class)(validProductAttributes(['is_active' => true]));
expect($product->is_active)->toBeFalse();

$active = Product::factory()->active()->create();
$updated = app(UpdateProduct::class)($active, validProductAttributes(['name' => 'Novo nome']));
expect($updated->name)->toBe('Novo nome')->and($updated->is_active)->toBeTrue();

Product::factory()->create(['sku' => 'DUPLICATE']);
expect(fn () => app(CreateProduct::class)(validProductAttributes(['sku' => 'DUPLICATE'])))
    ->toThrow(ValidationException::class);
```

The helper must return only `sku`, `name`, `description` and `price_cents`; the explicit `is_active` probe verifies that creation discards unauthorized state.

- [ ] **Step 2: Confirm the action classes are missing**

```powershell
php artisan test --compact tests/Feature/Catalog/ProductPersistenceTest.php
```

- [ ] **Step 3: Implement PostgreSQL unique-conflict translation**

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

- [ ] **Step 4: Implement minimal write actions**

`CreateProduct` must whitelist fields and force inactivity:

```php
public function __invoke(array $attributes): Product
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

- [ ] **Step 5: Verify and commit write actions**

```powershell
php artisan test --compact tests/Feature/Catalog/ProductPersistenceTest.php
vendor/bin/pint --dirty --format agent
git add platform/app/Features/Catalog/Actions/CreateProduct.php platform/app/Features/Catalog/Actions/UpdateProduct.php platform/app/Features/Catalog/Support/ProductSkuConflict.php platform/tests/Feature/Catalog/ProductPersistenceTest.php
git commit -m "feat: add product write actions"
```

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

- [ ] **Step 1: Write the complete lifecycle test matrix**

Create Pest cases for:

```php
$activated = app(ActivateProduct::class)($product);
expect($activated->is_active)->toBeTrue();

$deactivated = app(DeactivateProduct::class)($activated);
expect($deactivated->is_active)->toBeFalse()->and($deactivated->trashed())->toBeFalse();

$trashed = app(TrashProduct::class)(Product::factory()->active()->create());
expect($trashed->is_active)->toBeFalse()->and($trashed->trashed())->toBeTrue();

$restored = app(RestoreProduct::class)($trashed);
expect($restored->trashed())->toBeFalse()->and($restored->is_active)->toBeFalse();
```

Also assert that the trashed product disappears from `Product::query()` and remains in `Product::onlyTrashed()`.

- [ ] **Step 2: Run and observe the missing Actions**

```powershell
php artisan test --compact tests/Feature/Catalog/ProductLifecycleTest.php
```

- [ ] **Step 3: Implement activation and deactivation**

Each action updates only `is_active`, then returns `$product->refresh()`. `ActivateProduct` must reject a trashed model by calling `$product->trashed()` and throwing `LogicException('Um produto na lixeira não pode ser ativado.')`.

- [ ] **Step 4: Implement transactional trash and restore**

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

This ordering satisfies `products_deleted_inactive` throughout both transitions.

- [ ] **Step 5: Verify and commit lifecycle behavior**

```powershell
php artisan test --compact tests/Feature/Catalog/ProductLifecycleTest.php tests/Feature/Catalog/ProductDatabaseConstraintsTest.php
vendor/bin/pint --dirty --format agent
git add platform/app/Features/Catalog/Actions/ActivateProduct.php platform/app/Features/Catalog/Actions/DeactivateProduct.php platform/app/Features/Catalog/Actions/TrashProduct.php platform/app/Features/Catalog/Actions/RestoreProduct.php platform/tests/Feature/Catalog/ProductLifecycleTest.php
git commit -m "feat: manage product lifecycle"
```

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

- [ ] **Step 1: Write failing HTTP flow tests**

Cover the exact route behavior:

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

Use database assertions for every mutation, including `assertSoftDeleted('products', ['id' => $product->id, 'is_active' => false])`.

- [ ] **Step 2: Confirm named routes do not exist**

```powershell
php artisan test --compact tests/Feature/Catalog/ProductCrudTest.php
```

- [ ] **Step 3: Register the routes**

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

- [ ] **Step 4: Implement the controllers**

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

- [ ] **Step 5: Verify route wiring and commit**

```powershell
php artisan route:list --path=products --except-vendor
php artisan test --compact tests/Feature/Catalog/ProductCrudTest.php
vendor/bin/pint --dirty --format agent
git add platform/app/Features/Catalog/Http/Controllers platform/routes/web.php platform/tests/Feature/Catalog/ProductCrudTest.php
git commit -m "feat: expose catalog product web flows"
```

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

- [ ] **Step 1: Add failing view-content assertions**

Extend HTTP tests to assert:

- index shows SKU, name, formatted BRL price and status;
- show renders description escaped, never with `{!! !!}`;
- create/edit contain inputs named `sku`, `name`, `description`, `price_cents`, but no quantity or `is_active` input;
- trash view shows restore controls and does not offer physical deletion;
- pagination renders when 16 products exist.

Use `Number::currency($product->price_cents / 100, in: 'BRL', locale: 'pt_BR')` as the single display convention.

- [ ] **Step 2: Confirm the views are missing**

```powershell
php artisan test --compact tests/Feature/Catalog/ProductCrudTest.php
```

- [ ] **Step 3: Create the shared layout and form**

The layout must load `resources/css/app.css` and `resources/js/app.js` with `@vite`, provide links to `products.index`, `products.create` and `products.trash.index`, render `session('status')`, and expose `@yield('content')` inside a centered `<main>`.

`_form.blade.php` must contain labels and fields with these exact contracts:

```blade
<input name="sku" maxlength="32" required value="{{ old('sku', $product?->sku) }}">
<input name="name" maxlength="255" required value="{{ old('name', $product?->name) }}">
<textarea name="description">{{ old('description', $product?->description) }}</textarea>
<input name="price_cents" type="number" min="1" step="1" required value="{{ old('price_cents', $product?->price_cents) }}">
```

Render each field error with `@error`. The create view passes `['product' => null]`; edit passes its model. Neither view includes activation or quantity fields.

- [ ] **Step 4: Create list, detail and trash screens**

`index.blade.php` renders a table with SKU, name, BRL price, `Ativo`/`Inativo`, and links to show/edit. `show.blade.php` renders product details plus separate forms for activate/deactivate and delete. `trash.blade.php` renders deleted timestamp and a PATCH restore form only. All forms include `@csrf`; non-POST forms include the matching `@method`.

- [ ] **Step 5: Verify assets, views and commit**

```powershell
npm run build
php artisan test --compact tests/Feature/Catalog/ProductCrudTest.php
vendor/bin/pint --dirty --format agent
git add platform/resources/views platform/tests/Feature/Catalog/ProductCrudTest.php
git commit -m "feat: add catalog product views"
```

---

### Task 8: Document operation and run the full quality gate

**Files:**
- Modify: `platform/README.md`
- Verify: all files changed by Tasks 1–7

**Interfaces:**
- Consumes: fresh clone with PHP, Composer, Node, Docker and Docker Compose.
- Produces: reproducible local setup and a fully passing catalog quality gate.

- [ ] **Step 1: Replace scaffold-only README instructions with project commands**

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
vendor/bin/pint --format agent
```

State explicitly that prices are entered as integer BRL cents and that the test suite requires `catalog_db_testing` in PostgreSQL.

- [ ] **Step 2: Run the full test suite against PostgreSQL**

```powershell
php artisan config:clear
php artisan test --compact
```

Expected: all Unit and Feature tests pass; no SQLite connection appears in output or configuration.

- [ ] **Step 3: Run static and automated quality checks**

```powershell
vendor/bin/pint --format agent
vendor/bin/phpstan analyse
vendor/bin/rector process --dry-run
npm run build
```

Expected: Pint finishes cleanly, PHPStan reports no errors, Rector proposes no changes, and Vite completes the production build.

- [ ] **Step 4: Review acceptance boundaries manually**

Run:

```powershell
php artisan route:list --path=products --except-vendor
git diff --check
git status --short
```

Confirm there are no API routes, stock/quantity fields, physical-delete endpoints, isolated `is_active` index, floating-point price columns, or accidental changes to `application.md`.

- [ ] **Step 5: Commit documentation separately**

```powershell
git add platform/README.md
git commit -m "docs: document catalog development workflow"
```

## Final Acceptance Checklist

- [ ] Product creation always starts inactive.
- [ ] SKU is trimmed, uppercased, format-validated and globally unique including trash.
- [ ] Name is trimmed; blank description becomes `null`; HTML description is rejected.
- [ ] Price is a positive PostgreSQL `integer` expressed in BRL cents.
- [ ] Active products can be edited without implicit deactivation.
- [ ] Deactivation does not soft-delete.
- [ ] Trash atomically deactivates and soft-deletes; normal queries omit the row.
- [ ] Restore returns the product inactive.
- [ ] Database-level unique and check violations are proven on PostgreSQL.
- [ ] Unique races become a comprehensible `sku` validation error.
- [ ] CRUD, lifecycle, trash and restore are available through server-rendered web routes.
- [ ] No stock quantity or future-service integration was introduced.
- [ ] Pest, Pint, Larastan, Rector and Vite all pass.
