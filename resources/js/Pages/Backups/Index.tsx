import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';

type Artifact = { id: number; version: number; state: string; verification_level: string; coverage_scopes: string[]; source_observed_at: string; verified_at: string | null; legal_hold: boolean; restore_pending: boolean; encrypted_bytes: number };
type Run = { id: number; run_reference: string; state: string; source_status: string; transfer_status: string; integrity_status: string; reason_code: string | null; fake: boolean; can_download: boolean; impacted_project_ids: number[]; artifacts: Artifact[] };
type Policy = { id: number; version: number; enabled: boolean; account_id: number; connector_kind: string; validation_state: string; required_scopes: string[]; timezone: string; daily_at: string; jitter_minutes: number; rpo_hours: number; retention: { daily: number; weekly: number; monthly: number }; cleanup_temporary_source: boolean; last_goods: { scope: string; source_observed_at: string; verification_level: string }[]; runs: Run[]; retention_reports: { id: number; state: string; results: { artifact_id: number; state: string }[] | null }[] };
type Report = { id: number; decisions: { artifact_id: number; decision: string; reasons: string[]; tiers: string[] }[] };
const date = (value: string | null) => value ? new Date(value).toLocaleString('id-ID', { timeZone: 'Asia/Jakarta' }) + ' WIB' : 'Belum terbukti';

