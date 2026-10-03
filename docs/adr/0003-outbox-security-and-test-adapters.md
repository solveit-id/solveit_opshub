# ADR-0003: Outbox, security primitives, and fake adapters

**Status:** accepted for M0

## Decision

Business transitions write an `outbox_events` row in the same database transaction. A queued worker processes pending records after commit; M0's dispatcher has no live notification or connector side effect.

Outbound target validation, sensitive-data redaction, idempotency records, and fake adapters are application primitives. Fake public-probe, cPanel, SFTP, and Telegram adapters carry explicit `fake` provenance and are development/test only.

## Consequences

- Redis is a production queue/lock target. MySQL/InnoDB holds business records and the transactional outbox; the database queue remains a local MySQL fallback. All database tests and CI also use MySQL, as described in [ADR-0005](0005-mysql-primary-database.md).
- An outbox event being processed is not a delivered Telegram notification. M2 supplies Telegram delivery state, retries, binding, webhook, and callbacks.
- SSRF protection rejects non-HTTP(S), unapproved ports, localhost, and private/reserved resolved IPs. Private target exceptions require future explicit Owner-approved isolated-worker support.
- Secrets are only references in business data. The M0 redactor is defence-in-depth; it does not replace an external production secrets manager.
