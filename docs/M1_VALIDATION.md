# M1 validation evidence — 2026-10-04

## Decision and scope

M1 observation implementation is implemented and locally tested. The literal milestone exit gate remains **PARTIAL_WITH_BLOCKERS**: the plan requires *all* TC-01–10 to pass, but the complete PRD scenarios include renewal/full backup/account download (TC-03), Telegram delivery history (TC-05), and an independent watchdog (TC-07). Those capabilities belong to M2/M3/M4/M5 and are not implemented or silently waived here. No M2 task was started. IP-M1-10's fake vertical demo is implemented/tested; its full scenario gate remains partial.

Baseline: `main` at `ef8fb15`, clean worktree, upstream `origin/main`. IP-M1-01–03 already existed. Local instructions and actual source/tests were checked before proceeding. Existing product choices, credentials-free public probes, explicit capability gaps, fixed three-fail/two-pass behavior, environment separation, and default-disabled connectors were retained.

## Reproducible automated evidence

- `composer test`: builds TypeScript/Vite before running the complete dedicated MySQL suite. Final result is recorded in `M1_CHECKPOINT.md`; local detailed output is ignored at `output/qa/m1-final-suite.log`.
- `php vendor/bin/pint --test`, `composer validate --strict`, and `git diff --check` pass.
- `MonitoringVerticalDemoTest`: real scheduler, probe job, fenced observation persistence, incident engine, transactional outbox and closure services under explicit fakes. Six samples produce `up → suspect → suspect → down → recovering → up`; one canonical monitor serves two projects; one down and one recovery event remain pending for M2; first failure/recovery timestamps precede confirmations; expired freshness yields unknown.
- `MonitoringConcurrencyTest`: two separate PHP processes race against MySQL for each slot reservation, lease, and third-failure evaluation. One run, one lease holder, one active episode/outbox event, and three incident evidence records survive. The test also executes migration rollback; its InnoDB FK-index failure was repaired rather than bypassed.
- Security regressions cover private/reserved targets, credentials, redirects, DNS rebinding/peer mismatch, bounded body/deadline, content mismatch, organization/project scope, invalid assignee, optimistic version conflict, idempotency and summary secret rejection.
- TLS warning escalation emits one additional critical business event without duplicating the active episode; repeated critical samples do not repeat the event.
- Runtime migration check: 11 migrations applied to the empty local `solveit_opshub` runtime; application records remain empty. Test and runtime databases are separate. No `.env` change was committed.

## Deterministic TC-01–10 matrix

| Scenario | Evidence in M1 | Remaining full-scenario boundary |
|---|---|---|
| TC-01 | Fake credential-free public observation loop; project browser shows backup `not_configured`, internal `unsupported`, partial coverage, explicit fixture provenance. | Native HTTP/TLS/DNS are implemented but live-unverified. No fabricated provider/internal metrics. |
| TC-02 | Registry/environment/policy scope tests, active membership/project denial and cross-organization negatives. | Live secret/provider configuration is not configured; M1 never creates hosting secrets. |
| TC-03 | Shared public asset produces one canonical monitor/run and two impacted-project snapshots; archive/configuration retains incident history. | **Blocked full scenario:** canonical renewal reminder is M2; full account backup/download authorization is M3. Shared public probing alone is not TC-03 completion. |
| TC-04 | Fake-clock third failure creates one incident and pending outbox event; distinct first/confirmed timestamps; real MySQL races prove deduplication. | Pilot detection latency/load measurement is M5; no production latency claim. |
| TC-05 | Two successful samples resolve; close requires summary and recovery; recovery event includes destination down-delivery guard. Browser recovery/closure succeeds. | **Blocked full scenario:** M2 must consume events and enforce actual destination delivery history. A payload guard is not delivery validation. |
| TC-06 | HTTP 200/content mismatch fails; absent expected content is `not_configured` structured evidence shown in browser. | Live target app-content validation remains unverified. |
| TC-07 | Clock tests and elapsed-time browser evidence show stale scheduler/worker and project unknown, retaining last HTTP_OK evidence without fabricated outage. | **Blocked full scenario:** independent failure-domain watchdog and external alert are not configured (M4/M5). |
| TC-08 | TLS expiry 30/7-day/invalid-certificate tests; nullable quota metadata remains nullable, without invented percentage. | Credentialed quota observation/capability UI is M3 and live-unverified. |
| TC-09 | Maintenance keeps observations/downtime, suppresses alert, and alerts on a persistent failing sample after the window. | Production maintenance validation remains unverified. |
| TC-10 | Three episodes/30 minutes set flapping and coalesce one stability event; all observations and transitions remain; scoped timeline exposes history. | Telegram destination coalescing/delivery is M2. |

