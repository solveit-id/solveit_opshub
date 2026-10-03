# M0 Requirement Ledger

**Checkpoint:** M0 foundation implementation  
**Source:** `docs/product/PRD.md` v1.0.0; task order in `docs/planning/IMPLEMENTATION_PLAN.md`  
**Last updated:** 4 Oktober 2026

## Verified M0 checkpoint

The following evidence was run in this checkout on 4 Oktober 2026 after recovering the incomplete Composer vendor tree: `composer validate --strict`, `php -r "require 'vendor/autoload.php';"`, `vendor/bin/pint --test`, `php artisan test` (**36 passed, 91 assertions**), `php artisan about`, `php artisan migrate:status`, `php artisan route:list --path=api/v1/foundation`, `php artisan schedule:list`, and `npm.cmd run build` (**27.75 seconds**). The generated `public/build/manifest.json` exists. These results validate local development and fakes only; they are not deployment, credential, MFA-provider, or live-connector validation.

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

## Non-M0 release requirements

All other P0/P1 requirements remain `not_started` with `pending` tests and `not_configured` live validation. Their explicit IDs, planned owner phase, and acceptance evidence remain in the complete matrix at `docs/planning/IMPLEMENTATION_PLAN.md` section 5. They must not be marked complete through M0 schema, fake adapters, or scaffold output.

## M0 exit-gate statement

M0 is `MILESTONE_READY` only after the M0 automated suite, typecheck/build, migration checks, and documented dependency recovery pass in this checkout. This status is not an Internal v1 or production-readiness claim. Production provisioning, secrets manager, Owner MFA enrollment, independent watchdog, and every live connector remain outside M0.
