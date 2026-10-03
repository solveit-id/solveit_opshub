# ADR-0005: MySQL for every database workflow

**Status:** accepted by project owner on 4 Oktober 2026

## Decision

Use MySQL 8.4 with InnoDB and `utf8mb4` for application runtime, migrations, automated tests, and CI without an alternate relational backend. This supersedes earlier database recommendations and the earlier fast-test configuration. PRD section 22.1, the implementation plan, ADR-0001, and ADR-0003 follow this decision.

`config/database.php` defines only the MySQL relational connection. Service-provider registration removes Laravel's automatically merged connection templates, so effective configuration also contains only MySQL, including when cached. The application rejects a non-MySQL default or connection and identifies MariaDB server versions as unsupported. Runtime defaults and queue batch/failed-job database metadata use MySQL. Redis remains the planned queue/cache/lock infrastructure.

## Local runtime and setup

The project Compose service runs the official `mysql:8.4` image with a persistent project volume and a loopback-only port, defaulting to `127.0.0.1:3308`. This avoids the existing XAMPP MariaDB service. Runtime uses `solveit_opshub`; automated tests use `solveit_opshub_test`. The non-root local account has access to both project schemas.

`composer setup` installs dependencies, runs `composer db:up`, clears cached configuration, checks the authenticated MySQL connection, applies normal pending migrations, installs frontend dependencies from the lockfile, and builds assets. The local environment preparation script only accepts local MySQL settings with a non-root account and no `DB_URL`. It copies `.env.example` when needed and fills empty application/database secrets while preserving existing values. The ignored `.env` is never committed.

`composer db:check` reports the authenticated database and actual MySQL version. `composer db:down` preserves the data volume. Changing environment credentials does not rotate accounts in an initialized volume. Never run `migrate:fresh` against runtime data as part of setup or database switching.

The former local database was inspected read-only: all application tables had zero records and only seven migration-history rows existed. The ignored legacy file is retained, disconnected from all active workflows. No application records required transfer.

## Tests and CI

PHPUnit forces the testing environment, MySQL driver, dedicated `solveit_opshub_test` schema, and an empty `DB_URL` in both environment and server variables. This accounts for Laravel's environment-source precedence. Host, port, and credentials come from local or CI environment settings. The test base validates the environment, driver, isolated `_test` database name, and absent URL before `RefreshDatabase` can rebuild tables.

`composer test` clears cached configuration and builds frontend assets before running the complete MySQL suite. Direct PHPUnit/Artisan test invocations use the same MySQL configuration and safety check. There is no fast-test alternate database.

CI provisions a disposable MySQL 8.4 service and runs the full suite once on MySQL after the frontend build, followed by Pint. CI credentials and application keys are disposable runner-only values. Remote validation requires a successful MySQL workflow run for the exact published commit; historical successful runs on the prior configuration do not validate this workflow.

## Migration compatibility and evidence

The foundation migration uses explicit short names for the management-authorization scope index and policy-assignment resource unique constraint. This fixes MySQL error 1059 while preserving the indexed columns, foreign keys, and uniqueness. Current local migration/test evidence is recorded in the requirement ledger; historical evidence stays labeled as historical.

Production hosting, database-scoped deployment credentials, backup/restore validation, and recovery procedures still require separate provisioning evidence. The current MySQL setup does not claim production or live-connector readiness.

References: [MySQL 8.4 manual](https://dev.mysql.com/doc/refman/8.4/en/), [Laravel database configuration](https://laravel.com/framework/docs/13.x/database), [official MySQL container](https://hub.docker.com/_/mysql), [PHPUnit environment configuration](https://docs.phpunit.de/en/12.5/configuration.html).
