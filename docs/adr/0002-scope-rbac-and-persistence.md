# ADR-0002: Organization scope, RBAC, and persistence

**Status:** accepted for M0

## Decision

Every M0 business record is organization-owned or is linked to an organization-owned parent. Authorization is evaluated server-side from active memberships, roles, optional permissions, and organization identity; an organization identifier supplied by a browser is never authority by itself.

The role baseline is `OWNER`, `OPERATOR`, `OPERATIONS`, and `VIEWER`. `OWNER` has organization-wide access. Other roles need an active membership and only receive capabilities explicitly modeled by the authorization service.

## Persistence rules

- Database foreign keys, scoped query helpers, and unique constraints protect organization ownership, active job slots, outbox event versions, and idempotency keys.
- Mutable configuration/state records reserve a `version` column for later optimistic-concurrency flows; M1–M2 implement their public transition endpoints.
- Archive/delete lifecycle behavior and registry UX remain M1, even where M0 has prepared the tables.
- Audit records are inserted by an append-only application service and model updates/deletes are rejected by the ORM.

## Consequences

M0 creates no public client/project feature. It provides the persistence and access boundary those features must use in later milestones.
