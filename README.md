# Solveit OpsHub

Internal operations hub built with Laravel 13, Inertia, React, and TypeScript. The current implementation checkpoint and remaining scope are recorded in [the checkpoint and operations guide](docs/CHECKPOINT.md), with requirements in [the PRD](docs/PRD.md) and task order in [the implementation plan](docs/IMPLEMENTATION_PLAN.md).

## Database policy

Every database workflow uses **MySQL 8.4 / InnoDB / utf8mb4**: application runtime, migrations, automated tests, and CI. Only the MySQL relational connection is configured. The application rejects other drivers and MariaDB servers. Redis remains the planned queue/cache/lock service, not an alternative application database. See [ADR-0005](docs/adr/0005-mysql-primary-database.md).

## Local setup

Install PHP 8.5 with `pdo_mysql`, Composer, Node 22/npm, and Docker with Compose. Start Docker, then run:

```sh
composer setup
composer dev
```

`composer setup` installs dependencies, prepares the ignored `.env`, starts the project MySQL service, checks the authenticated connection, applies pending runtime migrations, installs frontend dependencies from the lockfile, and builds assets. Empty local application/database secrets are generated; existing `APP_KEY` and credentials are preserved. Credentials must never be committed.

| Purpose | Host / port | Database | Local account |
|---|---|---|---|
| Runtime | `127.0.0.1:3308` | `solveit_opshub` | `opshub` |
| Tests | `127.0.0.1:3308` | `solveit_opshub_test` | `opshub` |

Docker publishes MySQL on loopback only and stores data in the project's persistent `mysql_data` volume. Initialization grants the local application account access to these two project databases. Port `3308` avoids the XAMPP service on `3306`; set `DB_PORT` in `.env` if a different available local port is needed. Changing credentials in `.env` does not rotate accounts in an already initialized volume.

```sh
composer db:up
composer db:check
php artisan migrate:status
composer db:down
```

`db:down` stops the Compose service without deleting its data volume. Use normal `php artisan migrate` for pending application migrations. Existing data must be backed up and verified before any separate transfer or destructive schema operation. No operational Owner account or live connector is provisioned by setup.

## Verification

```sh
composer test
php vendor/bin/pint --test
composer validate --strict
```

`composer test` clears cached configuration, builds frontend assets, then runs the complete suite on MySQL. Build assets before direct `php artisan test` or `php vendor/bin/phpunit` calls because Inertia HTTP tests require the Vite manifest.

PHPUnit forces `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_DATABASE=solveit_opshub_test`, and an empty `DB_URL`. Host, port, and credentials come from the local `.env` or the CI environment. The test base checks the environment, MySQL driver, and a database name ending in `_test` before `RefreshDatabase` can reset tables. Runtime and test schemas remain separate; the test database is disposable.

## CI

[The CI workflow](.github/workflows/ci.yml) provisions a disposable MySQL 8.4 service and `solveit_opshub_test`, installs PHP 8.5 with `pdo_mysql` and Node 22, installs locked dependencies, builds frontend assets, runs the complete MySQL suite, then checks PHP formatting. CI's published passwords and generated application key are runner-only test values. There is no alternate database test path.