export default function Backups({ organization, items, isOwner, canRun, control, liveEnabled }: { organization: { id: number; name: string }; items: Policy[]; isOwner: boolean; canRun: boolean; control: { paused: boolean; version: number }; liveEnabled: boolean }) {
    const [message, setMessage] = useState('');
    const [busy, setBusy] = useState(false);
    const [report, setReport] = useState<Report | null>(null);
    const [download, setDownload] = useState<{ url: string; expires_at: string } | null>(null);
    const base = `/api/v1/organizations/${organization.id}`;
    const action = async (url: string, data = {}, kind = '') => {
        setBusy(true); setMessage('');
        try {
            const response = await axios.post(base + url, data, { headers: { 'Idempotency-Key': crypto.randomUUID() } });
            if (kind === 'preview') setReport(response.data.data);
            else if (kind === 'download') setDownload(response.data.data);
            else { setMessage('Permintaan diterima. Periksa status hasil setelah worker berjalan.'); router.reload({ only: ['items', 'control'] }); }
        } catch (error) {
            const status = axios.isAxiosError(error) ? error.response?.status : null;
            setMessage(status === 403 ? 'Akses ditolak atau konfirmasi password diperlukan. Gunakan Konfirmasi akses.' : status === 409 ? 'State/version berubah atau backend belum tersedia. Muat ulang dan periksa evidence.' : 'Permintaan gagal. Periksa scope dan status layanan.');
        } finally { setBusy(false); }
    };
    const button = 'rounded border border-gray-300 bg-white px-3 py-2 text-sm disabled:opacity-50 focus:ring-2 focus:ring-indigo-500';
    return <AuthenticatedLayout header={<h1 className="text-xl font-semibold">Backup — {organization.name}</h1>}>
        <Head title="Backup" />
        <div className="mx-auto max-w-7xl space-y-5 p-4 sm:p-6">
            <section className="rounded bg-white p-4 shadow space-y-3" aria-label="Status backup">
                <p>Write: <strong>{control.paused ? 'PAUSED' : 'diizinkan policy'}</strong> · Live connector: <strong>{liveEnabled ? 'enabled' : 'false'}</strong></p>
                <p className="text-sm text-gray-600">Acceptance API/source archive belum berarti backup terlindungi. Integrity, scope, dan restore ditampilkan sesuai bukti. SFTP file-only tidak membuktikan backup database.</p>
                <div className="flex flex-wrap gap-2"><Link className={button} href={route('password.confirm')}>Konfirmasi akses</Link><button className={button} onClick={() => router.reload()}>Muat ulang status</button>
                    {isOwner && <button disabled={busy} className={button} onClick={() => action('/backup-write-control', { paused: !control.paused, version: control.version })}>{control.paused ? 'Izinkan write sesuai policy' : 'Pause seluruh write backup'}</button>}
                </div>
                <p role="status" aria-live="polite">{message}</p>
                {download && <p><a className="text-indigo-700 underline" href={download.url}>Download artifact terenkripsi</a> · kedaluwarsa {date(download.expires_at)}. Key dikelola terpisah oleh operator.</p>}
            </section>
            {items.length === 0 && <p>Belum ada policy backup yang dapat Anda akses.</p>}
            {items.map(policy => <section key={policy.id} className="rounded bg-white p-4 shadow space-y-4" aria-label={`Backup akun ${policy.account_id}`}>
                <h2 className="font-semibold">Akun #{policy.account_id} · {policy.connector_kind} · {policy.validation_state}</h2>
                <p className="text-sm">Policy v{policy.version} {policy.enabled ? 'aktif' : 'disabled'} · required: {policy.required_scopes.join(', ')} · {policy.daily_at} {policy.timezone} + jitter 0–{policy.jitter_minutes} menit · RPO {policy.rpo_hours} jam.</p>
                <div className="space-y-1 text-sm">{policy.required_scopes.map(scope => { const good = policy.last_goods.find(g => g.scope === scope); return <p key={scope}>{scope}: last-known-good {date(good?.source_observed_at ?? null)} · {good?.verification_level ?? 'coverage gap'}</p>; })}</div>
                <div className="flex flex-wrap gap-2">
                    {canRun && <button className={button} disabled={busy || control.paused || !policy.enabled} onClick={() => action(`/backup-policies/${policy.id}/runs`, { version: policy.version })}>Antrekan backup</button>}
                    {isOwner && <button className={button} disabled={busy} onClick={() => action(`/backup-policies/${policy.id}/retention-preview`, {}, 'preview')}>Dry-run retention</button>}
                </div>
                {isOwner && <form className="flex flex-wrap items-end gap-3" onSubmit={event => { event.preventDefault(); const form = new FormData(event.currentTarget); action(`/backup-policies/${policy.id}/retention`, { version: policy.version, retention: { daily: Number(form.get('daily')), weekly: Number(form.get('weekly')), monthly: Number(form.get('monthly')) }, cleanup_temporary_source: form.get('cleanup') === 'on' }); }}>
                    {(['daily', 'weekly', 'monthly'] as const).map(tier => <label key={tier} className="text-sm">Retention {tier}<input className="block w-24 rounded border-gray-300" name={tier} type="number" min="1" max="365" required defaultValue={policy.retention[tier]} /></label>)}
                    <label className="text-sm"><input name="cleanup" type="checkbox" defaultChecked={policy.cleanup_temporary_source} /> Cleanup source milik OpsHub saja</label>
                    <button className={button} disabled={busy}>Setujui policy retention</button>
                </form>}
                <p className="text-sm text-gray-600">Cleanup menjaga last-known-good, legal hold, restore pending dan download aktif. Native source cleanup memerlukan proof kepemilikan teruji; SFTP tetap read-only.</p>
                {policy.retention_reports.map(result => <p className="text-sm" key={result.id}>Retention #{result.id}: {result.state} · {result.results?.map(row => `artifact #${row.artifact_id}: ${row.state}`).join('; ') ?? 'menunggu hasil worker'}</p>)}
                {policy.runs.map(run => <article key={run.id} className="border-t pt-3 space-y-2">
                    <h3 className="font-medium">Run #{run.id} · {run.state} {run.fake && '· fake/testing'}</h3>
                    <dl className="grid grid-cols-1 gap-2 text-sm sm:grid-cols-3"><div><dt>Source</dt><dd>{run.source_status}</dd></div><div><dt>Independent storage</dt><dd>{run.transfer_status}</dd></div><div><dt>Integrity</dt><dd>{run.integrity_status}</dd></div></dl>
                    <p className="text-sm">{run.reason_code ?? 'Tidak ada reason code'} · impacted project: {run.impacted_project_ids.join(', ')}</p>
                    {canRun && run.state === 'reconcile_required' && <button disabled={busy} className={button} onClick={() => action(`/backup-runs/${run.id}/reconcile`)}>Antrekan rekonsiliasi</button>}
                    {run.artifacts.map(artifact => <div key={artifact.id} className="rounded bg-gray-50 p-3 text-sm space-y-2">
                        <p>Artifact #{artifact.id}: {artifact.state} · {artifact.verification_level} · scope {artifact.coverage_scopes.join(', ')} · {artifact.encrypted_bytes.toLocaleString('id-ID')} bytes encrypted</p>
                        <p>Source {date(artifact.source_observed_at)} · verified {date(artifact.verified_at)} · restore {artifact.verification_level === 'restore_verified' ? 'verified' : 'belum diuji'}</p>
                        <p>Legal hold {artifact.legal_hold ? 'ya' : 'tidak'} · restore pending {artifact.restore_pending ? 'ya' : 'tidak'}</p>
                        <div className="flex flex-wrap gap-2">
                            {run.can_download && artifact.state === 'verified' && <button disabled={busy} className={button} onClick={() => action(`/backup-artifacts/${artifact.id}/download-link`, {}, 'download')}>Buat link 5 menit</button>}
                            {isOwner && artifact.state === 'verified' && <button disabled={busy} className={button} onClick={() => action(`/backup-artifacts/${artifact.id}/hold`, { version: artifact.version, legal_hold: !artifact.legal_hold })}>{artifact.legal_hold ? 'Lepas legal hold' : 'Pasang legal hold'}</button>}
                            {isOwner && ['deleting', 'delete_unknown'].includes(artifact.state) && <button disabled={busy} className={button} onClick={() => action(`/backup-artifacts/${artifact.id}/reconcile-deletion`, { version: artifact.version })}>Rekonsiliasi deletion</button>}
                            {isOwner && policy.cleanup_temporary_source && policy.connector_kind === 'cpanel' && artifact.state === 'verified' && <button disabled={busy || control.paused} className={button} onClick={() => action(`/backup-artifacts/${artifact.id}/cleanup-source`)}>Cleanup own source</button>}
                        </div>
                    </div>)}
                </article>)}
            </section>)}
            {report && <section aria-label="Dry-run report" className="rounded bg-white p-4 shadow space-y-3"><h2 className="font-semibold">Retention report #{report.id}</h2><p className="text-sm">Preview terbatas 500 kandidat delete. Worker memeriksa ulang semua perlindungan; report berlaku 30 menit.</p>
                <ul className="space-y-1 text-sm">{report.decisions.map(row => <li key={row.artifact_id}>#{row.artifact_id}: {row.decision} · {row.reasons.join(', ')} · tier {row.tiers.join(', ')}</li>)}</ul>
                {isOwner && <button className={button} disabled={busy || control.paused} onClick={() => action(`/backup-retention-reports/${report.id}/apply`)}>Antrekan cleanup approved policy</button>}
            </section>}
        </div>
    </AuthenticatedLayout>;
}
