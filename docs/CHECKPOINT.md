# Solveit OpsHub — Checkpoint dan Panduan Operasi

**Diperbarui:** 5 Oktober 2026

**Status milestone terakhir:** M2 — `MILESTONE_READY` untuk gate engineering lokal/fake sesuai ADR-0006; Internal v1 dan integrasi production belum ready.

**Milestone/task aktif:** M3 `PARTIAL_WITH_BLOCKERS`; `IP-M3-01–09` implemented/tested lokal, `IP-M3-10` validasi lokal/fake selesai, live gate blocked. Jalur native full-account/storage/key resolver/isolated target not configured; sandbox/storage/restore live belum tersedia. [Progres dan evidence M3](#11-progres-m3--connector-dan-verified-backup).

**Task terakhir:** `IP-M3-10` — failure/security/MySQL race matrix, deletion permits/lease fencing dan seluruh checks lokal passed; review lanjutan 5 Oktober 2026 mengonfirmasi remaining live gate blocked, kedua gates false dan tidak ada task M3 independen yang belum selesai. Berhenti dalam M3; M4 belum dimulai.

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
10. [Progres M2](#10-progres-m2--telegram-renewal-dan-client-action-loop)
11. [Progres M3](#11-progres-m3--connector-dan-verified-backup)

## 1. Ringkasan progres dan keputusan berikutnya

| Milestone | Progres aktual | Status gate | Langkah tersisa |
|---|---|---|---|
| M0 — Foundation | IP-M0-01–09 implemented; fondasi dan CI sudah diuji. | `MILESTONE_READY` untuk fondasi lokal; bukan kesiapan production. | Provisioning/MFA/integrasi live tetap mengikuti phase berikutnya. |
| M1 — Registry dan observation | IP-M1-01–10 implemented/tested; sequencing disetujui Owner. | `MILESTONE_READY` untuk engineering lokal sesuai ADR-0006; full TC tetap unfinished. | Tidak ada pekerjaan M1 tersisa; validasi live dan full scenario mengikuti milestone pemilik. |
| M2 — Telegram/renewal/client loop | IP-M2-01–09 implemented/tested. | `MILESTONE_READY` lokal/fake; bukan integrasi production. | Tidak ada implementasi M2 tersisa; receipt CI/push di bagian 10. |
| M3 — Connector dan verified backup | IP-M3-01–09 implemented/tested lokal; IP-M3-10 local/fake validation complete. | `PARTIAL_WITH_BLOCKERS`; live sandbox/storage/restore gate belum terpenuhi. | IP-M3-10 live validation setelah target/adapter/failure-domain evidence tersedia; jangan masuk M4. |
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

**Commit/push terverifikasi:** penutupan M1 `a666a2c8da065486827fabfc6e4c518eb7f873b0` (`(docs) align approved milestone gates and close M1`) mencakup tepat lima path dokumentasi; staged scope/stat/check dan outgoing commit diperiksa, tanpa attribution trailer. Push biasa `main` → `origin/main` berhasil dan remote `refs/heads/main` cocok. Receipt ini dicatat pada commit dokumentasi berikutnya; CI untuk commit baru belum diverifikasi. Commit review terdahulu `fe1e092` dan receipt `a286843` tetap evidence historis.

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

## 10. Progres M2 — Telegram, renewal dan client action loop

**Baseline:** `main`/`origin/main` bersih pada `e6bbd34766b7d83e1f78d1075cac782e6dab2ad5`; Owner menginstruksikan melanjutkan milestone pertama yang belum selesai sesudah M1 ditutup. Scope aktif M2 saja; M3 belum dimulai. Semua probe/connector live tetap disabled dan tidak ada bot/destination production dikonfigurasi.

| Task | Status | Evidence / pekerjaan tersisa |
|---|---|---|
| IP-M2-01 | implemented/tested | Expiry/source/date precision, billing/payment terpisah; canonical subscription dan unique active cycle/primary follow-up; append-only contact model, derived overdue dan metadata version/409. |
| IP-M2-02 | implemented/tested | Calendar thresholds, skipped history/shared canonical events, bounded escalation dan reasoned expiring snooze/pause. |
| IP-M2-03 | implemented/tested | Follow-up/contact/response/renewal verification workflow. |
| IP-M2-04 | implemented/tested | Sepuluh versioned templates, mandatory-variable validation dan deterministic drafts. |
| IP-M2-05 | implemented/tested | Dashboard Copy/Mark contacted/current draft/verified resolution. |
| IP-M2-06 | implemented/tested | Telegram config/destination/outbox/domain/renderer. |
| IP-M2-07 | implemented/tested | Binding/webhook/commands/constrained callbacks. |
| IP-M2-08 | implemented/tested | 29 tests/319 assertions; reconciliation, retries, current digest, findings/privacy/priority. Native live-unverified; receipt di bawah. |
| IP-M2-09 | implemented/tested | Full suite 132 tests/1142 assertions; HTTP demo + race MySQL, 10 template mandatory coverage dan browser nyata desktop/mobile passed. Live Telegram unverified; receipt di bawah. |

### IP-M2-01 — Model renewal dan follow-up

**Hasil:** migration/model untuk canonical renewal resource, satu active cycle per service dan satu primary follow-up per cycle; UTC instants terpisah dari date-only sumber IANA, billing due/payment status/renew_by terpisah; histori contact append-only dan overdue derived. Metadata memakai locked version/409; koreksi expiry wajib reason/evidence dan tidak memverifikasi renewal. Organization/resource scope dan duplicate canonical subscriptions dicegah. Data awal lama dapat memperoleh cycle melalui service tanpa reset runtime.

**Pengujian:** `php artisan test --filter='RenewalModelTest|RegistryMetadataTest|MonitoringWorkflowTest|ObservationTest|MonitoringConcurrencyTest'` **16 passed / 130 assertions**, 43.89s pada MySQL `solveit_opshub_test`; mencakup uniqueness database, canonical/cross-org denial, date/instant/billing, stale version, correction vs verification, append-only contact, UTC/fencing dan migration rollback/race dua proses. Pint dan strict Composer validation lulus. Diagnosis awal: mapping Evidence mengarah ke `evidence` sementara schema `evidences`; diperbaiki. Fixture organization harus mengisi active untuk pemanggilan service sebelum hydrate, dan UTC helper menjaga date-only tidak bergeser. Kegagalan tidak dinonaktifkan.

**Batas:** belum ada reminder, rendered draft atau Telegram delivery; pengujian fixture tidak berarti client telah dihubungi/renewed. Runtime migration masih pending, persistent data tidak di-reset. UI/build/browser/suite penuh belum diulang pada task model ini; manifest M1 tersedia. Task selanjutnya akan menambahkan perilaku sesuai acceptance, bukan mengklaim schema sudah mencakup gate M2.

**Commit/push:** `e0dafa2d4d48ba2225523a9ea907b20661865198`, `(feat) model scoped renewal cycles and follow-up history`; push biasa ke `origin/main` berhasil, remote hash exact diverifikasi.

**Next step:** **IP-M2-02** — scheduler H-60/30/14/7/3/1/0 dan timezone calendar, skipped history/highest applicable threshold, canonical shared reminder/outbox, bounded snooze/escalation. Dependency IP-M2-01 dan fondasi atomic outbox M0 tersedia; tidak memerlukan token live.


### IP-M2-02 — Threshold scheduler dan escalation

**Hasil:** scheduler canonical organization/service/cycle mengeluarkan satu event per threshold, memakai hari kalender sumber IANA H-60/30/14/7/3/1/0. Late entry hanya threshold paling mendesak, threshold lama skipped; impacted active-project snapshot tidak menduplikasi shared resource. Expiry unknown/unverified menghasilkan verification task, tanpa tanggal fiktif. Waiting tidak menghentikan critical; unacknowledged critical escalation hanya pada 15/60 menit, overdue maksimal satu event per hari. Snooze/pause wajib reason/end, maksimal critical 24 jam/noncritical tujuh hari, optimistic version dan audit. Command terjadwal setiap menit dengan atomic outbox dan unique reminder.

**Pengujian:** `php artisan test --filter='RenewalSchedulerTest|RenewalModelTest'` **10 passed / 43 assertions**, 19.52s pada MySQL `_test`; late-entry/skipped/shared snapshot/repeat idempotency, boundary Asia/Jakarta, waiting/overdue, unknown, bounded escalation dan snooze cap. Pint targeted lulus; docs integrity/diff diperiksa sebelum commit.

**Batas:** reminder/outbox `pending` adalah event durable, bukan Telegram delivery; Owner route escalation baru dikonsumsi task 06/08. Command tidak menjalankan probe/send live. Runtime migration masih pending; targeted service tests bukan browser/CI/full gate.

**Commit/push:** scoped task commit dan push biasa dilakukan setelah staging review; receipt exact dicatat dalam checkpoint task berikutnya.

**Next step:** **IP-M2-04** — sepuluh versioned template dan deterministic validated draft. Dependency IP-M2-01/02 tersedia. IP-M2-03 contact-recording membutuhkan ready/current draft dari 04, sehingga 04 dikerjakan dahulu; bukan waiver task 03. Sesudah 04 kembali ke 03, lalu 05–09; M3 tidak diotorisasi.


### IP-M2-04 — Sepuluh template dan deterministic drafts

**Hasil:** seed organization-scoped TPL-01…10 versioned dari wording PRD, mandatory/allowed variables, trigger dan conditional deadline. Owner mempunyai API list/preview/publish dengan session/CSRF, immutable publication dan version/409. Renderer deterministic plaintext, menghapus optional clause utuh, menjaga Unicode dan menolak unknown/syntax/secret/URL/detail keamanan sensitif. Snapshot draft menyimpan versi/hash sumber/contact/variables/body/status/generated/edited actor; source berubah menjadi stale, publication baru supersedes, historic sent body immutable. Threshold client/shared menghasilkan draft + linked primary follow-up + outbox dalam transaksi yang sama, repeat tidak menduplikasi. Missing contact/PIC/review/verified expiry blocked; unknown memakai TPL-04; TPL-06/07/08/09 membutuhkan reviewed evidence, TPL-10 hanya verified renewal. Domain menggunakan actual canonical domain resource, bukan identitas subscription.

**Pengujian:** targeted suite `ClientTemplateTest|RenewalSchedulerTest|RegistryMetadataTest|MonitoringConcurrencyTest` **15 passed / 132 assertions**, 67.10s; setelah source-security/HTTP/domain additions, final `ClientTemplateTest` **9 passed / 94 assertions**, 25.43s. Mencakup sepuluh seeds, deterministic optional clauses, atomic/idempotent drafts, unknown/blocked/stale/superseded, historical body, Owner role/409/invalid publish, unsafe source tidak tersimpan, review/preview, actual domain, MySQL migration rollback/race dan metadata regressions. Diagnosis awal Membership tidak mempunyai scoped trait; organization filter eksplisit memperbaikinya. Pint lulus; integritas docs/acceptance/gate dan staging review dilakukan.

**Batas:** belum UI Copy/contact; endpoint governance dan domain tested bukan browser/live bot. Tidak ada biaya/billing clause yang diambil dari metadata belum terverifikasi; URL pada client body ditolak, secure access instruction berupa teks. Runtime migration masih pending, fake fixtures bukan data client nyata. Full milestone demo/gate tetap task 09.

**Commit/push:** scoped task commit/push biasa sesudah review; receipt exact dicatat pada task berikutnya. IP-M2-02 telah di-push pada `5a16e846df1b04d784e47d043245c494124130da` dan remote exact diverifikasi. Separator catatan progres yang rusak oleh encoding PowerShell diperbaiki tanpa rewrite history.

**Next step:** **IP-M2-03** — follow-up assignment/response/contact yang terpisah dari Copy, verified renewal future/later + provider evidence, new cycle/cancel old pending. Dependency IP-M2-01/02/04 tersedia; UI 05 dan delivery 06–08 mengikuti. Tidak ada blocker live untuk domain workflow.


### IP-M2-03 — Follow-up dan verified renewal

**Hasil:** server-side organization/project/role-scoped workflow untuk assignment/claim/ack, next follow-up, response/blocker/commitment, waiting/client-confirmed/in-progress, reasoned cancel/reopen, contact dan verify renewal. Waiting wajib deadline; client-confirmed/payment report tidak menutup renewal. Contact memerlukan current/ready draft dan contact target yang cocok, waktu aktual bukan future, manual channel dan next follow-up; immutable snapshot body/actor/contact/version/evidence disimpan append-only. Session/CSRF API memakai required idempotency key, locked transaction dan optimistic 409 dengan scope recheck pada replay. Verify memerlukan versi follow-up/subscription, precision/source, future/later expiry serta evidence provider baru yang telah diverifikasi oleh anggota berizin; payment/approval/old proof ditolak. Old cycle verified/by/time, old pending reminders/outbox cancelled, satu new active cycle, renew_by dihapus atau future deadline baru, payment terpisah; TPL-10 current tersedia.

**Pengujian:** final `FollowupWorkflowTest|ClientTemplateTest` **15 passed / 140 assertions**, 32.30s pada MySQL `_test`; contact idempotency/current/stale/future/body/audit, waiting deadline/claim/response/cancel/reopen, verified cycle/receipt/replay/409, payment vs expiry/later/future/old proof, current RBAC/cross-org, source/new deadline hardening dan seluruh template regression. Pint targeted dan docs integrity/staged diff diperiksa.

**Batas:** contact adalah manual record berdasarkan konfirmasi operator, bukan bot mengirim ke client. Evidence fixture bukan provider live; tidak ada perubahan payment yang dianggap technical renewal. Pending outbox bukan delivery; reconciliation cancellation delivery dikerjakan 08. UI/browser dan full demo belum diulang; runtime migration tetap pending.

**Commit/push:** scoped workflow commit dan push normal sesudah review; receipt exact dicatat saat task berikutnya. IP-M2-04 `2ea62a5324ba5f237a32092f2d2b911af6ae97fd` telah di-push dan remote hash exact diverifikasi.

**Next step:** **IP-M2-05** — dashboard scoped renewal/follow-up, current draft/blocked data links, clipboard Disalin terpisah dari Mark contacted, response/verified resolution dan template governance UI. Dependency 01–04 tersedia. Sesudah 05 lanjut 06–09; tidak ada domain blocker yang memerlukan token live.


### IP-M2-05 — Dashboard follow-up dan template

**Hasil:** authenticated Inertia/API renewal overview, current/unknown/date-only expiry, shared impacted-project filtering, active/history cycle links, overdue/snooze, read-only/empty/partial scope. Follow-up detail menyediakan current draft/contact/template selector, reviewed context, blocked reason + registry link, source-version refresh, Copy dengan feedback Disalin dan review ulang bila berubah. Mark contacted form terpisah mempunyai target/body snapshot, actual sent time/manual channel/next follow-up/optional evidence dan konfirmasi; sukses mereset form. Assignee/waiting/client response/ack/claim/progress/snooze/cancel/reopen tersedia. Verification screen merekam human-reviewed provider evidence reference dalam transaksi, lalu future/later expiry/new cycle, tanpa payment-success claim; reference tidak diekspos pada detail. Closed cycle tidak menghasilkan reminder draft ready. Owner template page menyediakan syntax/schema, labelled preview dan versioned publish. Scope parsial tidak dapat membaca body/contact/history atau memutasi shared follow-up.

**Pengujian:** final `RenewalDashboardTest|FollowupWorkflowTest|ClientTemplateTest` **19 passed / 214 assertions**, 41.04s MySQL `_test`; HTTP/Inertia/current draft, positive-version 409, role/scope/cross-project denial, manual start idempotency, session/CSRF middleware, reviewed-reference transaction/absence from responses, contact/renewal/template regression. Pint targeted lulus; TypeScript/Vite final build **36.43s** lulus.

**Browser nyata:** Playwright CLI pada loopback `127.0.0.1:8012`, guarded `APP_ENV=testing`, MySQL `solveit_opshub_test`, no DB_URL, live gates false. H-14 ready TPL-01 → Copy Disalin (state open, attempts 0) → fictitious manual contact (contacted, attempts 1) → waiting → client-confirmed (cycle active/payment unknown) → verified provider reference/new expiry (resolved, verified cycle, ready TPL-10, attempts 1/payment unknown). Unknown TPL-04 ready dan missing service blocked/Copy disabled diperiksa. Mobile 390×844 pada unknown/blocked/templates tidak overflow (scrollWidth=390), screenshot visual ditinjau; Owner preview ready mengaktifkan publish. Console pada halaman sesudah fix **0 errors/0 warnings**. Artifact ignored: `output/playwright/m2-unknown-mobile.png`, `m2-blocked-mobile.png`; network evidence actions API 200 dan snapshot di `.playwright-cli/`. Browser/server dihentikan, port 8012 tidak listening, manifest login dihapus. Fixture dapat dibersihkan oleh disposable test migrations; runtime tidak di-reset.

**Diagnosis/perbaikan:** viewer filter harus mengubah Collection menjadi array; test version0 adalah invalid input (422), diganti real stale positive version sesudah claim (409). Browser menemukan HTML pattern Unicode-v memerlukan slash/hyphen escaped, diperbaiki dan RegExp-v valid; contact form reset diuji ulang (checkbox false/manual channel kosong sesudah sukses). CLI PowerShell menghilangkan empty argument dan CLI run-code aktual membutuhkan function; mengikuti help aktual untuk JSON preview, bukan mengulangi perintah tanpa diagnosis.

**Batas:** hanya fictitious browser/manual records; tidak ada pesan nyata ke client/Telegram/provider. Evidence reference berarti operator menyatakan telah memeriksa bukti di storage aman, bukan fetch/validasi provider API live. Telegram delivery/config/retries masih task 06–08; runtime migration pending, full gate M2 masih task 09. Browser safety/role assertions tidak menyatakan production/MFA provisioning atau live readiness.

**Commit/push:** scoped UI/workflow commit + push normal setelah review; exact receipt dicatat task berikutnya. IP-M2-03 `4ff7c7bb6953bd282af67e7a7a6ce00241e5ee1a` telah di-push, remote exact diverifikasi.

**Next step:** **IP-M2-06** — Owner bot secret reference/destination allowlist/routing/quiet hours/digest/test intent, durable scoped deliveries dan internal-header/separate plaintext renderer, bounded segmentation/rate/coalescing foundation. Dependency M0/outbox dan IP-M2-01–05 tersedia. Bot/destination live belum dikonfigurasi; native implemented + fake tested harus dipisahkan dari live-unverified. Sesudah 06 lanjut binding/webhook 07, reconciliation 08, full demo/exit gate 09; jangan masuk M3.


### IP-M2-06 — Konfigurasi Telegram, durable delivery dan rendering

**Hasil:** Owner-only session/CSRF configuration UI/API dengan recent password step-up, version/409, constrained environment secret references, verified bot identity sebelum enable, rotation invalidates identity/destinations, allowlisted immutable chat identity, explicit group membership/scope confirmation dan current private member access. Settings menyimpan IANA timezone/digest/quiet hours/severity/Owner route; test memakai idempotent transactional outbox intent dan database notification queue, tidak diklaim sent saat intent dibuat. Durable per-recipient/kind/chunk/revision delivery/attempt/history domain, lease fencing dan pinned TLS native Bot API adapter dengan live gate default false. Native tidak mengeluarkan token URL, raw curl/provider errors atau HTTP telemetry events; fixed host, bounded body/header/timeout, proxy/redirect disabled dan actual public peer check dipertahankan.

**Renderer/limiter:** current renewal package berisi event/resource/project scope/severity/WIB/evidence/PIC/action/link; client plaintext terpisah tanpa internal markup/button. Shared partial destination tidak menerima body atau nama proyek di luar scope. Unicode UTF-16 margin 3500, sequential chunk dependency, intact dashboard URL; body >8 chunks diarahkan ke full dashboard. Burst >=5 event per severity/recipient dalam 2 menit coalesces summary, mempertahankan semua event/individual delivery records. Reservation di bawah bot lock membatasi group 15/min, private 1/sec, bot 20/sec, termasuk failed attempts. `sent` memerlukan API acceptance dan message ID; uncertainty tetap unknown. Outbox UTC storage diperbaiki; preflight runtime menunjukkan users/organizations/outbox/service/incident semuanya **0**, jadi tidak ada legacy outbox timestamp yang perlu dikonversi.

**Pengujian:** `TelegramConfigurationTest|TelegramDeliveryTest|FoundationReliabilityTest` **17 passed / 121 assertions**, 44.41s, MySQL `_test`. Scope/role/step-up/raw-secret rejection, rotation/409, explicit test dedup, current package/contact unchanged, shared scope denial, Unicode, 100-event retention/coalescing, exact microsecond rate boundaries, structured 429/5xx/401/403/400/missing-ID/live-disabled covered. Initial combined run also passed MonitoringVerticalDemoTest/RenewalSchedulerTest (7 tests); its one fixture reset failure was diagnosed as stale Eloquent dirty state and corrected with fresh model, not relaxed assertions. Build TypeScript/Vite and Pint passed; docs/task/acceptance/link integrity reviewed.

**Batas:** native implemented, fake tested, **live-unverified**; no bot token/chat/network call live. Environment references require actual process environment or compatible secret resolver; config cache must not be assumed to load arbitrary dotenv values. No production fake fallback. Runtime migrations still pending; preflight read-only target confirmed MySQL `solveit_opshub`, live gates disabled. Automated operational dispatch/current-state reconciliation/retry uncertainty/digest/findings belong to 08; only explicit Owner test is queued at this task. Binding/webhook/callback commands belong to 07. Browser for new Telegram settings not yet run; milestone browser/exit gate remains 09.

**Commit/push:** scoped task commit + normal push after review; exact receipt recorded next task. IP-M2-05 `43f265cb324e23c43ce5c98be05fb0aca2f9dc4a` already pushed and remote exact verified.

**Next step:** **IP-M2-07** — one-time 10-minute private binding plus authenticated same-user dashboard confirmation, secret-header durable webhook receipts/dedup async processing, scoped commands and opaque constrained callback actions with state/permission/replay checks. Dependency M0/session/identity/outbox and IP-M2-06 available; no live credential required for isolated fake tests. Then 08 reconciliation and 09 full demo/gate; no M3 work.


### IP-M2-07 — Private binding, durable webhook dan constrained callbacks

**Hasil:** authenticated/step-up binding intent memakai random 48-byte-hex command, hash-only persistence dan TTL 10 menit; `/start` hanya menerima private numeric user/chat identity yang sama. Candidate tidak aktif sebelum same OpsHub user mengonfirmasi ulang ID + checkbox di dashboard. Intent one-time/cancelled/expired, binding unik per bot/user/Telegram ID, personal revoke dan token identity rotation invalidation. Username tidak menjadi otoritas. Owner mempunyai explicit HTTPS setWebhook dengan secret reference, no drop-pending, dan live gate default false.

**Receiver/actions:** secret-header constant-time verification, bounded JSON/schema, durable unique bot/update-ID receipt + database queue insertion dalam transaksi sebelum 200, normalized/encrypted payload tanpa username/raw arbitrary text/raw start token. Async processing locked/dedup, current bot identity/member/project permissions; only acknowledge/claim/current-template callback, opaque reference 48 bytes dengan hashed scope/version/1-hour expiry dan single-use replay recheck. Contacted/verified/approval/remote changes tetap dashboard. `/start`/`help` generic; `/today`, `/incidents`, `/template <followup_ref>` scope-filtered, detail/current plaintext hanya ke private binding meskipun command/callback dari grup. Destination chat identity, full shared-project permission dan current entity state/409 diperiksa. Current source diperbarui saat template lama diminta; private sending memeriksa permission/binding kembali.

**Pengujian:** `TelegramReceiverTest|TelegramConfigurationTest|TelegramDeliveryTest` **16 passed / 195 assertions**, 52.29s; tambahan current-project/disabled-user coverage dan binding-state presentation: final `TelegramReceiverTest` **8 passed / 108 assertions**, 36.32s. Mencakup forged/malformed/dedup durable async receipt, stolen/group/expired/reused intent, wrong actor/ID, step-up/checkbox, revoke, opaque callback/unbound/cross-chat/stale/expired/viewer/revoked replay, current old-template reply, private group-command response dan Owner HTTPS fake setup tanpa stored secret. Initial PHPUnit load failure karena helper bernama `callback` menabrak final Assert method diperbaiki menjadi `callbackPayload` sebelum retry. TypeScript/Vite build **33.34s** passed; Pint/docs/diff integrity passed.

**Batas:** fake bot/update/transport only; **native implemented, live-unverified**. Tidak ada webhook/chat production dikonfigurasi atau token nyata digunakan. Group readership tetap Owner-confirmed scope; bot tidak mengaudit anggota grup. Dashboard confirmation wajib: stolen token sendiri tidak finalizes binding. Only environment secret resolver tersedia; managed production secrets integration/provisioning belum divalidasi. New Telegram pages HTTP/Inertia/build tested, browser dituntaskan pada gate 09. Operational retry/reconcile/digest/degradation masih 08, runtime migration masih pending.

**Commit/push:** scoped task commit/push biasa sesudah review; exact receipt pada task berikutnya. IP-M2-06 `77dc97ca9e9555891c47b75603b2ac598f24b280` pushed dan remote exact verified.

**Next step:** **IP-M2-08** — re-read/reconcile obsolete events, destination down-delivery recovery history, unknown/crash/bounded retries/jitter/429, critical priority + quiet/digest, integration finding/action dan scoped pending/failed/unknown dashboard. Dependency 06/07 domain/receiver dan M1 incident/renewal events tersedia. Fake tests tidak terblokir credential; live validation tetap unavailable sampai Owner setup/test. Lanjut 09 untuk full demo/TC gate, stop sebelum M3.

### Pemulihan session — IP-M2-08 in_progress

**Audit terverifikasi:** `main`/`origin/main`/remote exact `eab357f5d93ba97c00a4c91cbf0d98055f43651d`; IP-M2-07 committed/pushed, tidak ada outgoing commit. Working tree hanya perubahan parsial 08 (reconciliation/digest/retry/finding/dashboard/migration), belum di-commit dan belum diuji. Tidak ada PHP worker/server/test aktif; dua proses Node tidak terkait project/Playwright/Vite berdasarkan pemeriksaan command classification, tanpa listener, dipertahankan. Dependencies tidak diinstall ulang; migration/runtime/live gates tidak diaktifkan. Tool approval sebelumnya gagal karena kredit workspace, bukan keputusan unsafe; audit baru berhasil setelah instruksi pemulihan.

**Titik lanjut:** task 08 tetap aktif. Format PHP dijalankan dan frontend build mulai; hasil test 08 belum ada. Diagnosis source menemukan histori down harus mencakup episode recurrence saat ini, bukan episode lama pada ID incident yang sama; guard/pointer diperbaiki dan perlu regression. Tersisa test recovery/obsolete/recurrence, retry/429/uncertainty/lease, scoped digest/quiet/priority/finding/dashboard, format/build, checkpoint, scoped commit + push. Setelah 08 lanjut IP-M2-09 full demo/exit gate. Native tetap live-unverified; M3 belum dimulai.


### IP-M2-08 — Reconciliation, retry, digest dan degradation

**Hasil:** dispatcher terjadwal/durable queue dengan priority critical; sender me-recheck konfigurasi, recipient/current project access, current follow-up/template/incident sebelum I/O. Old down yang pulih superseded/recovered summary; recovery hanya setelah same-recipient down accepted dengan message ID pada episode recurrence saat ini. Current revisions mempertahankan sent history dan mengganti seluruh pending bundle bila panjang berubah. Coalesced current summaries mempertahankan 100 event/incident dan mencatat histori yang benar. Retry 429 retry-after + jitter/backoff, maksimal 5 attempts/15 menit; timeout/crash visible unknown, maksimal satu uncertain retry dan duplicate-possible flag. Lease fencing menolak response worker lama. Actual-chat budget dibagi oleh private destination/binding; bot/group/private caps tetap berlaku.

**Quiet/digest/failure:** warning/info tertunda ke daily unique timezone digest, critical/recovery-critical tetap immediate. Digest memakai current critical/overdue/renewal/verification state dan maksimal 10 item, menyertakan held quiet event IDs; pending digest dirender ulang sebelum send. Backup/maintenance coverage belum tersedia dan ditandai unsupported. 401 disables bot sampai Owner identity/test; 403 disables recipient sampai scope/config/test; structured integration findings/action, Owner step-up/versioned acknowledgement tidak berarti repaired. Explicit accepted test menjadi resolution proof. Webhook backlog terlihat sampai receipt diproses. Scoped delivery dashboard menampilkan pending/failed/unknown/fake/history tanpa body, secret/chat IDs atau private recipient lain. RuntimeHealth melaporkan configured/live-disabled/fake-tested/degraded, tanpa fabricated green. Composer dev worker sekarang membaca database critical/notification/probe/routine/backup queues.

**Pengujian:** final `TelegramReconciliationTest|TelegramDeliveryTest|TelegramReceiverTest|TelegramConfigurationTest`: **29 passed / 319 assertions**, 84.31s MySQL `_test`. Twelve reconciliation tests mencakup real M1 3-fail/2-pass recovery dan recurrence/two destinations, old/current source, 429/5xx/401/403, max-window/cap, timeout/late lease fencing, Owner repair proof/409, quiet/digest/current source, backlog/critical queue, shared actual private-chat budget/privacy, 100 actual fictitious incidents retained/current batch/history. First run gagal pada helper prefix `test` yang di-snake-case oleh Pint dan non-hydrated organization active default; diperbaiki dengan helper `deliveryFixture`/fresh fixture, tanpa perubahan asersi. Pint, strict Composer, TypeScript/Vite build dan docs/diff checks diperiksa sebelum commit.

**Batas:** fake transport only; native **implemented/live-unverified**, tidak mengaktifkan live connector/probe atau production Telegram. Sent adalah accepted provider result dengan ID, bukan read receipt. Unknown tetap terlihat karena Telegram tidak menyediakan idempotent send receipt; retry bisa duplicate. Database queue implemented/tested; Redis durability/production worker/watchdog/secrets provisioning belum divalidasi. Runtime migrations masih pending; task 09 melakukan guarded normal migration dan full gate/demo/browser. Backup/maintenance digest detail unsupported sampai milestone terkait.

**Commit/push:** scoped task commit + normal push setelah review; exact receipt dicatat dalam task 09. IP-M2-07 `eab357f5d93ba97c00a4c91cbf0d98055f43651d` pushed dan remote exact verified saat pemulihan.

**Next step:** **IP-M2-09** — full fake H-14→TPL-01→Copy→actual manual contact→waiting/client-confirmed→provider evidence/future expiry→verified/new cycle; security/binding/replay/long Unicode/retry storm, TC-11–25/38 dan allocated TC-03/05/10 evidence, full mandatory checks. Dependency 01–08 tersedia. Live bot tidak diperlukan untuk fake/sandbox gate; production tetap live-unverified. Stop sesudah gate M2; jangan masuk M3.

**Runbook M2 lokal:** scheduler `php artisan schedule:work`; consumer `php artisan queue:work database --queue=critical,notification,probe,routine,backup --tries=1` (atau `composer dev` untuk worker/web/Vite). `php artisan opshub:telegram:dispatch` hanya reconcile/enqueue, bukan bukti sent. Konfigurasi Owner + identity test + explicit destination scope/test wajib; live gates default false. Periksa dashboard delivery/finding, jangan menganggap outbox materialized/queue insertion sebagai provider acceptance. Pada unknown periksa chat/event ID sebelum manual resend. Jangan menjalankan queue live tanpa target/config terotorisasi.

### IP-M2-09 — Riwayat diagnosis gate (sebelum validasi final)

**Commit/push task 08:** `c765de0cf5f08ce7b130da3cfd598295d610b748`, `(feat) reconcile Telegram delivery with bounded retries and current digests`; normal push `origin/main` berhasil dan remote exact diverifikasi. Build 08 final 33.35s/Pint/Composer/docs passed. Working tree 09 berisi demo HTTP, race worker/test, mandatory-variable coverage dan mobile notification link; belum committed.

**Bukti sementara:** `RenewalTelegramDemoTest|TelegramConcurrencyTest` **2 passed / 78 assertions**, 67.89s. Full H-14/TPL-01/separate Telegram header/body/private binding/dashboard confirmation/contact/wait/client-confirmed/provider proof/TPL-10/new cycle/replay/forged webhook diuji. Race dua proses MySQL membuktikan satu active threshold/follow-up/outbox, satu attempt sent, satu verify dan satu 409/two cycles dengan satu active. Initial demo helper memilih tombol URL alih-alih callback dan expected replay code tidak sesuai `CALLBACK_ALREADY_USED`; diperbaiki berdasarkan protocol/source. Initial race count memasukkan dua historical skipped reminder; assertions kini membuktikan tiga persisted rows (dua skipped + satu pending), satu outbox, tanpa historical flood.

**Runtime:** read-only preflight MySQL 8.4.11 `solveit_opshub`, users/org/outbox/service/incident 0, dua live gates false. Keenam migration M2 kemudian applied normal `php artisan migrate --force`; `composer db:check` passed. Runtime tidak di-reset; `_test` tetap terpisah. Full `composer test` sedang berjalan, browser belum dimulai.

**CI/blocker sementara:** exact task 08 run [37213005803](https://github.com/solveit-id/solveit_opshub/actions/runs/37213005803) failed di application tests, build passed/Pint skipped; public job-log endpoint 403 dan annotations hanya exit code 2. Automatic approval review menolak penggunaan configured Git credential untuk API log (credential probing); action tidak dieksekusi, izin baca log privat diminta. Diagnosis lokal/full suite dan pekerjaan independen tetap berjalan. Status M2 belum ready; next step tetap IP-M2-09: selesaikan full gate/browser dan diagnosis CI, commit/push scoped tanpa masuk M3.


### IP-M2-09 — Hasil final dan exit gate M2

**Hasil:** demo HTTP lengkap H-14 → ready TPL-01 → pesan internal terpisah/plaintext client → actual manual contact → waiting/client-confirmed → reviewed provider evidence + expiry future/later → verified/new cycle/TPL-10. Client confirmation tetap unresolved/payment unknown; historic sent body immutable. Test dua proses MySQL nyata membuktikan satu reminder aktif/outbox/follow-up, satu materialization, satu sent attempt, dan verify berkonflik 409 dengan tepat satu active cycle. Mandatory variable pada setiap definisi TPL-01…10 diuji satu per satu. Browser gate memperbaiki tiga duplicate main landmarks dan menambahkan akses Notification delivery pada navigasi mobile.

**Pemulihan CI:** Owner mengizinkan membaca log CI. Pembatasan akses sebelumnya telah terselesaikan; log exact task 08 menunjukkan fixture `RenewalModelTest` memakai `template_draft_id=1` tanpa draft nyata setelah FK task 04 ditambahkan. Full suite lokal mereproduksi error yang sama (1 failed/131 passed). Fixture kini membuat ready draft melalui `DraftGenerator` dan menggunakan ID/version/body aktual; invariant FK dan append-only tetap dipertahankan. Tidak ada test dinonaktifkan atau acceptance dikurangi.

**Pengujian final:** full `php artisan test` **132 passed / 1142 assertions**, 167.35s; build TypeScript/Vite melalui `composer test` passed sebelum diagnosis FK, kemudian build final sesudah perubahan landmark passed (1025 modules). Targeted demo/race **2 passed / 78 assertions**, 67.89s; `RenewalModelTest` ulang **6 passed / 26 assertions**, 31.31s, sekaligus cleanup disposable QA DB. Pint, Composer strict, diff/link/reference/acceptance integrity diperiksa sebelum commit; receipt mencatat hasil final dan CI exact.

| Gate/TC | Bukti actual dalam suite penuh dan browser |
|---|---|
| TC-11/12/17 dan allocated TC-03 | `RenewalSchedulerTest`, `RenewalTelegramDemoTest`, `TelegramConcurrencyTest`: kalender/timezone, highest applicable threshold, skipped history, shared canonical reminder, bounded escalation/snooze dan race uniqueness. |
| TC-13 | `ClientTemplateTest`, `RenewalDashboardTest` dan browser: unknown expiry ready TPL-04 jika data cukup; missing data blocked, tanpa tanggal rekaan. |
| TC-14 | Demo HTTP/delivery renderer: internal header terpisah dari body client plaintext; actual fake destination internal, tanpa client auto-send. |
| TC-15 | Browser nyata Copy/clipboard tetap open/0 attempts; form Mark contacted menghasilkan satu actual auditable attempt; `FollowupWorkflowTest` menjaga actor/contact/time/channel/draft version. |
| TC-16/38 | Workflow/demo/race: confirmation/payment tidak verify, reviewed future/later expiry wajib, stale version 409, satu active cycle, cancellation old reminders dan immutable history. |
| TC-18 | `ClientTemplateTest`/receiver: version/publication, stale/superseded draft dan callback current-source/replay guards. |
| TC-19 | Template/delivery tests: optional clauses, mandatory variables semua 10 template, secret/markup rejection, Unicode dan bounded UTF-16 segmentation. |
| TC-20–22 | `TelegramReceiverTest`/HTTP demo: private one-time intent + same-user dashboard confirmation, forged-secret rejection, durable update dedup, scoped/RBAC/constrained callback dan replay refusal. |
| TC-23 | `TelegramReconciliationTest`/delivery tests: bounded 429/5xx retry, 401/403 disable, timeout/crash uncertainty, lease fencing, explicit successful repair test dan failed-history retention. Browser acknowledge tidak mengubah degraded menjadi healthy. |
| TC-24 dan allocated TC-10 | Reconciliation/delivery storm 100 incidents → satu coalesced destination delivery, current summary/history, rate caps, critical priority, quiet hours dan digest. |
| TC-25 dan allocated TC-05 | Deterministic M1 incident/delivery history: per-destination/per-episode recovery hanya setelah down sent, unsent destination menerima recovered-summary, obsolete down tidak dikirim ulang. |

**Browser nyata:** Chrome di loopback, backend MySQL `_test` dengan adapter fake eksplisit/guarded. Copy clipboard, actual manual contact, waiting/client-confirmed, verified resolution/TPL-10, Owner identity/test pending→fake accepted, private binding candidate→typed-ID/dashboard confirmation, degraded 403 finding→acknowledged (tetap degraded) berhasil melalui API/Inertia aktual. Enam halaman pada desktop 1280×844 dan mobile 390×844: 12 pengukuran, tepat satu main/halaman, tanpa horizontal overflow; console 0 errors/0 warnings. Screenshot lokal ignored, bukan evidence Telegram live.

**Runtime/cleanup:** keenam migration M2 applied normal; MySQL 8.4.11 runtime tetap terpisah, 17 migration applied, users/org/outbox/service/incident 0 setelah QA, kedua live gates false. Browser ditutup dan PHP QA server dihentikan (port 8012 tidak listening). Manifest/random login helper dihapus. Guarded `_test` cleanup check: users/org/service/incident/outbox/delivery/binding/receipt/jobs/session semuanya 0. Tidak ada perubahan `.env`, runtime reset, atau worker/scheduler live.

**Runbook demo ulang:** `php artisan test --filter=RenewalTelegramDemoTest` untuk fake HTTP vertical flow; `php artisan test --filter=TelegramConcurrencyTest` untuk race MySQL nyata. Keduanya memakai guard testing/MySQL/schema `_test`/DB_URL kosong/live disabled, dan destructive migration hanya mengenai disposable test schema. Build manifest harus tersedia sebelum HTTP tests. Browser QA memerlukan fixture fictitious + fake transport eksplisit dan cleanup setelahnya; tidak menggunakan fallback production.

**Gate/status:** TC-11–25/38 dan allocated TC-03/05/10 memenuhi gate M2 lokal/fake; seluruh task 01–09 implemented/tested, status **MILESTONE_READY**. Native Telegram adapter implemented tetapi **live-unverified**: tidak ada bot/token/destination/webhook production dikonfigurasi atau diuji. Backup/maintenance digest yang membutuhkan M3 tetap unsupported pada fase ini; Redis durability, independent watchdog/provisioning dan Internal v1 mengikuti M3–M5. Tidak ada waiver full TC/PRD.

**Commit/push:** task 09 `cd85988b7f9e435c12b07bfe70905fadc4f777b0`, `(test) verify M2 renewal loop and MySQL delivery concurrency`, sudah normal-pushed dari `main` ke `origin/main`; `git ls-remote` exact dan working tree bersih diverifikasi setelah push. Outgoing hanya satu scoped task commit, 12 file; cached names/stat/check dan isi diperiksa, tanpa secret/QA artifact/attribution trailer. Task 08 exact receipt `c765de0cf5f08ce7b130da3cfd598295d610b748` sudah pushed; failure CI lama diperbaiki oleh fixture task 09. Pint seluruh PHP, Composer strict, `git diff --check`/cached check dan validasi 52 task outputs/6 exit gates/6 acceptance blocks/11 Markdown/96 links-anchor/PRD unchanged passed. CI exact task 09 [37216248860](https://github.com/solveit-id/solveit_opshub/actions/runs/37216248860) **completed/success**: frontend build, MySQL application tests dan Pint masing-masing passed; `head_sha` cocok `cd85988b7f9e435c12b07bfe70905fadc4f777b0`. Receipt dokumentasi ini mencatat hasil setelah push/CI dan tidak mengubah implementasi. Tidak ada blocker M2 lokal/fake; batas live tetap berlaku.

**Next step konkret:** **IP-M3-01** — definisikan typed connector contract/capability assessment dan persistence, memakai canonical account/resource scope, auth/security/outbox M0–M2. Dependency engineering lokal tersedia; pengembangan M3 menunggu instruksi lanjutan sesuai stop boundary. Live connector memerlukan konfigurasi/target dan otorisasi terpisah. Tidak ada task M3 yang dimulai.

## 11. Progres M3 — Connector dan verified backup

**Otorisasi/dependency aktual:** Owner memberi instruksi melanjutkan milestone pertama yang belum selesai, commit/push per task dan stop di gate/blocker M3. Owner kemudian mengonfirmasi belum tersedia target sandbox non-client yang diotorisasi untuk cPanel/SFTP, storage independen atau restore drill terisolasi. Kontrak/fake engineering dapat dilakukan tanpa akses tersebut; sandbox capability, integrity/restore dan seluruh exit gate M3 tidak dapat diklaim passed. Tidak ada otorisasi target production atau perubahan live gates.

### IP-M3-01 — Typed connector contract dan capability persistence

**Hasil:** interface config validation/read-only discovery/read observation/backup request/reconcile, typed capability/status/reason, UTC observation time/retry policy dan evidence allowlist. Unsupported selalu non-success; raw provider message/body/path/token tidak dipersist. Immutable config hanya menerima secret reference, endpoint tanpa credential/query, serta root/fingerprint SFTP eksplisit. Contract fake hanya untuk testing dan tidak menjadi fallback production. Fixture notification/public probe M0 memakai DTO terpisah agar status accepted/retrying/pass tidak menjadi capability supported.

**Persistence/API:** satu connector per canonical account/kind/org; configuration version dan lifecycle terpisah dari capability serta validation state. Assessment append-only, stale-version 409, late evidence tidak mengembalikan state auth-failed menjadi connected. Writes tetap paused; fake/provider provenance tidak bisa membuat validated_sandbox. Auth failure mencatat connector.failed secara atomik tanpa membuat observation/website outage. Route GET/POST `/api/v1/organizations/{organization}/connectors`: organization/project/all-account scope, permission connector.manage eksplisit untuk non-Owner dan recent step-up mutation; configuration tersembunyi dari response. Reference rotation harus memakai test-then-switch workflow berikutnya.

**Pengujian:** `ConnectorContractTest|FoundationReliabilityTest|RegistryMetadataTest` **18 passed / 118 assertions**, 28.93s; full `php artisan test` **138 passed / 1210 assertions**, 256.28s termasuk dua race MySQL existing. Pint seluruh PHP passed, Composer strict valid; validator docs **52 task outputs / 6 exit gates / 6 acceptance blocks / 11 Markdown / 97 links-anchor**, PRD unchanged. Initial targeted run menemukan shared legacy DTO Telegram dan organization instance yang belum refresh DB default; dipisahkan DTO fixture serta refresh organization, kemudian regression pass tanpa mengurangi asersi. MySQL runtime check **8.4.11 / solveit_opshub** passed; tests memakai guard MySQL `_test`. Tidak ada runtime migration, browser, build frontend baru, provider request atau target sandbox yang diklaim pada task ini.

**Commit/push:** `87fded9945eee1b3c570d58c62ea5f07d7ca085b`, `(feat) persist typed scoped connector capability assessments`, normal push `main` → `origin/main` berhasil, exact remote hash dan working tree bersih diverifikasi. CI exact [37218281699](https://github.com/solveit-id/solveit_opshub/actions/runs/37218281699) **completed/success**, `head_sha` cocok. Preflight HEAD lokal/remote `9c6a65832fd927f6e4311c9b0c39b5df68324c72`, working tree awal bersih. Scoped commit 24 file; tidak ada secret, dependency installed, QA artifact atau attribution trailer.

**Next step:** **IP-M3-02**, adapter cPanel read/discovery melalui worker, TLS/SSRF guard, sanitized failure dan test-then-switch reference rotation; dependency IP-M3-01 dan security/canonical account M0–M2 tersedia. Sandbox provider/token/capability evidence belum tersedia, sehingga native protocol tetap live-unverified dan gate M3 blocked. IP-M3-03 merupakan jalur read SFTP independen; backup source/independent-storage/restore tetap mengikuti dependency dan tidak ditandai selesai dari fake.

### IP-M3-02 — cPanel read/discovery dan atomic reference rotation

**Hasil:** adapter UAPI read-only memakai allowlist `Features/list_features` dan `Quota/get_quota_info`, worker-only secret resolution, HTTPS/TLS chain+hostname verification, IP pinning/actual-peer guard, proxy/redirect disabled, batas total 10s/read, body 1 MiB/header 64 KiB. Port cPanel 443/2083 tidak memperluas global public-probe ports. Provider error/body dibuang dari normalized evidence; quota null/unlimited/zero tidak menjadi percentage palsu. Full backup trigger/retrieval masih unknown; full restore/database backup unsupported, tanpa write selama discovery.

**Workflow:** POST `/api/v1/organizations/{organization}/connectors/{connector}/test`, candidate reference opsional untuk rotation; session/CSRF/recent step-up/account-scope/explicit connector permission/idempotency/version/cooldown. Run dan job database atomik; satu active run/account connector, lease fencing, worker recheck actor/org/all-project scope dan authorization observe, retry lease hanya explicit replay dengan cap tiga. Candidate yang gagal/denied tidak mengubah active reference atau history; berhasil → switch/version/audit, lalu operator wajib revoke reference lama melalui provider. Writes tetap paused; fake tidak menjadi validated_sandbox. 401/403 menghasilkan connector auth issue + internal notification intent melalui consumer/destination guard M2; tidak membuat uptime observation/website incident. Delivery belum diklaim sent.

**Evidence lokal:** `CpanelReadAdapterTest` **5 passed / 70 assertions**, 1.62s; first regression contract/worker/delivery **22 passed / 261 assertions**, 44.08s; final `ConnectorWorkerTest` sesudah penambahan lease cap **6 passed / 54 assertions**, 46.93s. Connector contract dan 12 reconciliation cases juga passed dalam targeted run; satu tambahan lease fixture sempat gagal karena stale Eloquent instance, lalu diperbaiki dengan refresh, tanpa perubahan asersi. Pint seluruh PHP, Composer strict dan diff check passed. Tidak ada suite penuh/build/browser baru pada task 02; full task 01 tetap evidence source sebelumnya, bukan exact final task 02.

**Runtime/batas live:** read-only preflight MySQL `solveit_opshub`, users/org/account/outbox/jobs 0, kedua live gates false. Dua migration connector applied normal, `composer db:check` MySQL 8.4.11 passed dan counts/gates tidak berubah. Tidak ada runtime reset, connector/Telegram provider request atau perubahan `.env`; native cPanel **live-unverified**. Resolver env hanya untuk development; production membutuhkan binding secrets manager (M5), tidak fallback env atau fake. Sandbox capability/native TLS/peer/quota evidence tetap requirement gate M3.

**Otorisasi/review:** automatic approval review sempat menolak kode queued-test/worker/rotation karena tidak ada target diotorisasi. Owner kemudian secara eksplisit mengotorisasi pengembangan/test lokal/fake M3-02 dengan live gates false dan tanpa I/O provider nyata; patch baru diterapkan sesudah persetujuan itu. Usulan perubahan AGENTS stop snapshot ditolak dan tidak diterapkan; instruksi sesi menentukan scope M3, gate/PRD tetap utuh. Terminal PowerShell mengalami helper/Access denied; cmd.exe memulihkan execution, tanpa perubahan permission/global config.

**Referensi protocol:** [UAPI introduction](https://api.docs.cpanel.net/cpanel/introduction), [official token authentication](https://docs.cpanel.net/knowledge-base/security/how-to-use-cpanel-api-tokens/), [quota schema](https://api.docs.cpanel.net/specifications/cpanel.openapi/disk-quotas/quota-get_quota_info). Ini acuan implementasi, bukan bukti provider live.

**Commit/push:** `0dc6638aa10eefe71ef189c74fd20cde5fcd0c02`, `(feat) add guarded cPanel discovery and tested reference rotation`, normal push `main` → `origin/main` berhasil dan exact remote hash diverifikasi. Scoped commit 23 file; tanpa secret/QA artifact/attribution trailer. CI exact [37219916656](https://github.com/solveit-id/solveit_opshub/actions/runs/37219916656) **completed/success**, `head_sha` cocok; hasil CI merupakan bukti exact commit, terpisah dari targeted local tests di atas.

**Next step:** **IP-M3-03**, SFTP read-only adapter dengan fingerprint pinning sebelum auth, root/path/symlink guards dan bounded listing/download. Dependency kontrak/security/canonical scope IP-M3-01/02 tersedia. Sandbox SFTP/fingerprint/root terotorisasi belum tersedia; fake test tidak menggantikan protocol validation gate. Source backup/storage independen/restore drill belum tersedia untuk gate task 05–10; tetap jangan masuk M4.

### IP-M3-03 — SFTP read-only dengan host-key/root guard

**Hasil:** adapter/session API hanya expose handshake/auth/realpath/lstat/non-recursive listing/bounded read/close; tanpa write/delete/shell. Worker binding memakai native factory, bukan fake fallback. Live gate false/console guard berhenti sebelum DNS/secret/network. Native koneksi memakai public-IP validation/pinned socket/actual-peer check, host-key SHA256 dari verified SSH handshake dicocokkan sebelum resolve credential/login; tidak auto-trust. Allowed root harus canonical dan directory; seluruh component lstat/realpath harus tetap di root, symlink ditolak termasuk root. Listing default 1000, maksimum 5000; chunk maksimal 64 KiB, byte/time limit, ukuran/mtime sebelum dan sesudah read serta checksum manifest; partial/changed/path race adalah failure, consumer wajib membuang sink parsial. Path/content tidak masuk normalized evidence. File backup belum diklaim terverifikasi; database/full restore unsupported.

**Pengujian:** `SftpReadAdapterTest` **7 passed / 62 assertions**, 4.07s; fingerprint sebelum auth, native secret resolver tidak dipanggil saat mismatch (mocked library tanpa network), traversal/root/symlink/malicious listing, chunk/checksum, partial/changed file, symlink race sebelum consumer, timeout dan empty file, provenance serta gate false. `ConnectorWorkerTest` **7 passed / 64 assertions**, 48.85s; queued scope/version/lease/idempotency/rotation/cPanel regressions dan SFTP assessments, tanpa uptime observation/incident. Initial worker test salah memakai table `incident_episodes`; schema menunjukkan canonical `incidents`, referensi diperbaiki dan asersi deny dipertahankan. Pint seluruh PHP passed, Composer strict valid; dependency phpseclib **4.0.1**/constant-time encoding **3.1.3** locked, 2 installs/0 existing updates, audit tidak menemukan advisory. Source API library locked diperiksa; [SSH connection](https://phpseclib.com/docs/ssh2/connect) dan [SFTP](https://phpseclib.com/docs/ssh2/sftp) adalah acuan protocol.

**Otorisasi/batas:** automatic approval review sebelumnya menolak patch native M3-03 karena persetujuan tambahan baru M3-02. Owner kemudian secara eksplisit mengotorisasi **seluruh kode dan pengujian lokal/fake dalam M3**; patch diterapkan setelah itu. Dependency sempat dihapus saat scope pending dan dipasang kembali setelah persetujuan, tanpa update package existing; warning uninstall hook tidak menghentikan cleanup. Tidak ada provider I/O, perubahan `.env`, runtime migration atau browser/build/suite penuh baru. Live gates tetap false. Native handshake/permission/read-only credential/jail/server behaviour **live-unverified**; high-level SFTP pre/post checks tidak membuktikan atomic open terhadap server yang berkompromi. Sandbox dengan read-only credential/root terotorisasi tetap wajib; fake tidak membuat validated_sandbox atau mengaktifkan writes.

**Commit/push:** `5d05369898544e479924fb053b11e7b83e79e49d`, `(feat) add pinned read-only SFTP adapter with bounded transfers`, normal push `main` → `origin/main` berhasil, remote SHA exact dan working tree bersih terverifikasi. Scoped commit 16 file, tanpa secret/installed dependency/QA artifact/attribution trailer. CI exact [37221962704](https://github.com/solveit-id/solveit_opshub/actions/runs/37221962704) **completed/success**, `head_sha` cocok.

**Next step konkret:** **IP-M3-04** — model backup policy, required scopes/schedule/RPO/retention/destination/verification, safe preflight authorization/capacity/capability/account lease/reconcile/kill switch. Dependency M0–M2 dan IP-M3-01–03 tersedia untuk local/fake; capability/provider/storage evidence belum tersedia untuk aktivasi nyata. Gate M3 tetap blocked pada sandbox/storage/restore; jangan masuk M4.

### IP-M3-04 — Approved backup policies dan safe fenced preflight

**Hasil:** satu policy canonical per account, Owner-only approval/config version/audit; files/database/full-account required scopes, canonical include/exclude, IANA daily schedule/jitter/RPO, independent destination reference, daily 7/weekly 4/monthly 3 retention, verification requirement dan byte/bandwidth/time limits. Full cPanel account tidak menerima filter project-only yang tidak dapat dipenuhi provider. Policy/run response menyembunyikan path snapshot; UTC timestamps dan snapshot approved config/version/impacted projects tersimpan pada run.

**Preflight/lease:** session+CSRF/recent step-up API, current dan historical all-account project scope, backup.run dan management authorization `backup`, enabled policy/current connector version/fresh typed capability/provenance, source/storage estimate/quota/freshness/independence, byte limit serta write kill switch. Native path membutuhkan live gate, writes enabled dan validated_sandbox; semua masih disabled/not configured. Capacity provider production eksplisit unavailable; test-only fixture bukan fallback. Cadangan headroom max(64 MiB, 20% estimate); organization lock menyerialkan reservasi destination antar-account. Unknown quota/estimate/independence memblokir, tidak dianggap cukup; SFTP DB gap tetap tercatat. Queue/run atomik pada database/backup queue, idempotency digest dan generated unique active-account fence; preflight worker berhenti pada `ready`, **bukan backup success**. Lease 90s/worker 60s; expired lease dan kill switch mempertahankan fence/reservation, menandai reconcile_required, tanpa auto retry write atau klaim remote cancelled. Attempt trail dan late-worker fencing tersimpan. Driver source/reconcile dan storage/transfer menyusul pada task 05–07.

**Pengujian:** `BackupPreflightTest` **8 passed / 70 assertions**, 58.46s: Owner/step-up/version/path API termasuk default root-relative, atomic rollback/idempotency, canonical account lock, DB gap, source/storage near-full/unknown/stale/non-independent, revoked authorization/capability/version, lease/reconcile, midflight kill switch/read scope, dua account tidak overreserve satu destination, native gate false tanpa capacity I/O. Diagnosis dua kegagalan awal: posisi fixture Telegram berisi subscription, lalu HTTP middleware empty-string→null; buat user/membership Operator nyata dan normalisasi root-relative nullable ke empty string, asersi tetap utuh. Pint seluruh PHP, Composer strict dan diff check passed. Tidak ada runtime migration, provider request, browser/build atau suite penuh baru pada task 04; MySQL `_test` guard tetap berlaku.

**Commit/push:** `79bfb5ead7dc8effa62c5976fe69b865786ec9ce`, `(feat) model approved backup policies and fenced quota preflight`, normal push `main` → `origin/main` berhasil dan remote SHA exact diverifikasi. Scoped commit 17 file, tanpa secret/QA artifact/installed dependency/attribution trailer. CI exact [37223584666](https://github.com/solveit-id/solveit_opshub/actions/runs/37223584666) **completed/success**, `head_sha` cocok.

**Next step konkret:** **IP-M3-05** — cPanel full-account request dengan capability teruji, accepted→awaiting_source, reconciliation/stability/ownership/deadline tanpa mengarang status endpoint, retrieval private independent sink. Dependency policy/preflight/fence IP-M3-04 dan cPanel/security IP-M3-01/02 tersedia lokal/fake; native capability/retrieval sandbox dan storage evidence belum tersedia. Jalur streaming SFTP IP-M3-06 dapat dilanjutkan independen; storage/integrity task 07 tetap wajib sebelum success, gate sandbox/restore tetap blocked.

### IP-M3-05 — cPanel request, reconcile dan private source retrieval

**Hasil lokal/fake:** persisted source intent sebelum request, sanitized PID-only receipt dan request trail; API accepted menjadi awaiting_source, tidak sukses. Timeout/crash/unknown mempertahankan canonical account fence dan melakukan read reconciliation; tidak memicu request kedua sampai idle_proven, lalu wajib preflight baru. Deadline dan request cap tiga, lease/attempt fencing serta global concurrency limit default dua. Artifact wajib terasosiasi dengan run/PID, completed dan dua metadata sample identik selama 30 detik; bounded private streaming memeriksa byte/hash/provenance dan controls saat transfer. Receipt hanya stored_unverified/verifying, belum independent/integrity success. Midflight kill switch, revoked authorization/config dan late worker tidak mempromosikan hasil. Auth denial pauses connector dan mencatat sanitized internal outbox tanpa website outage. Step-up scoped reconcile API; tidak ada remote cancellation palsu.

**Batas native:** official [fullbackup_to_homedir](https://api.docs.cpanel.net/specifications/cpanel.openapi/backup/backup-fullbackup_to_homedir) diimplementasi memakai fixed GET write allowlist `homedir=include`, tanpa provider email, tetap worker/live/lease permit dan TLS/SSRF guards. OpenAPI resmi hanya menjamin accepted PID string; `list_backups` tidak memberi asosiasi PID/completion/path. Bridge artifact/completion native eksplisit **not configured**, sehingga request nyata diblokir sebelum API, tanpa polling endpoint rekaan atau fallback fake. Source fixture memakai data fictitious, bukan gzip/database backup asli. Sink task ini ephemeral private test-only, bukan encryption/independent-provider/restore evidence; implementasi storage/verification task 07 tetap wajib. Tidak ada live connection/gate change/runtime migration/browser/build baru.

**Pengujian:** `CpanelBackupFlowTest` **7 passed / 56 assertions**, 46.81s; `CpanelBackupProtocolTest` **2 passed / 61 assertions**, 3.19s; refactored `CpanelReadAdapterTest` **5 passed / 70 assertions**, 1.66s. Full `php artisan test` **174 passed / 1593 assertions**, 333.45s, termasuk dua MySQL process races existing, authorization/registry/connector/preflight dan Telegram regressions. Deadline fixture bulk update awal melewati UTC cast; diperbaiki ke UTC. Pemeriksaan menemukan ManagementAuthorization belum memakai UTC model cast dan cutoff memakai timezone display; keduanya kini UTC, dengan regression izin 30 menit, raw DB UTC dan expiry deny. Runtime preflight users/org/accounts/outbox/jobs 0, live gates false; tidak mengonversi/reset data. Fixture bulk authorization existing diselaraskan UTC, asersi tidak diturunkan. Pint seluruh PHP, Composer strict, diff dan canonical docs validator passed (52 task outputs, 6 exit gates/acceptance blocks, PRD unchanged).

**Commit/push:** `74aa617be8e0a20536eaafc3fa8f909e5bfc0239`, `(feat) fence cPanel backup requests and reconcile source before retrieval`, normal push `main` → `origin/main` berhasil, exact remote hash dan clean worktree diverifikasi sebelum task 06. Scoped commit 37 file, tanpa secret/QA artifact/installed dependency/attribution trailer. CI exact [37253828022](https://github.com/solveit-id/solveit_opshub/actions/runs/37253828022) **completed/success**, `head_sha` cocok.

**Next step konkret:** **IP-M3-06**, bounded SFTP file-bundle streaming dengan manifest private, exclusion/missing/changed reports, bandwidth/global concurrency limits dan DB gap. Dependency read-only/root guards task 03, policy/preflight task 04 dan shared lease/sink task 05 tersedia lokal. Native SFTP sandbox, independent storage dan restore target belum tersedia; lanjutkan task lokal/fake 06–09 yang independen, stop di gate M3 tanpa masuk M4.

### IP-M3-06 — Bounded SFTP file bundle dan private manifest

**Hasil:** worker database/backup queue, satu canonical account fence dan global heavy-work concurrency limit; cooldown mencegah replay menumpuk job saat kapasitas global penuh. Framed file bundle streaming (`opshub-files-v1`), maksimum chunk 64 KiB, satu budget total byte/bandwidth/monotonic deadline; limit inventory 5000 nodes/depth 64. Relative root-index/path, size/mtime/count/hash, directory metadata, policy exclusions dan missing/unreadable/changed reports tersimpan privat, hidden dari API/Telegram. Approved exclusions tidak mengikuti/stat excluded symlink; included symlink/traversal tetap ditolak. File metadata dicek sebelum/selama/sesudah read, directory mtime dicek sebelum bundle selesai. Missing/changed/denied menghasilkan partial/fence dan sanitized failure, tidak complete diam-diam. SFTP tidak memberi atomic application/database snapshot; hanya files, DB/full-account gaps tetap tercatat. Transfer berhasil berakhir stored_unverified/verifying, tidak succeeded.

**Security/recovery:** fresh authorization/current+historical account scope, policy/connector/capability/version/provenance, capacity age, byte reservation dan kill switch dicek sebelum read serta selama/final transfer. Crash/expired lease/partial tidak auto restart; fence tetap sampai private storage reconciliation task 07. Auth denial pause connector; bukan website outage. Native binding tetap live gate false, tanpa fake fallback. Tidak ada provider I/O, runtime migration, browser/build atau full-suite baru pada task 06; source/storage sandbox belum tersedia.

**Pengujian:** `SftpFileBundleTest` **4 passed / 34 assertions**, 6.38s; generated fictitious payload **1,073,807,361 bytes** (>1 GiB), bundle 1,073,807,525 bytes, maximum chunk 65,536, peak PHP allocator growth 0 bytes dalam process fixture (bukan absolute process memory/provider benchmark), cumulative fake-clock pacing 10.240000944s pada 100 MiB/s. Counting sink menguji producer tanpa buffering/artifact besar di web; durable encrypted-object measurement menyusul task 07/10. `SftpBackupFlowTest` **5 passed / 41 assertions**, 42.05s; DB gap/private response, partial/permissions/no outage, auth expiry/stale capacity, midflight pause/crash fencing, native false gate/global limit/cooldown. `SftpReadAdapterTest` **7 passed / 62 assertions**, 3.99s. Fixture awal secret-reference prefix invalid diperbaiki sesuai allowlist; tiga lokasi Eloquent stale fixture di-refresh untuk reset skenario independen, asersi tetap utuh. `BackupPreflightTest` **8 passed / 70 assertions**, 46.01s; queue atomicity/capacity/scope/kill regressions passed. Pint seluruh PHP, Composer strict, diff dan docs validator passed (52 task outputs, 6 exit gates/acceptance blocks, PRD unchanged).

**Commit/push:** `8a3778281e3776101d368b4acf0b364b0bcd387b`, `(feat) stream scoped SFTP file bundles with bounded budgets and private manifests`, normal push `main` → `origin/main` berhasil, remote SHA exact dan clean worktree diverifikasi sebelum task 07. Scoped commit 16 file, tanpa secret/QA artifacts/installed dependency/attribution trailer. CI exact [37255120598](https://github.com/solveit-id/solveit_opshub/actions/runs/37255120598) **completed/success**, `head_sha` cocok.

**Next step konkret:** **IP-M3-07** — private immutable object version, authenticated streaming encryption/key reference, private manifest dan transport/content verification, per-required-scope last-known-good tanpa menutupi DB gap. Dependency driver/sink/lease task 05–06 dan safe policy/preflight task 04 tersedia lokal/fake. Backend independen yang diotorisasi/key management production belum tersedia; jangan menyebut fake private storage sebagai failure-domain provider evidence. Gate M3 tetap blocked, M4 belum dimulai.

### IP-M3-07 — Private object encryption dan verification

**Hasil:** private object intent/version/key reference, streaming libsodium secretstream envelope dengan authenticated final private manifest, ciphertext/plaintext SHA-256 dan context org/account/run/artifact/version. Incremental file-bundle structure/path/file checksum inspection tanpa plaintext artifact spool. Verification records, scoped UTC last-good per required scope; files tidak menutupi database gap. Opaque cPanel archive hanya transport/full-account coverage; DB/file semantic content verification belum supported. Source dan storage/integrity state tetap terpisah. Scoped step-up queued reconciliation dapat mengadopsi sealed immutable object setelah crash hanya melalui authenticated envelope/owned identity, tanpa upload kedua; SFTP tanpa object/source intent dapat ditutup sebagai failed, tanpa success/restart. Pure read reconciliation tetap tersedia saat write kill switch aktif, late worker tidak mempromosikan. Byte/deadline limits berlaku pada encrypted read; actor/scope/observe authorization dicek ulang selama/final read. Permission revoke adalah unknown, bukan corruption. Missing key/storage tetap unknown; tamper/decryption/manifest mismatch failed dan tidak menggeser last-good.

**Pengujian:** `BackupVerificationTest` **10 passed / 92 assertions**, 64.44s; scoped last-good/DB gap, tamper/checksum/wrong key, independent/private/transport guards, missing key/expiry, pause/late worker, sealed-object crash adoption, step-up/cooldown/no object intent, files-only approved success, opaque cPanel partial dan mid-read actor disable. Initial denial fixture mengharapkan 423, sedangkan middleware canonical mengembalikan 403; expected deny diselaraskan tanpa mengurangi security gate. `SodiumEncryptedStreamTest` final **4 passed / 22 assertions**, 14.26s, wrong context/key/tamper/reorder/truncate/trailing/byte/time. Generated payload **1,073,807,361 bytes**, encrypted temp object **1,074,168,121 bytes**, max chunk 65,536, PHP allocator growth 0 bytes pada fixture process; hanya encrypted payload di temp disk, plaintext diproses chunked. Asersi primitive write yang semula berulang per chunk diringkas menjadi aggregate byte/hash/bound checks. `SftpBundleInspectionTest` **2 passed / 8 assertions**, 2.28s; arbitrary chunk split, MySQL JSON key order tanpa mengubah typed/list identity, missing required entry/type/digest/traversal/truncation/trailing/limit. Kedua source drivers **12 passed / 97 assertions**, 56.26s. Pint seluruh PHP, Composer strict, diff dan docs validator passed (52 task outputs, 6 exit gates/acceptance blocks, 11 Markdown/97 links-anchor, PRD unchanged).

**Batas:** native backend/key resolver **not configured**, live gates false, tanpa actual storage/provider I/O. Test-only private temp object store memodelkan independence/privacy/transport dengan provenance fake; bukan failure-domain/TLS evidence provider. Random encryption key hanya memory test. Extension sodium tersedia lokal dan ditambahkan pada Composer platform/CI; lock diff hanya platform/content hash, tanpa versi package berubah. Acuan [libsodium secretstream](https://doc.libsodium.org/secret-key_cryptography/secretstream) dan [PHP secretstream API](https://www.php.net/manual/en/function.sodium-crypto-secretstream-xchacha20poly1305-init-push.php), bukan bukti provider. Runtime migration/browser/full suite belum diulang pada task 07; full milestone suite mengikuti task 10. Native cPanel content/DB semantics dan restore level tetap unsupported/not configured sampai jalur teruji tersedia.

**Commit/push:** `2a7689dd015ddf20499cf981dda00e4f1f4091f3`, branch `main` → `origin/main`, normal push dan remote SHA exact verified. CI exact [37257964352](https://github.com/solveit-id/solveit_opshub/actions/runs/37257964352) completed/success. Tanpa secret/QA artifact/installed dependency/attribution trailer.

**Next step konkret:** **IP-M3-08**, approved retention/dry-run/protected last-good/hold/restore-pending, own temporary source cleanup only, scoped step-up 5-minute download/dashboard. Dependency task 04–07 tersedia lokal/fake. Blocker live: sandbox cPanel/SFTP, storage independen dan isolated restore belum tersedia; tidak menghalangi pekerjaan lokal/fake yang diotorisasi. Tidak masuk M4.

### IP-M3-08 — Retention, guarded access dan dashboard

**Hasil:** approved 7 daily/4 weekly/3 monthly tiers menurut IANA timezone, satu immutable version dapat mengisi beberapa tier tanpa duplicate object. Private object/version uniqueness. Metadata-only cursor dry-run bounded 500 delete candidates, report pin policy version/30-minute expiry, queued worker dan fresh authorization/scope/version/kill-switch/protection recheck. Last-known-good semua scope, legal hold, restore pending, unverified/uncertain artifact dan link aktif dilindungi. Delete intent/fencing dan unknown/crash read-only immutable-version reconciliation tanpa blind delete retry. Approved automatic retention daily serta canonical latest daily backup slot dengan deterministic jitter/coalescing; paused write tidak menghentikan monitoring read. Source cleanup mempunyai durable intent dan own-archive proof/delete port; unknown/requesting tidak diulang, SFTP tidak mendapat write. Native ownership/delete protocol belum configured, tidak mengarang endpoint provider.

**Akses/UI:** authenticated user-bound token digest, TTL 300 detik, step-up pada issuance/read, explicit backup.download grant, current dan historical full shared-account scope, private ciphertext stream 64KiB dengan size/hash/periodic+final authorization check; audit tanpa URL/token/key/path. Owner hold/retention/control, operator queued backup/reconcile, separated source/storage/integrity/restore-level state, dated scoped last-good, DB gap, policy schedule/default retention dan report results. UTC ISO DTO diperbaiki setelah browser menemukan raw DB timestamp ambigu.

**Pengujian:** final BackupAccessRetentionTest **7 passed / 58 assertions**, 59.18s; encrypted download/audit/user binding/expiry/step-up, tiga project shared account/historical usage/removal/grant revoke, tier overlap/protection recheck, positive single immutable deletion/idempotency, crash read reconciliation, scheduler/approved retention once/day, foreign-source/SFTP denial dan repeat-cleanup denial. Setelah UTC DTO berubah, targeted download/dashboard regression **1 passed / 15 assertions**, 38.66s. Fixture relasi/project_accesses/generated-column dan MySQL JSON associative key order diperbaiki berdasarkan full error; constraints/security assertions tetap. TypeScript/Vite build passed (final build 37.72s), Pint, Composer strict, docs/diff checks. Real browser Owner login, 403 dry-run tanpa step-up dengan pesan akses, password confirm lalu dry-run protected report, UTC/WIB konsisten. Desktop 1280 dan mobile 390×844: main=1, overflow=false, source+DB gap terlihat, key_reference tidak tampil; screenshot mobile diinspeksi. Console normal page 0 errors/warnings; expected 403 resource error direkam, bukan runtime exception. Multiline Windows CLI parsing didiagnosis melalui node --check/help dan diperbaiki dengan --filename. Server/browser milik task dihentikan dan manifest login fictitious dihapus.

**Batas:** fake tmp object/key hidup dalam proses test; browser memakai fixture state/default unconfigured backend, bukan bukti download/provider/storage live. Native private storage/failure domain/TLS dan source-cleanup ownership path not configured. Live gates false, runtime backup migrations tidak dijalankan, tidak ada provider I/O. Source cleanup unknown memerlukan ownership reconciliation/provider evidence sebelum tindakan lanjutan, tidak automatic retry. Full milestone/security suite mengikuti task10.

**Commit/push:** `b7ef5381ff46ab0b3bcfb76be6de2e0dc1ca7857`, branch main → origin/main, normal push/remote SHA exact/clean worktree verified sebelum task09. CI exact [37261552080](https://github.com/solveit-id/solveit_opshub/actions/runs/37261552080) failed: 205 passed/1 retention test failed, Linux capture timestamps sama detik sehingga chronology guard mempertahankan old last-good; deterministic fixture clock diperbaiki dalam task09, guard/asersi tetap. Tanpa secret, QA artifacts, dependency terpasang atau attribution trailer.

**Next step konkret:** **IP-M3-09** — isolated restore-drill/runbook/operator/check evidence dan internal backup failure/overdue incident/task, last restore UI. Dependency task04–08 tersedia lokal/fake. Sandbox non-client cPanel/SFTP, independent storage dan target isolated restore live tetap belum tersedia; seluruh kode/test lokal/fake M3 diotorisasi. M4 belum dimulai.

### IP-M3-09 — Restore-drill/runbook dan backup recovery

**Hasil:** isolated restore queued/lease/target guard, authenticated file extraction/check/hash/time/operator/evidence; target production ditolak, DB/application health tidak diklaim. Crash workspace reconciliation terbatas tree generated server milik drill; unknown/failure tidak menjadi restore success. cPanel full restore manual/provider record live-unverified tanpa automatic promotion. Backup failed/partial/unknown dan per-required-scope RPO overdue menghasilkan idempotent internal incident + recovery task + owner notification intent, terpisah dari website outage; old last-good tetap dated. Source origin cPanel konservatif request-start, bukan delayed retrieval. Dashboard last restore/check/runbook/task/result; scope current/historical tetap server-side.

**Pengujian:** initial restore suite 8 passed/68 assertions, 68.37s; source/verification/retention/parser/restore regressions **39 passed / 325 assertions**, 143.90s, termasuk deterministic Linux-CI capture clock correction tanpa melemahkan chronology. Final restore + shared Telegram reconciliation **21 passed / 187 assertions**, 90.36s: target production/step-up/permission/kill denial, authenticated bounded file reconstruction/hash/manifest, required restore-level files-only promotion, DB gap tetap, tamper gagal+existing artifact ditandai failed/historical last-good tetap dated, mid-read revoke/cleanup, crash-owned workspace reconcile failed bukan pass, cPanel manual record tanpa level promotion, failure/RPO incident+task idempotency dan RPO age tidak di-reset retention approval, request-start cPanel timestamp, closed backup issue superseded before delivery/current digest. Initial digest regression kehilangan unsupported label; label kini khusus maintenance M4 yang belum tersedia, tanpa menghapus asersi. TypeScript/Vite build passed (38.40s). Real browser pada guarded MySQL _test: fake restore checks/artifact/operator/isolated target/time, internal incident/task dan DB gap terlihat; desktop1280/mobile390×844 main=1, overflow=false, key_reference tidak tampil, console 0 errors/warnings; screenshot mobile diinspeksi. Server/browser dihentikan, login manifest removed. Pint, strict Composer, docs/diff pass.

**Batas:** actual reconstruction hanya fictitious file scope di private GUID directory test lokal, max chunk64KiB; bukan native provider sandbox/DB/application restore. Workspace/key/path tetap hidden, manual external checks evidence-only/live-unverified. Native target/store/key/cPanel artifact bridge/source cleanup not configured; live gates false, runtime backup migrations belum dijalankan, tanpa actual provider I/O. Worker/global capacity bounded dan unknown retaining artifact protection; authorized reconciliation bukan automatic retry. Full milestone/concurrency/security gate mengikuti task10.

**Commit/push:** task09 `b20a314a433d3d77ac0310e474237211886edc9c`, normal push main → origin/main dan remote SHA cocok. [CI exact 37264873121](https://github.com/solveit-id/solveit_opshub/actions/runs/37264873121): build + MySQL **215 passed / 1923 assertions**, 113.82s; run gagal pada satu Pint style issue BackupScheduler. Task10 memperbaiki import/catch tersebut dan memakai cache Pint baru; task09 CI tidak disebut success. Task08 CI failure chronology fixture tetap historis, correction task09 terbukti melalui suite CI. Tanpa secret/QA artifacts/dependencies/agent attribution.

#### Runbook restore drill M3

1. Owner memverifikasi authority akun/project, policy required scopes/RPO dan reference target **isolated** yang diotorisasi. Tidak tersedia opsi production; label target sendiri belum membuktikan isolasi. Native target adapter harus menyatakan authorized/configured/isolated/not-production/validated-sandbox dengan provenance yang cocok; default unconfigured.
2. Pilih immutable artifact yang independent/integrity verified, current dan historical full-account scope; konfirmasi password, backup.download + backup.restore_drill permission. Record menyimpan operator, artifact, target reference private, runbook/version, checks/evidence dan waktu UTC. restore_pending melindungi retention selama job/manual/unknown aktif.
3. File drill lokal/fake (`isolated-file-drill-v1`) hanya APP_ENV testing, MySQL disposable _test, DB_URL kosong. Resolver fixture eksplisit, bukan production fallback. Worker membuka encrypted object/key reference, memverifikasi authenticated final/checksum/context/manifest, bounded parser menulis file regular ke direktori GUID server private di storage/framework/testing. Tidak menerima root filesystem/shell command dari operator, tidak overwrite existing file, traversal/symlink/unsafe Windows paths ditolak. Pemeriksaan hash/size/count pada file yang dipulihkan harus cocok sebelum **restore_verified untuk files pada artifact/target/time tersebut**. Database coverage/application health tetap false.
4. Workspace plaintext isolated dibuang setelah checks/failure. Crash/timeout/expired permission/kill switch mempertahankan unknown dan retention fence; queued job tidak blind retry. Authorized read/reconcile memeriksa lalu membersihkan hanya workspace GUID milik drill; hasil ditutup failed/DRILL_INTERRUPTED, tidak pass. Native cleanup/isolation evidence yang belum tersedia tetap blocked. Jangan menghapus direktori arbitrary atau menyimpulkan cleanup dari database state.
5. Full cPanel (`cpanel-provider-manual-v1`) tanpa tested restore capability diarahkan ke operator/provider runbook: siapkan sandbox isolated baru dengan provider, catat immutable artifact/reference dan operator, lakukan restore menurut prosedur provider, periksa files dan DB di target terisolasi, simpan evidence reference. OpsHub tidak mengirim remote restore/shell/production overwrite. Record manual checks terstruktur + evidence menjadi manual_recorded/live-unverified; tidak menaikkan artifact ke restore_verified dari checkbox atau attestation saja.
6. Review backup internal incident/recovery task. Unknown source/storage harus direconcile sebelum retry. Overdue tidak dapat resolved sebelum per-scope good kembali memenuhi policy level/RPO; failure task memerlukan evidence reference dan account operation sudah terminal. Task close bukan proof backup success. Telegram hanya internal summary + authenticated dashboard link, tanpa artifact download URL/key/path/content. Jadwal command opshub:backups:schedule menciptakan slot/approved retention dan mengevaluasi failure/RPO; dispatch/delivery Telegram mengikuti durability/history controls M2, outbox intent bukan delivery.

**Next step konkret:** **IP-M3-10** — deterministic contract/failure/security/concurrency matrix, seluruh exit-gate checks dan evidence sandbox terpisah. Dependency task01–09 tersedia lokal/fake. Gate sandbox non-client/storage independen/isolated restore live masih unavailable; tidak masuk M4.

### IP-M3-10 — Validasi lokal/fake dan review exit gate M3

**Status/stop:** `LOCAL_VALIDATION_COMPLETE / LIVE_BLOCKED`. Implementasi dan checks yang independen dari provider selesai; M3 `PARTIAL_WITH_BLOCKERS`, bukan MILESTONE_READY. Sandbox non-client cPanel/SFTP, private independent storage/key custody dan target restore live terisolasi belum tersedia/diotorisasi. Tidak ada koneksi provider nyata, migration runtime backup, aktivasi gates atau pekerjaan M4.

**Instruksi root:** snapshot historis M2 di AGENTS.md dipertahankan. Auto approval review menolak update snapshot tersebut karena dianggap dapat mengubah scope agent mendatang. Scope M3 berasal dari instruksi eksplisit Owner pada sesi ini; progres/stop boundary aktual tetap canonical di CHECKPOINT dan plan, tanpa memperluas otorisasi live.

**Hasil:** deletion permit single-use wajib dikonsumsi adapter tepat sebelum own temporary archive/private immutable version delete. Guard mengecek kembali Owner/full current+historical scope, management authorization, approved policy/connector version, provenance, private protection dan kill switch. Intent persisted before I/O; denied-before-write blocked/verified, issued permit with uncertain effect unknown, tidak blind retry. SFTP tidak mendapatkan write port. Retention deletion lease 90s; active deletion tidak dapat direconcile, expired/changed token menolak late adapter sebelum effect. Setiap confirmed effect+report result dipersist; total worker budget45s menutup report partial, remaining candidates harus memakai fresh dry-run, tanpa automatic resume. Native ports tetap unconfigured. Race dua proses nyata MySQL membuktikan satu canonical run+atomic job/intent dan satu fake cPanel source trigger/attempt, API accepted tetap awaiting_source/not independently verified.

**Evidence aktual, 5 Oktober 2026:** initial serial matrix/access/races **16 passed / 131 assertions**, 197.86s. Full **composer test** (clear config → TypeScript/Vite → disposable MySQL) **227 passed / 2016 assertions**, 595.89s; Vite33.82s. Termasuk final three retention boundary cases (kill/hold/expired-and-reconciled lease), semua restore/provider/delivery regressions dan >1GiB transfer/encryption tests. Pint `--test --cache-file=output/qa/pint-m3-10-final.json` memakai cache baru passed; Composer strict, DB check MySQL8.4.11, schedule:list, docs integrity dan staged diff checks passed. Runtime readonly counts users/org/accounts/outbox/jobs=0, kedua live gates false; QA task08/09 browser/server/login manifest telah dibersihkan. Gate10 tidak mengulang browser karena tidak ada perubahan presentasi.

**Diagnosis/retry:** dua runner targeted sempat overlap schema disposable dan collision migrations/table errors; keduanya ditunggu selesai lalu satu runner serial, tanpa runtime reset/asersi dilonggarkan. Race source expectation diperbaiki menjadi accepted sesuai source state machine, bukan generating tanpa observation. CI09 test passed tetapi Pint scheduler failed; fresh-cache formatting memperbaiki issue, fresh-cache check passed. Bukti failed tetap dicatat, bukan dianggap pass.

**Delta acceptance TC-03 setelah suite penuh:** BackupConcurrencyTest diperkuat dengan tiga project nyata di fixture yang memakai satu canonical account sebelum enqueue; dua proses menghasilkan satu run/job dengan tiga impacted IDs, lalu dua worker hanya satu source trigger. Targeted final **2 passed / 23 assertions**, 83.08s; file PHP Pint passed. Tidak ada perubahan application/JS setelah suite penuh; tambahan assertions ini mendapat regression tersendiri dan akan termasuk CI exact commit berikutnya.

| Gate/scenario | Evidence lokal/fake (suite penuh + delta regression di atas) | Batas penerimaan live/full scenario |
|---|---|---|
| TC-26 | CpanelReadAdapterTest, ConnectorWorkerTest, CpanelBackupFlowTest: typed auth/permission/unsupported, write pause, tanpa website incident; public probe suite tetap passed. | Official target token-denied/unsupported sandbox belum tersedia. |
| TC-27 | SftpReadAdapterTest: pinned handshake sebelum auth, traversal/root/symlink/changed-peer denial. | Host-key/root evidence target read-only nyata belum tersedia. |
| TC-28 / allocated TC-08 | BackupPreflightTest + cPanel quota parser: stale/unknown/null/unlimited, source/storage headroom/refusal/reservation. | Credentialed quota/independence/storage capacity nyata belum divalidasi. |
| TC-29 | CpanelBackupFlowTest/ProtocolTest/BackupConcurrencyTest: request intent, acceptance awaiting_source, ownership/stability/busy/unknown, no second remote trigger. | Native official completion association/pull not_configured; acceptance/date-only inventory tidak dianggap completion. |
| TC-30 | SftpBackupFlowTest/BackupVerificationTest/BackupRestoreIssueTest: files content/restore evidence, DB gap tetap partial. | File-only SFTP tidak mendukung DB dump/application health/full restore. |
| TC-31 | SodiumEncryptedStreamTest, SftpBundleInspectionTest, BackupVerificationTest, BackupRestoreIssueTest: corrupt/wrong-key/missing-entry fail, last-good dated, internal issue/task. | Native encrypted immutable object read/recovery key drill belum tersedia. |
| TC-32 | CpanelBackupFlowTest, BackupPreflightTest, BackupVerificationTest, BackupConcurrencyTest: process race, expired fence, intent/attempt trail, reconcile-first, late-worker denial. | Crash/provider reconciliation association belum live-validated. |
| TC-33 | BackupAccessRetentionTest + BackupFailureMatrixTest: tiers/last-good/hold/restore/link protection, own source, deletion boundary/reconcile/unknown/budget. | Native source ownership/delete contract and storage retention not_configured. |
| TC-34 | BackupRestoreIssueTest: actual fictitious file extraction/checks/cleanup/target/operator/time; cPanel manual record tidak promote. | Isolated live restore drill/provider DB checks belum tersedia; universal restore unsupported. |
| TC-35 / allocated TC-03 | BackupAccessRetentionTest + BackupConcurrencyTest: full current/historical shared scope, user-bound300s link, step-up/revoke/expiry/audit, canonical run+job; Telegram private fields absent. | Real shared-account provider/artifact path belum live-validated. |
| TC-39 | HttpProbeTest/TlsDnsProbeTest/CpanelReadAdapterTest/SftpReadAdapterTest + foundation guards: SSRF/rebinding/private IPv6/ports/redirect/actual peer. | Approved actual connector targets tetap live_unverified; tidak ada outbound provider QA pada M3 ini. |
| TC-40 (allocated M3) | FoundationReliabilityTest + connector/backup/Telegram tests: reference-only secrets, typed/redacted errors/URL, CSV escaping helper formula inert. | Full scoped CSV/report export workflow M4 not_implemented; TC-40 lintas milestone tidak diklaim full pass. |
| TC-41 (allocated M3) | BackupPreflightTest/source/verification/retention/failure/restore suites: kill before/during effect, read reconcile retained; public monitoring separate unchanged and tested. | Future change/M4 controls dan provider-side active effect belum divalidasi. |

**Streaming:** generated payload1,073,807,361B → bundle1,073,807,525B/encrypted1,074,168,121B; max chunk65,536B, PHP allocator peak-growth0 pada producer/encrypted fixture. Test encrypted temp spool dan test-owned file restore bukan independent provider failure domain, absolute RSS atau pilot load/p95 proof.

**Connector/backup readiness:** native cPanel read/SFTP protocol `live_unverified`; official full-account completion/pull/private store/key resolver/source cleaner/isolated restore target `not_configured`; supported fake paths hanya berprovenance fake. Tidak ada connector `validated_sandbox` atau `unsupported_on_target` dari fake; provider target belum pernah diuji. File-only SFTP DB backup dan universal automatic cPanel/production restore `unsupported` dalam scope v1.

**Commit/push terverifikasi:** task10 `73dd8bf042cff623edd17336427a2b1b5df71573`, `(fix) fence backup deletions and validate local M3 failure and concurrency gates`, tepat17 scoped paths; staged name/stat/check, secret/artifact guard dan outgoing commit diperiksa. Push biasa main → origin/main berhasil, remote exact SHA cocok dan worktree bersih. [CI exact 37267025454](https://github.com/solveit-id/solveit_opshub/actions/runs/37267025454) **completed/success**, head_sha cocok: build7.34s, **227 passed / 2022 assertions**, 105.20s, Pint passed. CI mencakup final three-project source race delta dan perbaikan style scheduler; failed CI08/09 tetap evidence historis. Log/credentials/build/temp helpers/QA assets di-ignore, tanpa attribution trailer.

**Receipt dokumentasi:** hasil exact task10 dicatat dalam commit dokumentasi penutup sesudah implementasi/push/CI sukses. Hanya CHECKPOINT berubah; doc/reference/diff checks diulang, aplikasi/browser tidak diulang karena source/build sama. Hash implementation yang divalidasi adalah 73dd8bf di atas; hash receipt sendiri dibaca dari Git setelah commit, bukan direka sebelumnya. Snapshot AGENTS tetap tidak berubah; PRD/task acceptance/exit gates preserved.

**Pekerjaan tersisa / next step konkret:** tetap **IP-M3-10 — live sandbox capability/failure/restore validation**, dependency01–09 + kontrak lokal task10 tersedia. Infrastructure Owner harus menyediakan reference target non-client yang diotorisasi (scope/actions/expiry, official cPanel read+documented completion/pull association, SFTP read-only root/fingerprint), memilih/configure private failure-domain-independent storage + key custody/recovery, dan isolated restore target/prosedur checks/cleanup. Implement native provider ports hanya setelah kontrak/target tersedia; lakukan sandbox supported dan denied path, independent immutable verified artifact dan isolated restore evidence exact artifact/target/time, lalu review gate M3. Semua pekerjaan independen lokal selesai; stop di blocker tersebut, **jangan masuk M4** tanpa instruksi lanjutan.

#### Review lanjutan blocker IP-M3-10 — 5 Oktober 2026

**Baseline dan scope:** instruksi lanjutan Owner meminta milestone pertama yang belum selesai sampai gate atau seluruh pekerjaan tersisa blocked. Worktree bersih pada `main`/`origin/main`, baseline `e01e8058c9159f3526167b25d3d476645716c515`; `git ls-remote` mengonfirmasi exact remote SHA. Root AGENTS adalah satu-satunya instruksi lokal; snapshot M2 historis dibaca bersama progres canonical M3, bukan alasan mengulang milestone selesai atau masuk M4. Perbandingan dengan implementation `73dd8bf042cff623edd17336427a2b1b5df71573` hanya menunjukkan perubahan CHECKPOINT; source aplikasi/test/build/lockfile tidak berubah.

**Hasil audit source:** bindings `AppServiceProvider` tetap memakai `UnavailableBackupCapacity`, `UnconfiguredCpanelArtifacts`, `UnconfiguredObjectStore` dan `UnconfiguredBackupKeys`. `NativeCpanelBackupSource` menolak request ketika artifact bridge belum configured; date-only inventory tidak menjadi completion/ownership proof. `RestoreDrills` memakai `UnconfiguredRestoreTarget` bila target belum dibinding dan mensyaratkan authorized/isolated/not-production/validated-sandbox untuk native. Port source cleanup juga belum configured sebagaimana evidence task10. Tidak ditemukan task lokal/fake independen yang belum selesai; provider-specific port dan evidence membutuhkan pilihan storage/key custody, kontrak provider serta target sandbox yang belum tersedia. Status tetap `LOCAL_VALIDATION_COMPLETE / LIVE_BLOCKED`, M3 `PARTIAL_WITH_BLOCKERS`; tidak ada task baru ditandai selesai.

**Pemeriksaan aktual:** `composer db:check` berhasil pada MySQL **8.4.11**, runtime `solveit_opshub`; pemeriksaan read-only runtime menunjukkan users/organizations/hosting_accounts/outbox_events/jobs masing-masing **0**, live connectors dan public probes **false**. API GitHub read-only mengonfirmasi [CI implementation 37267025454](https://github.com/solveit-id/solveit_opshub/actions/runs/37267025454) dan [CI receipt 37267623259](https://github.com/solveit-id/solveit_opshub/actions/runs/37267623259) completed/success dengan head SHA tepat `73dd8bf` dan `e01e805`. Angka suite **227 passed / 2022 assertions** tetap evidence CI implementation terdahulu, bukan suite baru. Audit dokumen menjaga **52 task outputs, 6 exit gates, 6 acceptance blocks**, PRD unchanged, **11 Markdown / 97 relative links/anchors**; diff check passed. Aplikasi/build/browser tidak diulang karena source identik dan blocker membutuhkan evidence eksternal, bukan tambahan fake.

**Diagnosis dan batas:** launcher sandbox gagal sebelum perintah berjalan; PowerShell di luar sandbox yang lolos approval menjalankan pemeriksaan tersebut. `gh` tidak tersedia; API publik GitHub menjadi fallback dan mengembalikan exact SHA/status, tanpa credential output. DB check ditunggu hingga selesai tanpa retry/reset. Tidak ada provider I/O, migration, aktivasi gate, secret/config live atau perubahan runtime. Read-only counts tidak menyatakan provider target tersedia; native cPanel/SFTP tetap live-unverified, storage/key/capacity/restore ports not_configured, SFTP database backup/universal production restore unsupported.

**Commit/push:** review ini hanya mengubah `docs/CHECKPOINT.md`; staged scope/stat/check dan outgoing commit diperiksa sebelum commit/push biasa `main` → `origin/main`. Hash review, hasil push dan CI review dibaca setelah commit; tidak mengubah hasil CI implementation di atas. Jika push gagal, commit lokal dipertahankan dan blocker dilaporkan. Tidak ada attribution trailer atau artifact QA/secret/dependency yang masuk commit.

**Next step konkret:** tetap **IP-M3-10**, tujuan membuktikan sandbox supported+denied paths, artifact independen/integrity serta restore terisolasi. Dependency kode/test task01–09 tersedia; blocker eksternal belum berubah. Infrastructure Owner menyediakan non-secret reference target non-client dengan scope/actions/expiry dan official completion/pull contract cPanel, SFTP root/fingerprint read-only, pilihan private independent storage dan key custody/recovery, serta reference target restore isolated beserta checks/cleanup. Setelah tersedia, implement/configure native ports sesuai kontrak dan jalankan validasi sandbox exact artifact/target/time. Stop pada blocker M3; **M4 belum dimulai**.