## Real local browser evidence

Chrome via Playwright CLI used the production frontend build against an isolated Laravel server at `127.0.0.1:8097`, `APP_ENV=testing`, MySQL `solveit_opshub_test`, separate QA session cookie and native probes/connectors disabled. Only fictitious records and fake observations were used. The random disposable login manifest stayed ignored and was deleted after QA.

- Internal login, overview, project, asset, incident and self-health pages render successfully. Browser actions acknowledge, assign to the active scoped Owner, investigate, and close after two fake recovery samples update persisted state/timeline. Closure is disabled while down.
- Assigned operator remains selected after a full reload; first/confirmed failure and recovery times display in Asia/Jakarta; fixture labels and structured evidence are visible. Timeline does not render a stray numeric suppression flag.
- Desktop and 390×844 mobile layouts were inspected visually. Mobile health/project measurements: document width 390 and viewport width 390; one main landmark. Keyboard Tab reaches the named application link. Navigation toggle has an accessible name, expanded state and controlled panel. This is scoped browser evidence, not a complete WCAG audit.
- After the freshness interval elapsed, project HTTP state became unknown/stale while retaining last HTTP_OK fixture evidence; self-health scheduler/worker became stale. Watchdog/storage/notification/secrets manager stay explicitly not configured.
- Authenticated cross-origin POST without a CSRF token returns **419**. No success claim is made for a rejected mutation. Automated tests independently cover permissions/conflicts/idempotency.
- Correct application pages have zero console errors/warnings. One manually mistyped asset URL returned 404; the registered `/organizations/{organization}/assets/{asset}` route then rendered normally. A layout CLI argument initially lost its quotes in PowerShell; running the same measurement from a script file succeeded. Neither diagnostic failure was hidden or treated as an application regression.

Local, ignored screenshots: `output/playwright/m1-incident-closed.png`, `m1-overview-mobile.png`, `m1-project-stale-mobile.png`. Screenshots/snapshots/logs are local QA artifacts, not required tracked runtime assets. The temporary server/browser were stopped; the final test suite resets the dedicated test schema and removes fictitious demo records. Persistent MySQL runtime/container data are preserved.

## Published task commits and next action

| Task | Commit |
|---|---|
| IP-M1-04 | `2b9bfdd` safe bounded HTTP |
| IP-M1-05 | `744fc3c` TLS/DNS observations |
| IP-M1-06 | `895b671` UTC observations/freshness |
| IP-M1-07 | `4e14bc3` incident lifecycle/maintenance |
| IP-M1-08 | `7d8f8ca` scoped operational workflows |
| IP-M1-09 | `6de1155` canonical durable scheduling/self-health |
| IP-M1-10 | Task commit is identified by `(test) verify isolated M1 demo and concurrency with browser fixes`; exact receipt is in `M1_CHECKPOINT.md`. |

Next active item remains **IP-M1-10 exit-gate review**: reconcile the plan's full TC-01–10 requirement with its later-milestone dependencies, without reducing PRD acceptance. Owner-approved sequencing/gate clarification is needed before progressing. After that gate is explicitly resolved and further work is authorized, **IP-M2-01** implements renewal cycle occurrence/projection, depending on the M0 outbox and M1 registry/subscription/policy/events. M2 is not started by this run.
