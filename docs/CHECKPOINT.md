# Solveit OpsHub — Checkpoint dan Panduan Operasi

**Diperbarui:** 4 Oktober 2026

**Status milestone terakhir:** M1 — `MILESTONE_READY` dalam scope engineering lokal yang disetujui Owner; Internal v1 belum ready.

**Task terakhir:** `IP-M1-10` — selesai dalam scope M1; tidak ada task aktif sesudah penutupan. M2 belum dimulai.

**Keputusan terbaru:** [persetujuan Owner dan penutupan M1](#14-keputusan-owner-dan-penutupan-m1--4-oktober-2026); full TC lintas milestone tetap wajib pada M2–M5.

**Sumber keputusan produk:** [PRD](PRD.md)

**Urutan task dan acceptance criteria:** [Implementation Plan](IMPLEMENTATION_PLAN.md)

Dokumen ini menjadi satu tempat untuk progres aktual, pemetaan requirement, hasil validasi, commit/push, blocker, next step, dan runbook monitoring. Hasil historis tetap disimpan dengan konteksnya: bagian 1.3 merekam suite ulang sebelum persetujuan; bagian 1.4 mencatat keputusan terbaru dan validasi dokumentasi tanpa mengarang pengujian aplikasi baru.

## Daftar isi

1. [Ringkasan progres dan keputusan berikutnya](#1-ringkasan-progres-dan-keputusan-berikutnya)
2. [Arti status dan standar bukti](#2-arti-status-dan-standar-bukti)
3. [Progres task M0 dan M1](#3-progres-task-m0-dan-m1)
4. [Requirement dan batas implementasi](#4-requirement-dan-batas-implementasi)
5. [Bukti validasi M1](#5-bukti-validasi-m1)
6. [Runbook monitoring lokal](#6-runbook-monitoring-lokal)
7. [Riwayat validasi fondasi M0](#7-riwayat-validasi-fondasi-m0)
8. [Riwayat commit dan push](#8-riwayat-commit-dan-push)
9. [Aturan pembaruan checkpoint](#9-aturan-pembaruan-checkpoint)

## 1. Ringkasan progres dan keputusan berikutnya

| Milestone | Progres aktual | Status gate | Langkah tersisa |
|---|---|---|---|
| M0 — Foundation | IP-M0-01–09 implemented; fondasi dan CI sudah diuji. | `MILESTONE_READY` untuk fondasi lokal; bukan kesiapan production. | Provisioning/MFA/integrasi live tetap mengikuti phase berikutnya. |
| M1 — Registry dan observation | IP-M1-01–10 implemented/tested; sequencing disetujui Owner. | `MILESTONE_READY` untuk engineering lokal sesuai ADR-0006; full TC tetap unfinished. | Tidak ada pekerjaan M1 tersisa; validasi live dan full scenario mengikuti milestone pemilik. |
| M2 — Telegram/renewal/client loop | Belum dimulai. | Belum dievaluasi. | `IP-M2-01` setelah instruksi milestone berikutnya; sequencing M1 sudah terselesaikan. |
| M3 — Connector dan verified backup | Belum dimulai. | Belum dievaluasi. | Dependency canonical account, authorization, notification dan sandbox. |
| M4 — Audit/resilience | Belum dimulai. | Belum dievaluasi. | Dependency M1–M3 dan validasi operasional. |
| M5 — Internal pilot | Belum dimulai. | Belum dievaluasi. | Gate M0–M4, otorisasi target dan provisioning. |

### 1.1 Checkpoint implementasi M1 terdahulu

- Active task: **IP-M1-10**, fake demo/concurrency/browser implementation tested; complete milestone exit gate **PARTIAL_WITH_BLOCKERS**.
- Last result: HTTP/TLS/DNS observations, fenced UTC persistence, incidents/maintenance/stability, scoped workflows, canonical scheduler/durable queue and local self-health implemented. Demo proves one shared monitor, six fake samples, one down/recovery episode and closure without hosting credentials. Browser lifecycle and elapsed stale/unknown state verified.
- Tests: complete `composer test` **70 passed / 386 assertions**, 73.31s suite, TypeScript/Vite build 32.91s. Subsequent new TLS escalation regression plus its incident suite: **5 passed / 30 assertions**. Final malformed redirect/SSRF suite: **3 passed / 27 assertions**. Pint, strict Composer validation, diff check, schedule list and runtime migrations passed. Tests added after the complete run were checked separately; no second complete-suite result is invented.
- Browser: real local Chrome, desktop and 390×844 mobile, scoped Owner login/ack/assign/investigate/recovery/close, selected assignee retained on reload, one main landmark, no horizontal overflow on measured health/project pages, correct application pages zero console errors; cross-origin POST without CSRF rejected **419**. See section 5 for evidence and diagnostic corrections.
- Cleanup: temporary browser/server stopped; ignored random QA login manifest deleted. Read-only verification found **11 migrations and zero users/projects/observations/incidents/jobs** in both runtime `solveit_opshub` and dedicated `solveit_opshub_test`. Persistent MySQL service/data retained. `.env`, installed dependencies, build, QA artifacts and secrets excluded from Git.
- Commits/push: `main` → `origin/main`; IP-M1-04 `2b9bfdd`, 05 `744fc3c`, 06 `895b671`, 07 `4e14bc3`, 08 `7d8f8ca`, 09 `6de1155` pushed normally. IP-M1-10 `2ad9b1b15be8b6b76fdf772d72aab6b25cecdee0` (`(test) verify isolated M1 demo and concurrency with browser fixes`) pushed normally and matched remote `refs/heads/main`. Documentation receipt `989cf17` was committed and pushed separately; branch state is checked again before each new task.
- Remaining/blocker: literal plan exit says all TC-01–10 pass, but full TC-03 requires M2 renewal/M3 backup/download, TC-05 requires M2 actual down-delivery history, and TC-07 requires independent M4/M5 watchdog. These are unimplemented, not waived. TC-08 resource probing and TC-10 destination delivery also retain later-phase boundaries. No M2 task started.
- Live limits: native client/provider probes, credentialed metrics, Telegram delivery, production Redis, secrets/storage/MFA deployment and independent watchdog are **live-unverified/not configured**. DNS TXT unsupported. Local fake/browser evidence is not Internal v1 or production readiness. Remote CI for implementation commit `2ad9b1b15be8b6b76fdf772d72aab6b25cecdee0` completed **success**: [run 37166571900](https://github.com/solveit-id/solveit_opshub/actions/runs/37166571900). The later documentation receipt changes no runtime/test source.
- Next step: **IP-M1-10 exit-gate review** — resolve cross-milestone TC mapping/sequence with the Owner while preserving full acceptance criteria. Dependencies: decisions for full TC-03/05/07 later-phase evidence; blocker: the current gate cannot be satisfied inside M1 scope. After explicit gate resolution and further instruction, **IP-M2-01** models subscription expiry precision, renewal cycle and follow-up state; depends on M0 transactional foundation and M1 registry/subscription/policy/event facts.

### 1.2 Blocker exit gate sebelum persetujuan Owner (historis)

| Blocker | Bukti yang sudah tersedia | Dependency yang belum tersedia | Keputusan berikutnya |
|---|---|---|---|
| TC-03 lengkap | Canonical public monitor dan impacted-project snapshots. | Renewal reminder M2; account backup/download M3. | Owner menyelaraskan urutan bukti lintas milestone tanpa menurunkan acceptance. |
| TC-05 lengkap | Recovery/closure dan pending event dengan delivery guard. | Histori down delivery serta consumer Telegram M2. | Validasi destination guard pada implementasi M2 yang diotorisasi. |
| TC-07 lengkap | Scheduler/worker stale dan project unknown. | Independent watchdog pada failure domain terpisah M4/M5. | Tentukan sequencing desain dan provisioning watchdog. |

Task berikutnya yang masih aktif adalah **IP-M1-10 — review exit gate**. Tidak ada acceptance waiver. Setelah gate diselesaikan secara eksplisit dan scope lanjutan diotorisasi, kandidat task berikutnya **IP-M2-01**: expiry precision, renewal cycle, dan follow-up state; dependency M0 transactional foundation serta M1 registry/subscription/policy/event facts. Penataan dokumen ini tidak mengubah keputusan tersebut.

### 1.3 Review lanjutan IP-M1-10 — 4 Oktober 2026

Review berikut merekam kondisi **sebelum** persetujuan Owner. Status/stop/next step pada saat itu dipertahankan sebagai evidence historis; keputusan terbaru ada di bagian 1.4.

- **Baseline:** worktree bersih, `main`/`origin/main` sama pada `a0d0d3775df228b1241fac093b5beb6fedb5fee0`; instruksi root, PRD, plan, matriks TC dan source diperiksa. M1 adalah milestone pertama yang belum selesai. Tidak ada perubahan runtime atau task milestone baru.
- **Hasil review:** IP-M1-01–09 dan demo IP-M1-10 tetap implemented/tested. [MonitoringVerticalDemoTest](../tests/Feature/MonitoringVerticalDemoTest.php) membuktikan enam sample fake, shared monitor, recovery/closure, stale/unknown dan incident event tetap pending. [IncidentEngine](../app/Application/Monitoring/IncidentEngine.php) menyimpan delivery guard; [RuntimeHealth](../app/Application/Monitoring/RuntimeHealth.php) masih menyatakan notification/watchdog `not_configured`. Metadata subscription serta fake cPanel tidak menyediakan canonical renewal reminder, verified account backup atau scope download. Tidak ditemukan task M1 independen yang belum selesai.
- **Pengujian aktual pada baseline tersebut:** `composer test` lulus **71 tests / 392 assertions**, suite **97.97s**, TypeScript/Vite build **47.58s**; mencakup demo, race MySQL, SSRF/redirect/peer, TLS escalation, scope, version/idempotency, maintenance dan freshness. PHP **8.5.7**, Node **22.23.1**, MySQL **8.4.11**. `composer db:check`, 11 migration berstatus `Ran`, `php artisan schedule:list`, Pint dan `composer validate --strict` lulus. Guard PHPUnit/TestCase diperiksa sebelum suite: MySQL `solveit_opshub_test`, `APP_ENV=testing`, tanpa `DB_URL`; persistent runtime/volume tidak di-reset.
- **Status/stop:** `PARTIAL_WITH_BLOCKERS`; full TC-03 (M2 renewal/M3 backup/download), TC-05 (M2 histori delivery destination), TC-07 (M4/M5 independent watchdog) belum terpenuhi. TC-08 credentialed quota dan TC-10 destination delivery tetap memiliki batas phase berikutnya. Tidak ada waiver, perubahan literal exit gate, atau task M2 yang dimulai. Semua pekerjaan yang dapat dilanjutkan dalam M1 sudah teruji; instruksi berhenti ketika seluruh pekerjaan tersisa terblokir diterapkan.
- **Batas validasi:** browser tidak diulang pada review ini; bukti browser bagian 5.4 tetap historis. Native public probes, provider connectors, Telegram, production Redis/storage/secrets/MFA dan watchdog tidak divalidasi live. Default live gates tetap false; tidak ada secret/config live yang diubah. Hasil suite lokal tidak membuktikan full TC-01–10, remote CI baru, atau kesiapan production.
- **Integritas/cleanup:** audit dokumentasi lulus 384 pemeriksaan total, termasuk 212 pemeriksaan integritas isi; 52 task dan 6 exit gate literal tetap utuh, 10 dokumen Markdown serta 86 tautan relatif/anchor valid. `git diff --check` lulus; manifest `storage/app/qa-m1-login.json` tidak tersisa setelah suite. Source, lockfiles, `.env`, dependency dan artifact QA/build tidak termasuk perubahan commit.
- **Commit/push terverifikasi:** review `fe1e0927793b2d4189421dd4bbe68455af1230fa` (`(docs) verify M1 exit gate and record current blockers`) hanya mengubah `docs/CHECKPOINT.md` dan `docs/IMPLEMENTATION_PLAN.md`; push biasa `main` → `origin/main` berhasil dan remote `refs/heads/main` cocok. Receipt ini dicatat pada commit dokumentasi berikutnya; hasil CI baru belum diperiksa. Tidak ada task implementasi baru yang ditandai selesai.
- **Next step konkret:** tetap **IP-M1-10** — Owner menetapkan sequencing bukti full TC-03/05/07 agar prerequisite M1 tidak melingkar dengan M2/M3/M4/M5, sambil mempertahankan acceptance produk. Setelah keputusan itu dicatat dan instruksi milestone berikutnya diberikan, **IP-M2-01** membangun expiry precision, renewal cycle dan follow-up state; dependency M0 auth/outbox/security serta M1 registry/subscription/policy/incident events. Blocker saat ini adalah urutan gate, bukan token live yang hilang.

### 1.4 Keputusan Owner dan penutupan M1 — 4 Oktober 2026

**Baseline:** worktree bersih, `main`/`origin/main` pada `a2868436d269f69f2f0532144c320ec60ade3c78`. Owner menjawab setuju atas usulan gate M1 sesuai scope observasi, assertion full TC tetap pada milestone pemilik, dan stop sebelum M2. [ADR-0006](adr/0006-milestone-acceptance-sequencing.md), PRD v1.0.1, plan dan snapshot AGENTS diselaraskan secara eksplisit; tidak ada waiver acceptance produk atau perubahan runtime/live config.

**Hasil:** IP-M1-10 dan M1 **`MILESTONE_READY`** untuk engineering lokal. Full TC-03/05/07, bagian credentialed quota TC-08 dan destination delivery TC-10 tetap unfinished, bukan pass. Kewajiban reminder/delivery dicatat pada gate M2, account backup/download/quota pada M3, watchdog contract pada M4 dan failure-domain alert pada M5. Gate Internal v1 tetap TC-01–44 lengkap plus live/sandbox evidence yang sesuai. Tidak ada task M2 dimulai.

| Gate/acceptance M1 yang dinilai | Evidence yang mendukung |
|---|---|
| Registry/environment/scope dan policy/data | RegistryApiTest, RegistryMetadataTest, MonitoringPolicyTest, MonitoringWorkflowTest; histori browser bagian 5.4. |
| Public observation tanpa hosting credential; gap internal/backup jujur | MonitoringVerticalDemoTest, MonitoringHealth, explicit fake provenance dan UI gap bagian 5.3/5.4. |
| SSRF/redirect/peer, TLS/DNS, expected-content dan sanitized evidence | HttpProbeTest, TlsDnsProbeTest, FoundationReliabilityTest; suite lengkap mencakup regresi malformed redirect/TLS escalation. |
| Incident/dedup/maintenance/flapping/recovery/summary | IncidentEngineTest, MonitoringConcurrencyTest dan MonitoringWorkflowTest; browser lifecycle bagian 5.4. |
| Shared monitor/run, lease fencing, stale/unknown dan demo | MonitoringSchedulerTest, ObservationTest dan MonitoringVerticalDemoTest; enam sample fake serta dua proses race MySQL bagian 5.2. |

**Pengujian/evidence:** hasil lokal terakhir tetap **71 tests / 392 assertions**, suite 97.97s dan build 47.58s pada source baseline `a0d0d3775df228b1241fac093b5beb6fedb5fee0` (bagian 1.3). Diff Git memverifikasi app/bootstrap/config/database/resources/routes/scripts/tests, lockfiles, PHPUnit dan CI identik sejak baseline tersebut; hanya dokumentasi berubah. Hasil browser dan CI implementasi `2ad9b1b` tetap historis. Suite aplikasi/build/browser tidak diulang untuk perubahan dokumen; native probes, provider, Telegram, Redis, storage/secrets/MFA dan watchdog tetap live-unverified/not configured; TXT unsupported.

**Validasi perubahan:** audit dokumentasi lulus **340 pemeriksaan**: 52 task beserta judul/mapping/output tetap utuh, 46 full scenario PRD dan enam blok acceptance milestone identik, gate M0/M5 literal tidak berubah; hanya gate engineering M1–M4 diamendemen secara eksplisit untuk alokasi evidence. Sebelas dokumen Markdown dan 94 tautan relatif/anchor valid; tidak ada path usang/conflict marker/encoding rusak. Diff source sejak baseline suite kosong; scope perubahan tepat lima path dokumentasi. `git diff --check` lulus. Secret, `.env`, dependencies/build/QA artifacts dikecualikan.

**Commit/push:** perubahan penutupan M1 akan di-commit dan di-push biasa `main` → `origin/main` setelah scope staging diverifikasi; receipt hash dicatat setelah remote cocok. Commit review terdahulu `fe1e092` dan receipt `a286843` tetap evidence historis.

**Next step:** **IP-M2-01** — model expiry precision/source timezone, renewal cycle dan follow-up state; dependency M0 auth/outbox/security dan M1 canonical registry/subscription/policy/incident events sudah tersedia. Tidak ada blocker sequencing M1. Pengembangan M2 menunggu instruksi milestone berikutnya sesuai stop boundary yang disetujui; missing bot/config live tidak menghalangi domain/fake work setelah diotorisasi. Semua acceptance full TC dan batas live tetap wajib.

## 2. Arti status dan standar bukti

| Field | Values | Meaning |
|---|---|---|
| Implementation | `implemented`, `partial`, `not_started`, `unsupported_on_target` | Code behavior, not a UI claim. |
| Test | `passing`, `pending`, `not_applicable` | Automated evidence in this checkout. |
| Live validation | `not_required_m0`, `not_configured`, `sandbox_pending`, `live_unverified` | External integration/deployment evidence. |

| Status gate | Makna |
|---|---|
| `MILESTONE_READY` | Acceptance dan exit gate milestone terbukti sesuai scope; bukan release production. |
| `PARTIAL_WITH_BLOCKERS` | Ada implementasi teruji, tetapi gate belum lengkap; blocker wajib disebutkan. |
| `READY_FOR_INTERNAL_PILOT` | Prasyarat pilot dan otorisasi target terbukti. |
| `INTERNAL_V1_READY` | Seluruh Definition of Done dan gate release PRD terpenuhi dengan bukti yang sesuai. |

`implemented`, hasil test, dan validasi live merupakan tiga hal terpisah. Fake observation, halaman UI, scaffold, API acceptance, dan pending outbox event tidak membuktikan kesehatan target atau pengiriman aktual. `unsupported` tidak boleh berubah menjadi `pass`; stale/missing data tidak boleh tampil sehat.

## 3. Progres task M0 dan M1

### 3.1 M0 — ringkasan task

| Task | Tujuan | Implementasi | Pengujian / gate |
|---|---|---|---|
| `IP-M0-01` | ADR dan pemetaan requirement | `implemented` | Passing dalam scope lokal; lihat bukti dan batas live. |
| `IP-M0-02` | Fondasi Laravel/Inertia dan setup | `implemented` | Passing dalam scope lokal; lihat bukti dan batas live. |
| `IP-M0-03` | Identity, membership, RBAC dan batas MFA | `implemented` | Passing dalam scope lokal; lihat bukti dan batas live. |
| `IP-M0-04` | Schema canonical dan organization scope | `implemented` | Passing dalam scope lokal; lihat bukti dan batas live. |
| `IP-M0-05` | Transition, audit dan evidence | `implemented` | Passing dalam scope lokal; lihat bukti dan batas live. |
| `IP-M0-06` | Job slot, queue dan transactional outbox | `implemented` | Passing dalam scope lokal; lihat bukti dan batas live. |
| `IP-M0-07` | Redaction, SSRF, session dan CSRF | `implemented` | Passing dalam scope lokal; lihat bukti dan batas live. |
| `IP-M0-08` | Fake adapter dan fixture | `implemented` | Passing dalam scope lokal; lihat bukti dan batas live. |
| `IP-M0-09` | Isolasi database, CI dan quality gate | `implemented` | Passing dalam scope lokal; lihat bukti dan batas live. |

#### Detail hasil dan batas validasi

##### IP-M0-01 — ADR dan pemetaan requirement

**Implementasi:** `implemented`.

ADR-0001 through ADR-0004 and this ledger.

##### IP-M0-02 — Fondasi Laravel/Inertia dan setup

**Implementasi:** `implemented`.

Laravel/Inertia/React scaffold, `composer.lock`, `package-lock.json`, secure defaults.

##### IP-M0-03 — Identity, membership, RBAC dan batas MFA

**Implementasi:** `implemented`.

Internal login only, membership/role authorization, organization middleware, bootstrap command, MFA/step-up boundary. Live MFA provider remains not configured.

##### IP-M0-04 — Schema canonical dan organization scope

**Implementasi:** `implemented`.

M0 organization-scoped schema, constraints, and models. M1 registry behaviors are now delivered; see IP-M1-01–02.

##### IP-M0-05 — Transition, audit dan evidence

**Implementasi:** `implemented`.

Versioned persistence baseline, audit writer, evidence and typed errors.

##### IP-M0-06 — Job slot, queue dan transactional outbox

**Implementasi:** `implemented`.

Job-slot reservation, database-backed queue records, transactional outbox, event ordering/idempotency primitives. M1 monitor coalescing is now delivered in IP-M1-09.

##### IP-M0-07 — Redaction, SSRF, session dan CSRF

**Implementasi:** `implemented`.

Redactor, SSRF target guard, session/CSRF scaffold, safe config, live connector gate. Telegram webhook work is M2.

##### IP-M0-08 — Fake adapter dan fixture

**Implementasi:** `implemented`.

Deterministic fake adapters with explicit fake provenance; no production fallback.

##### IP-M0-09 — Isolasi database, CI dan quality gate

**Implementasi:** `implemented`.

Unit/feature test suite, CI workflow, M0 status reporting convention.

### 3.2 M1 — ringkasan task

| Task | Tujuan | Implementasi | Pengujian / gate |
|---|---|---|---|
| `IP-M1-01` | Registry client/project/environment/asset | `implemented` | Passing dalam scope lokal; lihat bukti dan batas live. |
| `IP-M1-02` | Metadata hosting, subscription dan authorization | `implemented` | Passing dalam scope lokal; lihat bukti dan batas live. |
| `IP-M1-03` | Versioned monitoring policy | `implemented` | Passing dalam scope lokal; lihat bukti dan batas live. |
| `IP-M1-04` | HTTP probe dengan SSRF guard | `implemented` | Passing dalam scope lokal; lihat bukti dan batas live. |
| `IP-M1-05` | TLS dan DNS probe | `implemented` | Passing dalam scope lokal; lihat bukti dan batas live. |
| `IP-M1-06` | Observasi UTC, freshness dan health | `implemented` | Passing dalam scope lokal; lihat bukti dan batas live. |
| `IP-M1-07` | Incident, maintenance dan flapping | `implemented` | Passing dalam scope lokal; lihat bukti dan batas live. |
| `IP-M1-08` | Overview dan workflow operator | `implemented` | Passing dalam scope lokal; lihat bukti dan batas live. |
| `IP-M1-09` | Scheduler, durable queue dan self-health | `implemented` | Passing dalam scope lokal; lihat bukti dan batas live. |
| `IP-M1-10` | Demo, concurrency dan evaluasi exit gate | `implemented` | Passing untuk gate M1 sesuai persetujuan Owner/ADR-0006; full TC lintas milestone unfinished. |

#### Detail hasil dan batas validasi

##### IP-M1-01 — Registry client/project/environment/asset

**Implementasi:** `implemented`.

Scoped registry API and Inertia registry/project pages cover client/contact, project, separate environments, canonical assets, and shared usages. Mutations require `registry.manage`, are audited, reject recognizable secret-bearing values, and keep archive history. `RegistryApiTest` verifies the graph, RBAC, secret rejection, archive queue reconciliation, and rendering (5 tests, 36 assertions).

##### IP-M1-02 — Metadata hosting, subscription dan authorization

**Implementasi:** `implemented`.

Scoped hosting account, service subscription, and management-authorization APIs record metadata, source/evidence references, precision semantics, and expiring action scope. No connector, credential value, remote action, job, or outbox event is created. `RegistryMetadataTest` verifies these boundaries (4 tests, 26 assertions).

##### IP-M1-03 — Versioned monitoring policy

**Implementasi:** `implemented`.

Owner-only monitoring policy drafts publish immutable versions. Scoped project assignments record constrained overrides, and an effective-policy preview reports timezone, disabled checks, coverage gaps, and `not_configured` honestly. `MonitoringPolicyTest` verifies version isolation, override limits, coverage, and Owner gate (3 tests, 25 assertions).

##### IP-M1-04 — HTTP probe dengan SSRF guard

**Implementasi:** `implemented`.

Safe GET probe, DNS/IP pinning, actual-peer check, hop validation, bounded total timeout/body/redirects and opt-in content matching. `HttpProbeTest`: 3 passed, 27 assertions after final reserved-address and malformed-redirect guard regressions. Native network transport is live-unverified.

##### IP-M1-05 — TLS dan DNS probe

**Implementasi:** `implemented`.

TLS peer/hostname/chain validation and expiry thresholds; DNS A/AAAA/CNAME/MX/NS structured records and optional expected values. `TlsDnsProbeTest`: 2 passed, 27 assertions. TXT unsupported; native TLS/DNS transport live-unverified.

##### IP-M1-06 — Observasi UTC, freshness dan health

**Implementasi:** `implemented`.

Append-only scoped observations with UTC chronology, fenced leases, slot uniqueness, provenance, safe evidence allowlist, freshness and capability-aware health. `ObservationTest`: 2 passed, 15 assertions. Daily aggregates and raw-retention preview preserve data; actual destructive retention remains Owner-reviewed/M4. TLS outcome normalized to PRD `warn`.

##### IP-M1-07 — Incident, maintenance dan flapping

**Implementasi:** `implemented`.

Three eligible failures/two passes, unknown streak reset, active episode uniqueness, replay dedup, structured held evidence, UTC failure/recovery times, reopen/new episode linkage and recovery-summary closure gate. Maintenance preserves samples and reevaluates alerts afterward. Four episodes test stability coalescing. `IncidentEngineTest`: initial 4 passed/25 assertions; final escalation regression suite 5 passed/30 assertions. Outbox events are business facts; Telegram delivery/history guard is M2 and not claimed.

##### IP-M1-08 — Overview dan workflow operator

**Implementasi:** `implemented`.

Attention overview, scoped project/asset/incident pages, text/icon states, source/freshness/fixture labels, empty and missing-permission states, acknowledge/investigate/assign/close with version and transactional idempotency. Owner-managed project access and PIC scope protect non-Owner reads and registry mutations. Browser APIs use session/CSRF middleware. Final local browser QA passed desktop/mobile lifecycle and elapsed freshness, with assignee/timeline/landmark/navigation repairs; authenticated cross-origin POST rejected with 419. TypeScript/Vite build passed. Tasks/client follow-ups remain M2/M4.

##### IP-M1-09 — Scheduler, durable queue dan self-health

**Implementasi:** `implemented`.

Durable database probe queue, canonical shared schedules, UTC leases, bounded crash reconciliation, latest-slot coalescing, missed-slot accounting and scheduler/worker/queue self-health. Archive/configuration changes preserve usage history and incident impact snapshots. Public probe gate is independent from credentialed connectors. Scheduler+registry regression: 8 passed, 64 assertions; incident/workflow/scheduler: 10 passed, 102 assertions; policy envelope regression passed. Build passed (27.89s); schedule list includes both minute jobs. Production Redis/watchdog/integrations remain not configured.

##### IP-M1-10 — Demo, concurrency dan evaluasi exit gate

**Implementasi:** `implemented`; pengujian `passing` untuk scope M1.

Fake demo and browser recovery helper implemented/tested, test-only MySQL guards, actual two-process concurrency and migration rollback verified. Latest complete suite: 71 passed/392 assertions, including TLS escalation; earlier 70 passed/386 assertions and browser desktop/mobile, stale/unknown, closure and CSRF evidence remain historical. The Owner-approved sequencing in ADR-0006 closes the local engineering M1 gate. Full cross-milestone scenarios remain unfinished with named M2–M5 owners; no acceptance waiver or live delivery claim.

## 4. Requirement dan batas implementasi

### 4.1 Evidence M1 saat ini

| Requirements | Implementation | Test | Live validation | Evidence |
|---|---|---|---|---|
| REG-01 | implemented | passing | live_unverified | Client/contact CRUD is organization-scoped; contacts remain business contacts rather than application users. |
| REG-02 | implemented | passing | live_unverified | Project CRUD validates client scope, unique organization code, stack tags, internal PIC membership, lifecycle, criticality, and notes. |
| REG-03 | implemented | passing | live_unverified | Production/staging/development/custom kinds are explicit; a project cannot duplicate a standard environment and asset usage validates the matching project environment. |
| REG-04 to REG-06 | implemented | passing | live_unverified | Canonical asset kinds, responsibility, owner membership, source, verification timestamp, notes, and shared usage relation exist. No secret value is accepted in notes, identity, or source. |
| REG-10 | implemented | passing | live_unverified | Paused/archived project transitions cancel future unstarted work; canonical scheduling deactivates usages without deleting history; historical incident impact snapshots and other active shared usages survive. Leased results remain fenced and reconcile safely. |
| REG-07 | implemented | passing | live_unverified | Hosting metadata requires a canonical hosting asset and records provider, panel, separate HTTPS API endpoint, account identifier, quota, access declarations, and per-environment roots. It does not connect to the provider. |
| REG-08 | partial | passing | live_unverified | Subscription metadata stores billing/paying/action parties, source/evidence reference, reminder policy, billing due, and mutually exclusive instant/date/unknown expiry precision. Renewal cycle/follow-up/reminder execution remains M2. |
| REG-09 | partial | passing | live_unverified | Project/hosting scope records allowed action classes, authorizer, evidence reference, and expiry. The authorization service refuses expired or absent classes; future connector/backup write paths must consume it in M3. |
| CON-08 | partial | passing | not_configured | Metadata may declare `manual_only`/unknown access. Independent public HTTP/TLS/DNS probes are implemented without hosting credentials. Provider discovery/credentialed capability validation remains M3. |
| POL-01 | implemented | passing | not_required_m0 | Draft configuration is separate from immutable published `PolicyVersion`; active project assignments retain their published version when the draft changes. |
| POL-03 | implemented | passing | not_required_m0 | Project override may only disable an existing check or lengthen its interval; it cannot add checks, make a faster schedule, or violate the M1 fixed failure/recovery and TLS thresholds. Effective preview includes applied overrides, IANA timezone, coverage, and disabled/not-configured states. |
| SEC-02, SEC-04, SEC-11 | implemented | passing | not_required_m0 | Active organization membership and `registry.manage` gate every mutation; audit records are redacted and input rejects recognizable secret-bearing values. |
| CON-04, MON-01/02/04/05/12, SEC-05 | implemented | passing | live_unverified | Bounded HTTP/TLS/DNS native transports, safe structured results, expected-content opt-in and per-hop pinned outbound guard; unit security negatives and fake-loop evidence. DNS TXT is unsupported. |
| MON-03/07/08/09/10/13, JOB-09 | implemented | passing | live_unverified | UTC append-only observations, freshness/coverage-aware health, unique active episode, maintenance/stability, scoped operator lifecycle, recovery-summary closure and pending business events. Delivery-side guards remain M2. |
| JOB-02/06, MON-14 | partial | passing | not_configured | Canonical durable database queue, fenced leases, bounded retry/coalescing, missed-slot counters and local scheduler/worker heartbeat page implemented. Independent watchdog and production Redis/deployment evidence remain M4/M5. |
| UX-01/02/03/04/05/09 | partial | passing | live_unverified | Scoped attention/project/asset/incident/self-health browser flows validated locally, with text/icon states, timestamp/source/fake labels and permission gates. Search, client tasks and broader accessibility/load acceptance remain their later phases. |
| MON-06 | not_started | pending | not_configured | Quota metadata supports null without fabricated percentages; credentialed resource observations and capability evaluation remain M3. |

### 4.2 Evidence M0 pada checkpoint fondasi (historis)

Status berikut merekam fondasi sebelum behavior M1 ditambahkan. Untuk kondisi M1 terkini gunakan bagian 4.1; jangan menafsirkan `not_started` historis sebagai rollback fitur yang sudah tersedia.

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

### 4.3 Scope dan gate yang belum selesai

Requirements not covered by the current M1 evidence above retain their later-phase status in `docs/IMPLEMENTATION_PLAN.md` section 5. Renewal, Telegram delivery, credentialed connectors, backup/download/restore, independent watchdog and production release gates remain unfinished. The historical M0 table records the earlier foundation baseline rather than overriding current M1 evidence. No capability is marked live-validated through schema, UI, fixtures or a pending outbox event.

### 4.4 Gate fondasi M0

M0 is `MILESTONE_READY` only after the M0 automated suite, typecheck/build, migration checks, and documented dependency recovery pass in this checkout. This status is not an Internal v1 or production-readiness claim. Production provisioning, secrets manager, Owner MFA enrollment, independent watchdog, and every live connector remain outside M0.

## 5. Bukti validasi M1

### 5.1 Scope, baseline dan keputusan

**Keputusan terkini:** M1 `MILESTONE_READY` sesuai scope engineering yang disetujui Owner; lihat bagian 1.4 dan ADR-0006. Paragraf berikut mempertahankan hasil gate literal **sebelum persetujuan**, bukan status terbaru. Full TC lintas milestone tetap unfinished.

M1 observation implementation is implemented and locally tested. The literal milestone exit gate remains **PARTIAL_WITH_BLOCKERS**: the plan requires *all* TC-01–10 to pass, but the complete PRD scenarios include renewal/full backup/account download (TC-03), Telegram delivery history (TC-05), and an independent watchdog (TC-07). Those capabilities belong to M2/M3/M4/M5 and are not implemented or silently waived here. No M2 task was started. IP-M1-10's fake vertical demo is implemented/tested; its full scenario gate remains partial.

Baseline: `main` at `ef8fb15`, clean worktree, upstream `origin/main`. IP-M1-01–03 already existed. Local instructions and actual source/tests were checked before proceeding. Existing product choices, credentials-free public probes, explicit capability gaps, fixed three-fail/two-pass behavior, environment separation, and default-disabled connectors were retained.

### 5.2 Pengujian otomatis

- `composer test`: builds TypeScript/Vite before running the complete dedicated MySQL suite. Initial implementation results are recorded in section 1.1; local detailed output is ignored at `output/qa/m1-final-suite.log`. The later complete 71-test review is recorded separately in section 1.3 and reused for the unchanged source in section 1.4.
- `php vendor/bin/pint --test`, `composer validate --strict`, and `git diff --check` pass.
- [Remote CI run 37166571900](https://github.com/solveit-id/solveit_opshub/actions/runs/37166571900) for exact implementation commit `2ad9b1b15be8b6b76fdf772d72aab6b25cecdee0` completed **success**. Ubuntu/MySQL workflow validates the final committed test files, frontend build and Pint; local suite counts above remain their actual separately recorded runs. The later documentation receipt changes no runtime/test source.
- `MonitoringVerticalDemoTest`: real scheduler, probe job, fenced observation persistence, incident engine, transactional outbox and closure services under explicit fakes. Six samples produce `up → suspect → suspect → down → recovering → up`; one canonical monitor serves two projects; one down and one recovery event remain pending for M2; first failure/recovery timestamps precede confirmations; expired freshness yields unknown.
- `MonitoringConcurrencyTest`: two separate PHP processes race against MySQL for each slot reservation, lease, and third-failure evaluation. One run, one lease holder, one active episode/outbox event, and three incident evidence records survive. The test also executes migration rollback; its InnoDB FK-index failure was repaired rather than bypassed.
- Security regressions cover private/reserved targets, credentials, redirects, DNS rebinding/peer mismatch, bounded body/deadline, content mismatch, organization/project scope, invalid assignee, optimistic version conflict, idempotency and summary secret rejection.
- TLS warning escalation emits one additional critical business event without duplicating the active episode; repeated critical samples do not repeat the event.
- Runtime migration check: 11 migrations applied to the empty local `solveit_opshub` runtime; application records remain empty. Test and runtime databases are separate. No `.env` change was committed.

### 5.3 Matriks TC-01–10

Seluruh assertion/prerequisite observasi M1 di tabel ini passing sesuai [alokasi pada plan](IMPLEMENTATION_PLAN.md#pembagian-bukti-tc-0110); kolom remaining tetap menyatakan full scenario unfinished, bukan pass. Requirement release tidak dihapus ketika gate engineering M1 ditutup.

| Scenario | Evidence in M1 | Remaining full-scenario boundary |
|---|---|---|
| TC-01 | Fake credential-free public observation loop; project browser shows backup `not_configured`, internal `unsupported`, partial coverage, explicit fixture provenance. | Native HTTP/TLS/DNS are implemented but live-unverified. No fabricated provider/internal metrics. |
| TC-02 | Registry/environment/policy scope tests, active membership/project denial and cross-organization negatives. | Live secret/provider configuration is not configured; M1 never creates hosting secrets. |
| TC-03 | Shared public asset produces one canonical monitor/run and two impacted-project snapshots; archive/configuration retains incident history. | **Unfinished full scenario:** canonical renewal reminder IP-M2-02; full account backup/impact/scoped download IP-M3-04–08/10. Shared public probing alone is not TC-03 completion. |
| TC-04 | Fake-clock third failure creates one incident and pending outbox event; distinct first/confirmed timestamps; real MySQL races prove deduplication. | Pilot detection latency/load measurement is M5; no production latency claim. |
| TC-05 | Two successful samples resolve; close requires summary and recovery; recovery event includes destination down-delivery guard. Browser recovery/closure succeeds. | **Unfinished full scenario:** IP-M2-06/08/09 must consume events and enforce actual destination delivery history. A payload guard is not delivery validation. |
| TC-06 | HTTP 200/content mismatch fails; absent expected content is `not_configured` structured evidence shown in browser. | Live target app-content validation remains unverified. |
| TC-07 | Clock tests and elapsed-time browser evidence show stale scheduler/worker and project unknown, retaining last HTTP_OK evidence without fabricated outage. | **Unfinished full scenario:** watchdog contract/failure tests IP-M4-05/07; separate failure-domain provisioning/alert IP-M5-02/04 remain not configured. |
| TC-08 | TLS expiry 30/7-day/invalid-certificate tests; nullable quota metadata remains nullable, without invented percentage. | Credentialed quota observation/capability/null evidence IP-M3-01/02/10 remains unfinished/live-unverified. |
| TC-09 | Maintenance keeps observations/downtime, suppresses alert, and alerts on a persistent failing sample after the window. | Production maintenance validation remains unverified. |
| TC-10 | Three episodes/30 minutes set flapping and coalesce one stability event; all observations and transitions remain; scoped timeline exposes history. | Telegram destination coalescing/delivery IP-M2-06/08/09 remains unfinished. |

### 5.4 Pengujian browser lokal

Chrome via Playwright CLI used the production frontend build against an isolated Laravel server at `127.0.0.1:8097`, `APP_ENV=testing`, MySQL `solveit_opshub_test`, separate QA session cookie and native probes/connectors disabled. Only fictitious records and fake observations were used. The random disposable login manifest stayed ignored and was deleted after QA.

- Internal login, overview, project, asset, incident and self-health pages render successfully. Browser actions acknowledge, assign to the active scoped Owner, investigate, and close after two fake recovery samples update persisted state/timeline. Closure is disabled while down.
- Assigned operator remains selected after a full reload; first/confirmed failure and recovery times display in Asia/Jakarta; fixture labels and structured evidence are visible. Timeline does not render a stray numeric suppression flag.
- Desktop and 390×844 mobile layouts were inspected visually. Mobile health/project measurements: document width 390 and viewport width 390; one main landmark. Keyboard Tab reaches the named application link. Navigation toggle has an accessible name, expanded state and controlled panel. This is scoped browser evidence, not a complete WCAG audit.
- After the freshness interval elapsed, project HTTP state became unknown/stale while retaining last HTTP_OK fixture evidence; self-health scheduler/worker became stale. Watchdog/storage/notification/secrets manager stay explicitly not configured.
- Authenticated cross-origin POST without a CSRF token returns **419**. No success claim is made for a rejected mutation. Automated tests independently cover permissions/conflicts/idempotency.
- Correct application pages have zero console errors/warnings. One manually mistyped asset URL returned 404; the registered `/organizations/{organization}/assets/{asset}` route then rendered normally. A layout CLI argument initially lost its quotes in PowerShell; running the same measurement from a script file succeeded. Neither diagnostic failure was hidden or treated as an application regression.

Local, ignored screenshots: `output/playwright/m1-incident-closed.png`, `m1-overview-mobile.png`, `m1-project-stale-mobile.png`. Screenshots/snapshots/logs are local QA artifacts, not required tracked runtime assets. The temporary server/browser were stopped; the final test suite resets the dedicated test schema and removes fictitious demo records. Persistent MySQL runtime/container data are preserved.

## 6. Runbook monitoring lokal

### 6.1 Setup dan menjalankan monitoring

Run migrations on the configured MySQL runtime (`composer db:check`, `php artisan migrate`), build the frontend, create an internal Owner if not already configured, and record a canonical public URL/domain usage and published monitoring policy assignment. The Owner grants project access or assigns a PIC. Public URLs require no hosting credential; hosting secrets and write connectors remain separately disabled.

`OPSHUB_PUBLIC_PROBES_ENABLED=false` is the development default. Explicitly set it to `true` only for approved public registry targets after the deployment/network review; this does not enable hosting connectors. The normal executor never substitutes a fake probe. Disabled execution records `unknown/PUBLIC_PROBES_DISABLED`, not pass or target downtime.

Run `php artisan schedule:work` and a separate `php artisan queue:work database --queue=probe --timeout=40 --tries=2` worker. A one-shot scheduler tick is `php artisan opshub:monitoring:schedule`. Database queue insertion and the job-slot record share a transaction; production Redis adoption needs its own durable dispatch validation. Restart workers after code/config changes.

Public HTTP uses GET, TLS verification, pinned public IPs, actual-peer validation, at most five individually validated redirects, a total ten-second request deadline and at most 1 MiB body per hop. Expected content is opt-in and is evaluated only in bounded transient memory. Body, raw headers, URLs and raw network errors are not persisted. TLS probes verify the peer name/chain. DNS supports A/AAAA/CNAME/MX/NS; TXT is intentionally unsupported.

### 6.2 Aturan observasi dan incident

- UTC storage; IANA policy timezone and Asia/Jakarta display. A target edit changes the canonical monitor configuration; queued old configuration returns unknown instead of observing the wrong target.
- Three eligible HTTP/DNS failures confirm down; two successful samples recover. TLS warning/critical certificate evidence applies immediately. Unknown samples break streaks, and missed jobs only affect freshness/coverage.
- One active incident per canonical monitor. Resolved incidents reopen before closure; a closed incident leads to a linked new episode. Closure requires confirmed recovery and a summary, which may honestly state unknown cause.
- Maintenance suppresses alerts while retaining all observations/downtime. Persistent problems are alerted on the next sample outside the window. Three down/recovery episodes in 30 minutes coalesce to one stability warning in that interval.
- Shared public assets with the same environment kind and effective immutable policy are scheduled once. Different policy/configuration/environment scopes remain separate. Historical usage and incident project-impact snapshots survive archive/configuration changes.
- Expired worker leases fence late results. Failed execution remains unknown; crashed runs retry the same slot with at most three claimed attempts and then enter `failed`. Older queued slots are coalesced rather than replayed as a burst.
- Incident events remain pending for the M2 consumer. A recovery event requires the M2 destination consumer to verify down delivery history before sending; event persistence is not delivery evidence.

### 6.3 Inspeksi, recovery dan retention

Use `/organizations/{organization}/health` for scheduler/last probe-worker heartbeat, queue lag, missed/coalesced slots, and integration configuration boundaries. A stale worker heartbeat means no recent work evidence; it is not proof that the worker process is dead. Storage, notification, secrets manager and independent watchdog are `not_configured` until their later deployment gates pass.

If the queue stalls, inspect worker logs and failed jobs locally without exporting credentials. Resume the scheduler/worker; it coalesces missed slots and reconciles expired runs. Review dead-letter failures and fix configuration/infrastructure before an explicit retry. Never insert synthetic HTTP failures or successes to fill a gap.

Daily aggregation is available through `ObservationRetention`; raw HTTP retention preview is 30 days and daily uptime retention target is 12 months. No raw deletion is automatic in M1. Incident evidence is held. Owner-reviewed destructive retention and routine cleanup/hold reporting are M4 work.

### 6.4 Batas validasi live

M1 automated evidence uses dedicated MySQL `_test` databases and explicitly labelled fake results. Native probes are implemented, not live-validated against a client/provider. Pilot performance, production queue infrastructure, independent watchdog, hosting connectors, backups and Telegram delivery remain unverified/configuration work for their approved milestones.

### 6.5 Demo pada database test terisolasi

Use an already provisioned disposable MySQL test schema only. In PowerShell set process-local `APP_ENV=testing`, `DB_DATABASE=solveit_opshub_test`, and empty `DB_URL`, verify with `php artisan opshub:database:check`, then run `php artisan opshub:monitoring:demo`. The command refuses a non-testing environment, a non-MySQL connection, a schema without `_test`, or a connection URL before writing. It does not reset the database. All observations are explicitly fake and native execution stays disabled.

`--browser` adds a linked open episode and writes a random disposable login to ignored `storage/app/qa-m1-login.json`. Run a separate test-only HTTP server/session cookie, build assets, and use that fixture to exercise operator actions. `--recover={organization_id}` accepts only fictitious fake-only demo records in that same isolated test environment; it runs two fake successes through the real scheduler/job services. Never use either helper for runtime records or substitute synthetic data into a production coverage gap.

After QA stop the temporary browser/server, delete that exact login manifest, and reset the disposable schema through the project's guarded test suite. Preserve persistent runtime data. Detailed M1 scenario evidence and unresolved cross-milestone exit assertions are in section 5.

## 7. Riwayat validasi fondasi M0

Catatan berikut adalah hasil historis 4 Oktober 2026. Jumlah migration/table dan test pada setiap tahap berlaku pada commit/tahap tersebut. Kondisi setelah implementasi M1 tercatat di bagian 1 dan 5. SQLite hanya muncul sebagai riwayat sebelum keputusan MySQL; seluruh workflow database aktif memakai MySQL.

### 7.1 Validasi fondasi awal

The following evidence was run in this checkout on 4 Oktober 2026 after recovering the incomplete Composer vendor tree: `composer validate --strict`, `php -r "require 'vendor/autoload.php';"`, `vendor/bin/pint --test`, `php artisan test` (**36 passed, 91 assertions**), `php artisan about`, `php artisan migrate:status`, `php artisan route:list --path=api/v1/foundation`, `php artisan schedule:list`, and `npm.cmd run build` (**27.75 seconds**). The generated `public/build/manifest.json` exists. These results validate local development and fakes only; they are not deployment, credential, MFA-provider, or live-connector validation.

### 7.2 Perbaikan konfigurasi CI

**CI repair (4 Oktober 2026):** Earlier runs exposed incompatible Node types and a missing test encryption key. The dependency and lockfile now resolve `@types/node` 22.12.0, and `phpunit.xml` supplies a fixed non-production key for testing. CI also copies the committed non-secret `.env.example` into its disposable workspace and generates a fresh runner-only `APP_KEY`; neither generated file nor key is committed or used for deployment. The subsequent run for `0aed714` still failed during application tests: the workflow built frontend assets only after tests, while Inertia HTTP tests render `@vite` and require `public/build/manifest.json`.

### 7.3 Reproduksi build manifest

**Historical clean checkout evidence (before the MySQL-only decision):** A disposable archive of `0aed714`, without the working checkout's `.env`, `public/hot`, or `public/build`, reproduced **8 failed, 40 passed (174 assertions)** with `ViteManifestNotFoundException`. Dependencies were installed from the committed Composer/npm lockfiles using PHP 8.5 and Node 22. Running `npm.cmd run build` before `php artisan test` generated the real manifest and produced **48 passed, 176 assertions**; Pint and `composer validate --strict` also passed. That historical run used SQLite `:memory:`. The build-before-test repair is retained in the current MySQL workflow; HTTP rendering assertions remain enabled.

### 7.4 CI historis

**Historical remote CI verified:** [CI run #9](https://github.com/solveit-id/solveit_opshub/actions/runs/37147197456) for repair commit `9855a57283fc8a55f6a97c9c7ed55b85a403598d` completed with **Success** on 4 Oktober 2026. Its `foundation` job took **1 minute 10 seconds**, and every step succeeded, including frontend build, application tests, and Pint. [CI run #10](https://github.com/solveit-id/solveit_opshub/actions/runs/37147434309) also succeeded at `983b194f91d499732bd41552a1da10d34d17ebf0`. These runs verify the preceding build-order repair on GitHub's Ubuntu runner, not the later MySQL-only changes or deployment readiness.

### 7.5 Keputusan MySQL-only

**MySQL-only workflow adopted on 4 Oktober 2026:** The project owner requires MySQL for every database workflow; see [ADR-0005](adr/0005-mysql-primary-database.md). Setup, local runtime, migrations, all database tests, and CI now use MySQL/InnoDB. The database config defines only MySQL; application guards reject other drivers and MariaDB. PHPUnit forces the testing environment, MySQL connection, `solveit_opshub_test`, and an empty `DB_URL`, with a pre-reset safety check in `Tests\TestCase`. Composer setup/test/create-project scripts and the project README follow the same workflow. PRD, implementation plan, ADR-0001, and ADR-0003 are synchronized.

### 7.6 Migration dan suite MySQL

**MySQL migration and test evidence:** The initial MySQL compatibility run found error **1059**: two generated foundation index names exceeded MySQL's identifier limit. Explicit short names preserve the indexed columns, foreign keys, and uniqueness. The persistent local MySQL **8.4.11** service now runs at **127.0.0.1:3308**. The ignored `.env` selects runtime database `solveit_opshub`; the existing application key is preserved. `composer db:check` authenticated successfully and all **7 runtime migrations** completed. `composer test` built frontend assets (**35.49 seconds**) and passed the complete MySQL suite (**48 tests, 176 assertions**). Pint, `composer validate --strict`, `docker compose config --quiet`, and `git diff --check` passed.

### 7.7 Fresh setup

**Fresh setup evidence:** A separate disposable Compose project with a new empty volume initialized both `solveit_opshub` and `solveit_opshub_test` on MySQL **8.4.11**. The non-root application account authenticated to both schemas, and the test-database initialization script was safely repeatable. The validation project's container, network, and volume were then removed; the runtime service and its persistent data remain. The complete `composer setup` command then passed: locked Composer dependencies/autoload, local environment preparation, healthy MySQL service, authenticated runtime check, no pending migrations, `npm ci`, and frontend build (**44.67 seconds**). Dependency lockfiles remained unchanged; `npm ci` reported **5 high-severity audit findings**, outside this database change's scope. The former ignored local database was inspected read-only: application tables contained no records and only seven migration-history rows existed. No application records needed transfer; the disconnected legacy file remains preserved. XAMPP MariaDB **10.4.32** is not used by this project.

### 7.8 Isolasi konfigurasi/database

**Configuration and isolation checks:** Effective connection configuration contained only `mysql`, both before and after `config:cache`; the cache was cleared afterward. All **29 runtime tables** used InnoDB and runtime records remained unchanged after testing (only **7 migration-history rows**, no application records). Manual probes rejected the runtime database, non-testing environment, non-MySQL driver, and a connection URL before schema reset. Direct `php vendor/bin/phpunit --no-progress` also passed **48 tests, 176 assertions** with deliberately conflicting process-level environment values; PHPUnit's environment and server settings still selected the dedicated MySQL test schema. Repeating local environment preparation preserved every existing value.

### 7.9 Aturan verifikasi CI

The updated CI workflow provisions a disposable MySQL 8.4 service and runs the complete suite on MySQL only, preserving build-before-test order. Remote verification must check a MySQL workflow run for the exact published commit. Historical test results above do not represent an active alternative database path or validate the new MySQL workflow.

## 8. Riwayat commit dan push

| Task | Commit |
|---|---|
| IP-M1-04 | `2b9bfdd` safe bounded HTTP |
| IP-M1-05 | `744fc3c` TLS/DNS observations |
| IP-M1-06 | `895b671` UTC observations/freshness |
| IP-M1-07 | `4e14bc3` incident lifecycle/maintenance |
| IP-M1-08 | `7d8f8ca` scoped operational workflows |
| IP-M1-09 | `6de1155` canonical durable scheduling/self-health |
| IP-M1-10 | `2ad9b1b` isolated demo/concurrency and browser/security fixes; pushed and remote hash verified. |

Dokumentasi receipt M1: `989cf1758773affc7a2de40014236a8074250579`. Pada awal penataan dokumen ini, worktree bersih di branch `main`, upstream `origin/main`, HEAD `989cf17`. Hash implementasi, hasil CI dan push terdahulu adalah bukti historis yang tetap dipertahankan; perubahan dokumentasi dicatat terpisah di bawah.

## 9. Aturan pembaruan checkpoint

1. Baca PRD, urutan/dependency task, instruksi lokal dan Git state sebelum mengubah implementasi.
2. Catat task aktif, hasil aktual, pekerjaan tersisa, dependency/blocker, pemeriksaan dan batas live.
3. Kaitkan klaim selesai dengan acceptance criteria serta evidence yang relevan; status gate tidak disimpulkan dari scaffold/placeholder.
4. Jika pilihan arsitektur/scope berubah, perbarui plan dan ADR berdasarkan keputusan yang diotorisasi. PRD tetap otoritas produk.
5. Catat commit/push yang telah terverifikasi. Jika receipt ditulis dalam commit berikutnya, sebutkan hash implementasi yang dirujuk; jangan mengarang hash commit sendiri sebelum dibuat.
6. Pertahankan bukti historis beserta tanggal/commitnya; jangan mencampur jumlah test lama, targeted regression, CI, browser dan validasi live.
7. Simpan progres, evidence dan runbook di dokumen ini agar tidak muncul checkpoint paralel yang saling bertentangan.

### 9.1 Penataan dokumentasi — 4 Oktober 2026

Catatan berikut historis, sebelum persetujuan sequencing Owner; keputusan dan next step terkini ada di bagian 1.4.

**Scope:** konsolidasi progres/evidence/runbook, perapian roadmap, pemindahan PRD/plan ke `docs/`, perbaikan referensi, dan instruksi root `AGENTS.md`. Tidak ada task milestone baru atau perubahan runtime aplikasi.

**Validasi perubahan ini:** audit otomatis lulus: 212 pemeriksaan integritas isi; 52 task roadmap beserta mapping/output dan 6 exit gate literal tetap dipertahankan; evidence dari empat dokumen sumber terjaga; seluruh acceptance criteria milestone tidak berubah. Validasi struktur mencakup 10 file Markdown dan 81 tautan relatif/anchor, tanpa referensi path usang, conflict marker atau encoding rusak. Isi PRD tidak berubah saat dipindahkan. Pemeriksaan scope staging dan `git diff --check`/`git diff --cached --check` dilakukan sebelum commit. Suite aplikasi/build/browser tidak diulang untuk perubahan dokumentasi ini; hasil di bagian sebelumnya tetap evidence historis run implementasi M1.

**Commit/push:** receipt akhir tersedia di Git history untuk commit `(docs) consolidate checkpoints, organize roadmap and add project instructions`; remote/head diperiksa sesudah push biasa. Jangan menganggap push berhasil sebelum evidence tersedia.

**Next step:** tetap `IP-M1-10` exit-gate review dengan dependency/blocker bagian 1.2; perubahan dokumen tidak mengotorisasi M2 atau aksi live.
