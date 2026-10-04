# M1 public monitoring runtime

## Local runtime

Run migrations on the configured MySQL runtime (`composer db:check`, `php artisan migrate`), build the frontend, create an internal Owner if not already configured, and record a canonical public URL/domain usage and published monitoring policy assignment. The Owner grants project access or assigns a PIC. Public URLs require no hosting credential; hosting secrets and write connectors remain separately disabled.

`OPSHUB_PUBLIC_PROBES_ENABLED=false` is the development default. Explicitly set it to `true` only for approved public registry targets after the deployment/network review; this does not enable hosting connectors. The normal executor never substitutes a fake probe. Disabled execution records `unknown/PUBLIC_PROBES_DISABLED`, not pass or target downtime.

Run `php artisan schedule:work` and a separate `php artisan queue:work database --queue=probe --timeout=40 --tries=2` worker. A one-shot scheduler tick is `php artisan opshub:monitoring:schedule`. Database queue insertion and the job-slot record share a transaction; production Redis adoption needs its own durable dispatch validation. Restart workers after code/config changes.

Public HTTP uses GET, TLS verification, pinned public IPs, actual-peer validation, at most five individually validated redirects, a total ten-second request deadline and at most 1 MiB body per hop. Expected content is opt-in and is evaluated only in bounded transient memory. Body, raw headers, URLs and raw network errors are not persisted. TLS probes verify the peer name/chain. DNS supports A/AAAA/CNAME/MX/NS; TXT is intentionally unsupported.

## Operational semantics

- UTC storage; IANA policy timezone and Asia/Jakarta display. A target edit changes the canonical monitor configuration; queued old configuration returns unknown instead of observing the wrong target.
- Three eligible HTTP/DNS failures confirm down; two successful samples recover. TLS warning/critical certificate evidence applies immediately. Unknown samples break streaks, and missed jobs only affect freshness/coverage.
- One active incident per canonical monitor. Resolved incidents reopen before closure; a closed incident leads to a linked new episode. Closure requires confirmed recovery and a summary, which may honestly state unknown cause.
- Maintenance suppresses alerts while retaining all observations/downtime. Persistent problems are alerted on the next sample outside the window. Three down/recovery episodes in 30 minutes coalesce to one stability warning in that interval.
- Shared public assets with the same environment kind and effective immutable policy are scheduled once. Different policy/configuration/environment scopes remain separate. Historical usage and incident project-impact snapshots survive archive/configuration changes.
- Expired worker leases fence late results. Failed execution remains unknown; crashed runs retry the same slot with at most three claimed attempts and then enter `failed`. Older queued slots are coalesced rather than replayed as a burst.
- Incident events remain pending for the M2 consumer. A recovery event requires the M2 destination consumer to verify down delivery history before sending; event persistence is not delivery evidence.

## Inspect and recover

Use `/organizations/{organization}/health` for scheduler/last probe-worker heartbeat, queue lag, missed/coalesced slots, and integration configuration boundaries. A stale worker heartbeat means no recent work evidence; it is not proof that the worker process is dead. Storage, notification, secrets manager and independent watchdog are `not_configured` until their later deployment gates pass.

If the queue stalls, inspect worker logs and failed jobs locally without exporting credentials. Resume the scheduler/worker; it coalesces missed slots and reconciles expired runs. Review dead-letter failures and fix configuration/infrastructure before an explicit retry. Never insert synthetic HTTP failures or successes to fill a gap.

Daily aggregation is available through `ObservationRetention`; raw HTTP retention preview is 30 days and daily uptime retention target is 12 months. No raw deletion is automatic in M1. Incident evidence is held. Owner-reviewed destructive retention and routine cleanup/hold reporting are M4 work.

## Validation boundary

M1 automated evidence uses dedicated MySQL `_test` databases and explicitly labelled fake results. Native probes are implemented, not live-validated against a client/provider. Pilot performance, production queue infrastructure, independent watchdog, hosting connectors, backups and Telegram delivery remain unverified/configuration work for their approved milestones.

## Isolated vertical demo

Use an already provisioned disposable MySQL test schema only. In PowerShell set process-local `APP_ENV=testing`, `DB_DATABASE=solveit_opshub_test`, and empty `DB_URL`, verify with `php artisan opshub:database:check`, then run `php artisan opshub:monitoring:demo`. The command refuses a non-testing environment, a non-MySQL connection, a schema without `_test`, or a connection URL before writing. It does not reset the database. All observations are explicitly fake and native execution stays disabled.

`--browser` adds a linked open episode and writes a random disposable login to ignored `storage/app/qa-m1-login.json`. Run a separate test-only HTTP server/session cookie, build assets, and use that fixture to exercise operator actions. `--recover={organization_id}` accepts only fictitious fake-only demo records in that same isolated test environment; it runs two fake successes through the real scheduler/job services. Never use either helper for runtime records or substitute synthetic data into a production coverage gap.

After QA stop the temporary browser/server, delete that exact login manifest, and reset the disposable schema through the project's guarded test suite. Preserve persistent runtime data. Detailed M1 scenario evidence and unresolved cross-milestone exit assertions are in [M1_VALIDATION.md](M1_VALIDATION.md).
