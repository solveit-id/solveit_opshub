# Instruksi kerja Solveit OpsHub

Berlaku untuk seluruh repository. Instruksi eksplisit user menentukan scope dan tindakan yang diotorisasi; dokumen ini tidak memberikan izin tindakan live/production hanya karena dibaca.

## 1. Mulai dari kondisi aktual

1. Periksa `git status --short --branch`, branch/upstream, perubahan existing dan instruksi lokal yang lebih spesifik. Pertahankan perubahan user yang valid; jangan melakukan reset/clean, menghapus lock file atau membuat ulang scaffold tanpa instruksi.
2. Baca [docs/PRD.md](docs/PRD.md) sebagai otoritas produk, [docs/IMPLEMENTATION_PLAN.md](docs/IMPLEMENTATION_PLAN.md) untuk urutan/dependency/acceptance, dan [docs/CHECKPOINT.md](docs/CHECKPOINT.md) untuk progres/evidence/runbook/next step. Keputusan arsitektur ada di [docs/adr/](docs/adr/).
3. Snapshot 4 Oktober 2026 setelah persetujuan Owner [ADR-0006](docs/adr/0006-milestone-acceptance-sequencing.md): M0/M1 `MILESTONE_READY` lokal; M2 aktif, task 01, 02, 03, 04, 05, 06, 07 implemented/tested dan IP-M2-08 berikutnya; M3–M5 belum dimulai. Full TC lintas milestone tetap wajib pada gate pemilik/Internal v1. Verifikasi CHECKPOINT/source; stop di gate/blocker M2 dan jangan masuk M3 tanpa instruksi lanjutan.
4. Pilih task belum selesai dalam scope yang diminta. Jangan masuk milestone berikutnya atau mengubah acceptance gate secara diam-diam. Scope dokumentasi tidak mengotorisasi implementasi fitur baru.

## 2. Peta repository dan pola implementasi

| Lokasi | Tanggung jawab aktual |
|---|---|
| `app/Application/` | Service identity/scope, registry/lifecycle, policy/scheduling/idempotency, monitoring/incident, audit dan outbox. |
| `app/Domain/IdentityAccess/` | Role dan aturan domain identity yang sudah ada. Jangan membuat ulang struktur target plan tanpa kebutuhan task. |
| `app/Models/`, `database/migrations/` | Persistence, organization scope, relasi canonical, constraints, chronology/version. |
| `app/Infrastructure/Monitoring/` | HTTP/TLS/DNS transport dan hasil probe terstruktur. |
| `app/Infrastructure/Security/` | SSRF, DNS resolver, redaction, live connector gate dan security helpers. |
| `app/Infrastructure/Testing/`, `app/Infrastructure/Connectors/Fakes/` | Adapter/fixture fake dengan provenance eksplisit; bukan integrasi provider live. |
| `app/Http/`, `routes/`, `app/Jobs/`, `app/Console/`, `app/Providers/` | Authenticated API/Inertia, middleware, jobs, commands dan dependency bindings. |
| `resources/js/` | React/TypeScript/Inertia pages, layouts dan components; Tailwind mengikuti konfigurasi existing. |
| `tests/Unit/`, `tests/Feature/`, `tests/Support/` | Test domain/security/workflow, MySQL concurrency dan fixture. |
| `infra/mysql/init/`, `compose.yaml` | MySQL lokal dan provisioning schema test. |
| `docs/` | PRD, plan, CHECKPOINT tunggal dan ADR. Runbook/evidence monitoring berada di CHECKPOINT. |
| `output/`, `.playwright-cli/` | Artifact QA lokal yang di-ignore; jangan commit screenshot, session atau temporary helper. |

Baseline mengikuti lockfile: Laravel 13/PHP 8.5, Inertia 2/React 18/TypeScript 5/Vite 7. `package.json` hanya menyediakan `dev` dan `build`; jangan mengarang script lint/test frontend. Versi patch aktual diperiksa dari lockfile/runtime, bukan dari rekomendasi historis ADR.

