# ADR-0001: Foundation stack and runtime

**Status:** accepted for M0 development

## Decision

Use Laravel `13.34.0` with PHP `8.5.7`, Inertia Laravel `2.0.28`, React `18.2.0`, TypeScript `5.0.2`, Vite `7.0.7`, and Laravel Breeze `2.4.2`.

Production remains planned for PostgreSQL, Redis, private S3-compatible storage, and a secrets manager. M0 uses SQLite/database queue/array mail only for local and test execution. That fallback is not a production architecture claim.

## Rationale

Laravel 13 supports PHP 8.3–8.5. The selected dependencies are present in `composer.lock` and `package-lock.json`; they boot on the local PHP 8.5.7 runtime. Inertia React keeps the dashboard same-origin with Laravel session/CSRF authentication.

## Guardrails

- `LIVE_CONNECTORS_ENABLED=false` by default. No real Telegram, cPanel, SFTP, storage, or secrets-manager integration is configured in M0.
- Browser authentication is session based. Public registration is removed; the first Owner is created only by the one-time CLI command.
- Owner MFA is enforced by configuration in production, but a production MFA provider/enrollment flow is not configured in M0 and remains a live-deployment blocker.
- No channel other than Telegram is represented as an integration.

## Consequences

The exact production database/Redis/object-storage/secrets-manager provider remains unselected. A provisioning ADR must confirm compatibility, private networking, backup/DR, key recovery, and least-privilege credentials before pilot deployment.
