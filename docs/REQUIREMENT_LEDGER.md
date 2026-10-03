# M0 Requirement Ledger

**Checkpoint:** M1 registry implementation in progress
**Source:** `docs/product/PRD.md` v1.0.0; task order in `docs/planning/IMPLEMENTATION_PLAN.md`  
**Last updated:** 4 Oktober 2026

## Verified M0 checkpoint

The following evidence was run in this checkout on 4 Oktober 2026 after recovering the incomplete Composer vendor tree: `composer validate --strict`, `php -r "require 'vendor/autoload.php';"`, `vendor/bin/pint --test`, `php artisan test` (**36 passed, 91 assertions**), `php artisan about`, `php artisan migrate:status`, `php artisan route:list --path=api/v1/foundation`, `php artisan schedule:list`, and `npm.cmd run build` (**27.75 seconds**). The generated `public/build/manifest.json` exists. These results validate local development and fakes only; they are not deployment, credential, MFA-provider, or live-connector validation.

**CI repair (4 Oktober 2026):** GitHub Actions originally failed before application tests because Vite 7 required `@types/node` >=20.19 or >=22.12 while the lockfile resolved Node 18 types. The dependency and lockfile now resolve `@types/node` 22.12.0. Its next run passed `npm ci` but exposed that an ignored local `.env` was supplying the test encryption key: CI had no `APP_KEY`, so Laravel threw `MissingAppKeyException`. `phpunit.xml` now supplies a fixed non-production key for `APP_ENV=testing` only; it is not a deployment credential. With the process `APP_KEY` explicitly empty, local `php artisan test` passes (**48 passed, 176 assertions**), as do Pint, `npm.cmd run build`, and `git diff --check`. The remote rerun remains `live_unverified` until this pushed repair completes.

## Status legend

| Field | Values | Meaning |
|---|---|---|
| Implementation | `implemented`, `partial`, `not_started`, `unsupported_on_target` | Code behavior, not a UI claim. |
| Test | `passing`, `pending`, `not_applicable` | Automated evidence in this checkout. |
| Live validation | `not_required_m0`, `not_configured`, `sandbox_pending`, `live_unverified` | External integration/deployment evidence. |

## M0 task checkpoint

| Task | Status | Evidence / remaining boundary |
|---|---|---|
| IP-M0-01 | implemented | ADR-0001 through ADR-0004 and this ledger. |
| IP-M0-02 | implemented | Laravel/Inertia/React scaffold, `composer.lock`, `package-lock.json`, secure defaults. |
| IP-M0-03 | implemented | Internal login only, membership/role authorization, organization middleware, bootstrap command, MFA/step-up boundary. Live MFA provider remains not configured. |
| IP-M0-04 | implemented | M0 organization-scoped schema, constraints, and models. M1 registry behaviors remain not started. |
| IP-M0-05 | implemented | Versioned persistence baseline, audit writer, evidence and typed errors. |
| IP-M0-06 | implemented | Job-slot reservation, database-backed queue records, transactional outbox, event ordering/idempotency primitives. M1 monitor coalescing remains not started. |
| IP-M0-07 | implemented | Redactor, SSRF target guard, session/CSRF scaffold, safe config, live connector gate. Telegram webhook work is M2. |
| IP-M0-08 | implemented | Deterministic fake adapters with explicit fake provenance; no production fallback. |
| IP-M0-09 | implemented | Unit/feature test suite, CI workflow, M0 status reporting convention. |

## M1 task checkpoint

M1 is not yet at its exit gate. The following status is limited to the completed first dependency task and does not claim that public monitoring, monitoring policies, incidents, or the M1 vertical demo exist.

| Task | Status | Evidence / remaining boundary |
|---|---|---|
| IP-M1-01 | implemented | Scoped registry API and Inertia registry/project pages cover client/contact, project, separate environments, canonical assets, and shared usages. Mutations require `registry.manage`, are audited, reject recognizable secret-bearing values, and keep archive history. `RegistryApiTest` verifies the graph, RBAC, secret rejection, archive queue reconciliation, and rendering (5 tests, 36 assertions). |
| IP-M1-02 | implemented | Scoped hosting account, service subscription, and management-authorization APIs record metadata, source/evidence references, precision semantics, and expiring action scope. No connector, credential value, remote action, job, or outbox event is created. `RegistryMetadataTest` verifies these boundaries (4 tests, 26 assertions). |
| IP-M1-03 | implemented | Owner-only monitoring policy drafts publish immutable versions. Scoped project assignments record constrained overrides, and an effective-policy preview reports timezone, disabled checks, coverage gaps, and `not_configured` honestly. `MonitoringPolicyTest` verifies version isolation, override limits, coverage, and Owner gate (3 tests, 25 assertions). |
| IP-M1-04 to IP-M1-10 | not_started | Probes, observations, incidents, operations UI, self-health, and deterministic vertical demo remain outside this checkpoint. |

## M1 requirement evidence (IP-M1-01 scope)