Gunakan service/pola existing dan pencarian terarah (`rg`/`rg --files`). Tempatkan invariant/transisi di application/domain, protokol eksternal di infrastructure, dan presentasi di React. Scope, state dan permission harus dicek server-side; UI disabled bukan security boundary.

## 3. Database, runtime dan pengujian

- **MySQL 8.4/InnoDB/utf8mb4 adalah satu-satunya database aplikasi**, termasuk setup, runtime, migrations, test dan CI. Application guard menolak driver lain serta MariaDB. Jangan memperkenalkan jalur SQLite atau memakai XAMPP MariaDB untuk proyek ini.
- Default Compose lokal: `127.0.0.1:3308`, runtime `solveit_opshub`, test `solveit_opshub_test`, account non-root `opshub`. Jangan mencetak credential `.env`; verifikasi koneksi dengan `composer db:check`.
- PHPUnit memaksa `APP_ENV=testing`, MySQL, schema `solveit_opshub_test` dan `DB_URL` kosong. `tests/TestCase.php` memeriksa guard sebelum reset. Operasi destructive test hanya boleh mengenai database disposable berakhiran `_test`; jangan menghapus persistent volume/runtime data.
- Probe M1 memakai database queue yang di-dispatch eksplisit agar insert job dan slot berbagi transaksi. Redis tetap target production dengan gate durabilitas tersendiri; jangan menyebut Redis sudah digunakan.
- Simpan instant dalam UTC; gunakan IANA timezone untuk policy dan Asia/Jakarta untuk display. Pertahankan model UTC, optimistic version/409, lease fencing, slot uniqueness dan transaction/outbox atomicity.

| Tujuan | Perintah dari root | Catatan |
|---|---|---|
| Setup awal yang diperlukan | `composer setup` | Install locked dependencies, siapkan `.env` lokal, start MySQL, cek koneksi, migrate dan build. Jangan ulangi untuk pekerjaan docs. |
| Start/cek database | `composer db:up`, `composer db:check` | Credential lokal dibuat bila kosong; nilai existing dipertahankan. |
| Inspeksi migration | `php artisan migrate:status` | Periksa target/data sebelum migrate runtime; jangan memakai destructive reset untuk setup. |
| Development web/Vite | `composer dev` | Proses yang dimulai harus dihentikan setelah QA bila tidak diperlukan. |
| Suite penuh | `composer test` | Clear config → TypeScript/Vite build → MySQL tests. |
| Targeted regression | `php artisan test --filter=NamaTest` | Build manifest harus tersedia sebelum test HTTP/Inertia langsung. |
| Typecheck/build | `npm.cmd run build` di Windows | Script `tsc && vite build`. Gunakan `npm.cmd`/`npx.cmd` pada PowerShell. |
| Format PHP / Composer | `php vendor/bin/pint --test`, `composer validate --strict` | Perbaiki error, jangan menurunkan aturan/asersi. |
| Dokumentasi / staged scope | `git diff --check`, `git diff --cached --check` | Verifikasi relative links, anchor, path lama, task IDs, requirement mapping dan exit gates. |

Jalankan pemeriksaan sesuai dampak. Perubahan dokumen saja memerlukan validasi integritas/link/reference dan diff; jangan mengklaim test aplikasi/browser diulang jika tidak dijalankan. Untuk runtime/business/security lakukan targeted regression dan gate wajib; perluas pengujian bila perubahan/failure/risiko baru memberi alasan. CI Ubuntu memakai PHP 8.5, Node 22, MySQL 8.4, build sebelum test, lalu Pint.

Jika perintah gagal, periksa error lengkap, tentukan penyebab dan alasan retry sebelum mengulang. Jangan mematikan test atau mengubah unknown menjadi pass untuk mendapatkan hasil hijau.

## 4. Keputusan produk dan keamanan yang wajib dipertahankan

