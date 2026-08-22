# Catalog Product CRUD

This Laravel application provides the server-rendered catalog product CRUD and
its lifecycle operations. Local development requires PHP, Composer, Node.js,
Docker, and Docker Compose. PostgreSQL is required for the application and test
database; SQLite is not supported.

## Local setup

Run these commands from the `platform` directory, in order:

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

The application is then available at the URL shown by `php artisan serve`.

Product prices are entered as positive integer BRL cents, not floating-point
currency values. The test suite requires a PostgreSQL database named
`catalog_db_testing`.

## Tests and quality checks

Clear cached configuration and run the full test suite against PostgreSQL:

```powershell
php artisan config:clear
php artisan test --compact
```

Run the static analysis, formatting, refactoring, and production build checks:

```powershell
vendor/bin/pint --format agent
vendor/bin/phpstan analyse
vendor/bin/rector process --dry-run
npm run build
```

Pest, Pint, Larastan, Rector, and Vite must all pass before changes are
considered ready.