| Requirements | Implementation | Test | Live validation | Evidence |
|---|---|---|---|---|
| REG-01 | implemented | passing | live_unverified | Client/contact CRUD is organization-scoped; contacts remain business contacts rather than application users. |
| REG-02 | implemented | passing | live_unverified | Project CRUD validates client scope, unique organization code, stack tags, internal PIC membership, lifecycle, criticality, and notes. |
| REG-03 | implemented | passing | live_unverified | Production/staging/development/custom kinds are explicit; a project cannot duplicate a standard environment and asset usage validates the matching project environment. |
| REG-04 to REG-06 | implemented | passing | live_unverified | Canonical asset kinds, responsibility, owner membership, source, verification timestamp, notes, and shared usage relation exist. No secret value is accepted in notes, identity, or source. |
| REG-10 | partial | passing | not_required_m0 | Paused/archived project transitions cancel only future queued project/environment work and preserve history; leased/running work is left for reconciliation. Full monitor scheduling and shared-resource run semantics depend on IP-M1-03 and later. |
| REG-07 | implemented | passing | live_unverified | Hosting metadata requires a canonical hosting asset and records provider, panel, separate HTTPS API endpoint, account identifier, quota, access declarations, and per-environment roots. It does not connect to the provider. |
| REG-08 | partial | passing | live_unverified | Subscription metadata stores billing/paying/action parties, source/evidence reference, reminder policy, billing due, and mutually exclusive instant/date/unknown expiry precision. Renewal cycle/follow-up/reminder execution remains M2. |
| REG-09 | partial | passing | live_unverified | Project/hosting scope records allowed action classes, authorizer, evidence reference, and expiry. The authorization service refuses expired or absent classes; future connector/backup write paths must consume it in M3. |
| CON-08 | partial | passing | not_configured | Metadata may declare `manual_only`/unknown access without offering a connector action. Provider discovery and public-monitoring fallback arrive in M1-04/M3. |
| POL-01 | implemented | passing | not_required_m0 | Draft configuration is separate from immutable published `PolicyVersion`; active project assignments retain their published version when the draft changes. |
| POL-03 | implemented | passing | not_required_m0 | Project override may only disable an existing check or lengthen its interval; it cannot add checks, make a faster schedule, or violate the M1 fixed failure/recovery and TLS thresholds. Effective preview includes applied overrides, IANA timezone, coverage, and disabled/not-configured states. |
| SEC-02, SEC-04, SEC-11 | implemented | passing | not_required_m0 | Active organization membership and `registry.manage` gate every mutation; audit records are redacted and input rejects recognizable secret-bearing values. |

## M0 requirement evidence

| Requirements | Implementation | Test | Live validation | Evidence |
|---|---|---|---|---|
| DEC-01, DEC-05, DEC-13, DEC-14 | implemented | passing | not_required_m0 | Registration removed; one organization scope; no non-Telegram adapter, shell, or production-change feature. |
| DEC-02, DEC-03, DEC-04 | partial | passing | not_configured | Only fake Telegram transport exists. Alert/template/client-send workflow is M2. |
| DEC-06–DEC-10 | partial | passing | not_required_m0 | Typed fake outcomes/outbox/persistence boundaries exist; observations, backup, and controlled operations are M1–M3. |
| DEC-11, DEC-12 | partial | passing | not_configured | Secret references/redaction/default-disabled connectors exist; real secrets manager/runtime/storage are deployment work. |
| SEC-01 | partial | passing | not_configured | Internal session auth, inactive-user guard, bootstrap command. Production Owner MFA provider/enrollment pending. |
| SEC-02 | implemented | passing | not_required_m0 | Organization scope service, active membership, role gate, middleware/API negative tests. |
| SEC-04 | implemented | passing | not_required_m0 | Sensitive data redactor and audit/outbox sanitization tests. |
| SEC-05 | implemented | passing | not_required_m0 | HTTP(S)/port/localhost/private/reserved IP guard tests. |
| SEC-09 | not_started | pending | not_configured | Telegram webhook receipt/callback checks are M2. |
| SEC-11 | partial | passing | not_required_m0 | Server-side redaction and regular React escaping baseline; template/CSV flows are M2/M4. |
| SEC-16 | implemented | passing | not_required_m0 | Live connectors default false; no credentials committed; fake adapters only. |
| SEC-17 | implemented | passing | not_configured | One-time Owner bootstrap command; deployment secret channel remains unconfigured. |
| JOB-01, JOB-02, JOB-04, JOB-09 | implemented | passing | not_required_m0 | Unique job-slot service, queued dispatcher, transactional outbox, aggregate-version uniqueness. |
| JOB-06 | partial | passing | not_required_m0 | Generic queue record exists; monitor missed-slot coalescing is M1. |
| POL-01, POL-03 | partial | passing | not_required_m0 | Versioned policy/assignment schema and validation baseline only; effective policy/activation is M1. |
| REG-02, REG-03, REG-05, REG-06, REG-10 | partial | passing | not_required_m0 | Canonical tables/foreign keys/version columns only; CRUD/lifecycle is M1. |
| UX-01, UX-03 | not_started | pending | not_required_m0 | M1 dashboard states. |
| NFR-06, NFR-09 | partial | passing | not_required_m0 | Durable transaction/queue and security negative tests; crash/load evidence is later. |
| NFR-12 | partial | passing | not_required_m0 | Correlation IDs/audit/outbox fields exist; metrics dashboards are M4. |

## Non-M0/M1-01 release requirements

All other P0/P1 requirements remain `not_started` with `pending` tests and `not_configured` live validation. Their explicit IDs, planned owner phase, and acceptance evidence remain in the complete matrix at `docs/planning/IMPLEMENTATION_PLAN.md` section 5. They must not be marked complete through foundation schema, registry CRUD, fake adapters, or scaffold output.

## M0 exit-gate statement

M0 is `MILESTONE_READY` only after the M0 automated suite, typecheck/build, migration checks, and documented dependency recovery pass in this checkout. This status is not an Internal v1 or production-readiness claim. Production provisioning, secrets manager, Owner MFA enrollment, independent watchdog, and every live connector remain outside M0.