- Internal-first, organization-scoped, tanpa public signup/client portal/billing pada Internal v1. User bisnis/contact bukan otomatis application user.
- Telegram satu-satunya notification integration eksternal dalam scope v1. Pengiriman ke client tetap manual setelah review; Copy tidak berarti contacted, dan reported payment bukan verified renewal.
- Public HTTP/TLS/DNS tidak memerlukan hosting credential dan tidak membuktikan CPU/RAM/database/application health/global uptime. Expected-content check opt-in.
- `OPSHUB_PUBLIC_PROBES_ENABLED=false` dan `LIVE_CONNECTORS_ENABLED=false` adalah default yang terpisah. Jangan mengaktifkan live probe/connector tanpa otorisasi target/config yang sesuai. Native adapter implemented bukan live-validated.
- Pertahankan SSRF scheme/port/DNS/public-IP/redirect/pinned-target/actual-peer guard, TLS chain/hostname verification, bounded total timeout/body/header/redirect dan structured evidence allowlist. Jangan menonaktifkan TLS verification/proxy safeguards untuk memudahkan test.
- Tiga eligible failure mengonfirmasi HTTP/DNS down; dua pass mengonfirmasi recovery. TLS warning/critical immediate. Unknown/missed/stale tidak mengarang website outage atau kesehatan hijau.
- Canonical shared resource tidak boleh menduplikasi run atau membocorkan project lain. Pertahankan project/organization access, incident impact snapshots, maintenance evidence dan recovery/closure summary gate.
- Fake harus diberi source/provenance dan tidak menjadi fallback production. Demo `opshub:monitoring:demo` hanya untuk `APP_ENV=testing`, MySQL `_test` dan tanpa `DB_URL`; recovery hanya untuk fictitious fake-only records. Cleanup manifest login setelah QA.
- Incident outbox event belum berarti Telegram delivery. Consumer destination/history guard merupakan pekerjaan M2; jangan membuat event pending seolah sudah terkirim.
- Secret bisnis disimpan sebagai reference; token/password/key/raw client data tidak boleh masuk UI, Telegram, log, export atau Git. Jangan commit `.env`, `auth.json`, vendor/node_modules, build, QA artifacts atau session.
- cPanel hanya official API pada scope yang diuji/diotorisasi; SFTP read-only dengan host-key/root guard. Tidak ada control-panel scraping, arbitrary remote shell, auto deployment/update/migration/DNS write/production restore atau autonomous production agent.
- Backup baru terlindungi setelah independent storage dan integrity verification. API acceptance/archive di source bukan success; file-only SFTP bukan database backup. Connector/backup/restore/watchdog/production provisioning tetap mengikuti gate milestone terkait.

## 5. Checkpoint, commit dan push

Perbarui `docs/CHECKPOINT.md` dengan task aktif, hasil, pekerjaan tersisa, tests/evidence, batas live, commit/push, dependency/blocker dan next step konkret. Perbarui status snapshot plan saat progres berubah; pertahankan ID/acceptance/gate PRD. Jangan membuat file ledger/checkpoint/runbook monitoring paralel.

Commit/push hanya sesuai scope instruksi user. Jika diotorisasi, stage path terkait secara eksplisit, periksa `git diff --cached --name-only`, `--stat`, `--check` dan isi yang sensitif. Format commit **`(<type>) <deskripsi spesifik perubahan>`**, misalnya `(docs) consolidate checkpoints and align roadmap`; type mengikuti perubahan aktual. **Jangan menambahkan `Co-authored-by`, co-author atau attribution trailer agent.**

Sebelum push, periksa branch/upstream, outgoing commits dan staged scope. Gunakan push biasa ke remote/upstream yang dikonfigurasi; tanpa force push/rewrite history. Jika remote/push gagal, pertahankan commit lokal dan catat blocker. Jangan mengubah remote atau branch user tanpa kebutuhan yang diotorisasi. Verifikasi remote hash setelah push; hasil CI harus terkait exact commit yang disebut.

Laporan akhir menyebut hasil/scope task, pemeriksaan aktual, batas validasi, branch/hash/status push, blocker dan next step. Status release harus berasal dari evidence, bukan jumlah file atau tampilan UI.
