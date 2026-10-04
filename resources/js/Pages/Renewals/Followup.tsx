import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { observedTime } from '@/Components/MonitoringStatus';
import axios from 'axios';
import { FormEvent, useEffect, useState } from 'react';

type Draft = { id: number; template_key: string; template_version: number; contact_id: number | null; draft_status: string; blocked_reasons: string[]; rendered_body: string; generated_at: string };
type Followup = { id: number; version: number; state: string; severity: string; purpose: string; overdue: boolean; cycle_state: string; next_followup_at: string | null; assignee_user_id: number | null; response_summary?: string | null; blocker?: string | null; client_commitment?: string | null; resolution_reason?: string | null; snoozed_until: string | null; template_context?: Record<string, string | number> | null };
const button = 'rounded border border-slate-400 px-4 py-2 font-medium disabled:opacity-50';
const input = 'mt-1 block w-full rounded border-slate-300';
function instant(value: string) { return value ? new Date(value).toISOString() : null; }

export default function FollowupPage({ organization, service, followup, draft: initialDraft, contacts, assignees, attempts, impactedProjects, canManage, fullScope }: {
    organization: { id: number; name: string; timezone: string };
    service: { id: number; service_name: string | null; version: number; date_precision: string; expiry_date: string | null; expires_at: string | null; source_timezone: string | null; payment_status: string; action_owner: string };
    followup: Followup; draft: Draft | null; contacts: { id: number; name: string | null; preferred_manual_channel: string | null }[];
    assignees: { id: number; name: string }[]; impactedProjects: { id: number; name: string }[];
    attempts: { id: number; actor_user_id: number; contact_id: number; sent_at: string; manual_channel: string; draft_version: number; sent_body: string }[];
    canManage: boolean; fullScope: boolean;
}) {
    const [draft, setDraft] = useState(initialDraft);
    const [version, setVersion] = useState(followup.version);
    const [key, setKey] = useState(initialDraft?.template_key || '');
    const [contact, setContact] = useState(initialDraft?.contact_id ? String(initialDraft.contact_id) : '');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [feedback, setFeedback] = useState('');
    const [context, setContext] = useState<Record<string, string>>(Object.fromEntries(Object.entries(followup.template_context || {}).map(([k, v]) => [k, String(v)])));
    const [precision, setPrecision] = useState('date');
    const active = followup.cycle_state === 'active';
    const open = active && !['resolved', 'cancelled'].includes(followup.state);
    useEffect(() => { setDraft(initialDraft); setVersion(followup.version); setKey(initialDraft?.template_key || ''); setContact(initialDraft?.contact_id ? String(initialDraft.contact_id) : ''); setFeedback(''); }, [initialDraft, followup.version]);
    function failure(e: unknown) {
        if (axios.isAxiosError(e) && e.response?.status === 409) return 'Data/draft berubah. Muat ulang halaman dan tinjau current draft sebelum mencoba kembali.';
        if (axios.isAxiosError(e) && e.response?.status === 422) {
            const errors = e.response.data.errors as Record<string, string[]> | undefined;
            return errors ? Object.values(errors).flat().join(' ') : 'Input atau transisi belum valid. Periksa kembali data dan evidence.';
        }
        return 'Tindakan gagal. Periksa izin dan koneksi, lalu muat ulang halaman.';
    }
    async function generate() {
        const result = await axios.post(`/api/v1/organizations/${organization.id}/follow-ups/${followup.id}/drafts`, { version, template_key: key || null, contact_id: contact ? Number(contact) : null, ...(['TPL-06', 'TPL-07', 'TPL-08', 'TPL-09'].includes(key) ? { context } : {}) });
        setDraft(result.data.data); setVersion(result.data.followup_version); setKey(result.data.data.template_key); setContact(result.data.data.contact_id ? String(result.data.data.contact_id) : '');
        return result.data.data as Draft;
    }
    async function refreshDraft() {
        setBusy(true); setError(''); setFeedback('');
        try { await generate(); } catch (e) { setError(failure(e)); } finally { setBusy(false); }
    }
    async function copy() {
        setBusy(true); setError(''); setFeedback('');
        try {
            const current = await generate();
            if (current.draft_status !== 'ready') { setFeedback('Draft blocked. Lengkapi data sebelum Copy.'); return; }
            if (current.id !== draft?.id) { setFeedback('Draft diperbarui. Tinjau pesannya, lalu tekan Copy kembali.'); return; }
            await navigator.clipboard.writeText(current.rendered_body);
            setFeedback('Disalin. Kirim manual setelah ditinjau; catat kontak melalui form terpisah.');
        } catch (e) { setError(failure(e)); } finally { setBusy(false); }
    }
    async function action(name: string, data: Record<string, unknown>) {
        setBusy(true); setError(''); setFeedback('');
        try {
            await axios.post(`/api/v1/organizations/${organization.id}/follow-ups/${followup.id}/${name}`, { version, ...data }, { headers: { 'Idempotency-Key': crypto.randomUUID() } });
            router.reload();
            return true;
        } catch (e) { setError(failure(e)); return false; } finally { setBusy(false); }
    }
    function submit(e: FormEvent<HTMLFormElement>, name: string, map: (f: FormData) => Record<string, unknown>) {
        e.preventDefault();
        const form = e.currentTarget;
        try { void action(name, map(new FormData(form))).then(success => { if (success && name === 'contact') form.reset(); }); } catch { setError('Tanggal/waktu invalid.'); }
    }
    return <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Follow-up #{followup.id} · {service.service_name || 'Layanan'}</h2>}>
        <Head title={`Follow-up ${followup.id}`} />
        <div className="mx-auto max-w-5xl space-y-6 px-4 py-8">
            <Link className="underline" href={route('renewals.index', organization.id)}>Kembali ke renewal</Link>
            <section className="space-y-2 border bg-white p-5">
                <h3 className="text-lg font-semibold">{followup.state} · {followup.severity}{followup.overdue && ' · Overdue'}</h3>
                <p>{impactedProjects.map(p => p.name).join(', ')} · {followup.purpose}</p>
                <p>Expiry: {service.date_precision === 'unknown' ? 'Unknown — verifikasi diperlukan' : service.date_precision === 'date' ? `${service.expiry_date?.slice(0, 10)} · jam tidak diketahui · ${service.source_timezone}` : observedTime(service.expires_at)}</p>
                <p>Pembayaran: {service.payment_status} · Action owner: {service.action_owner}</p>
                <p>Tindak lanjut: {observedTime(followup.next_followup_at)} · Snoozed: {observedTime(followup.snoozed_until)}</p>
                {followup.resolution_reason && <p>Hasil: {followup.resolution_reason}</p>}
                {!fullScope && <p>Scope parsial. Detail komunikasi dan draft membutuhkan akses seluruh proyek terkait.</p>}
                {fullScope && !canManage && <p>Akses baca. Perubahan memerlukan Operator/Operations yang ditugaskan.</p>}
            </section>
            {error && <div role="alert" className="border border-red-300 bg-red-50 p-4">{error} <button type="button" className="underline" onClick={() => router.reload()}>Muat ulang</button></div>}
            {feedback && <p role="status" aria-live="polite" className="border bg-blue-50 p-4">{feedback}</p>}
            {fullScope && <section className="space-y-4 border bg-white p-5">
                <h3 className="text-lg font-semibold">Draft client — pengiriman manual</h3>
                {canManage && <div className="grid gap-4 sm:grid-cols-2">
                    <label>Template<select className={input} value={key} onChange={e => { setKey(e.target.value); setFeedback(''); }}><option value="">Otomatis sesuai current state</option>{Array.from({ length: 10 }, (_, i) => `TPL-${String(i + 1).padStart(2, '0')}`).map(k => <option key={k}>{k}</option>)}</select></label>
                    <label>Contact target<select className={input} value={contact} onChange={e => { setContact(e.target.value); setFeedback(''); }}><option value="">Pilih / otomatis jika satu client</option>{contacts.map(c => <option key={c.id} value={c.id}>{c.name || `Contact #${c.id}`} · {c.preferred_manual_channel || 'manual'}</option>)}</select></label>
                </div>}
                {canManage && ['TPL-06', 'TPL-07', 'TPL-08', 'TPL-09'].includes(key) && <div className="space-y-3 border p-3"><p>Konteks yang sudah ditinjau. Evidence wajib sesuai trigger; tidak membuat keputusan upgrade/maintenance secara otomatis.</p>{['requested_action', 'impact_summary', 'approval_summary', 'safe_access_instruction', 'review_evidence_id'].map(name => <label key={name} className="block">{name}<input className={input} maxLength={2000} value={context[name] || ''} onChange={e => setContext({ ...context, [name]: e.target.value })} /></label>)}</div>}
                {canManage && <button type="button" className={button} disabled={busy} onClick={refreshDraft}>{busy ? 'Memproses…' : 'Generate current draft'}</button>}
                {draft ? <>
                    <p>{draft.template_key} v{draft.template_version} · <strong>{draft.draft_status}</strong> · {observedTime(draft.generated_at)}</p>
                    {draft.draft_status === 'blocked_missing_data' && <div className="border bg-amber-50 p-3"><p>Lengkapi: {draft.blocked_reasons.join(', ')}</p><Link className="underline" href={route('registry.page', organization.id)}>Lengkapi data registry/contact/PIC</Link><p className="mt-2">Template review memerlukan evidence yang ditinjau; renewal selesai memerlukan verifikasi masa aktif.</p></div>}
                    {['stale', 'superseded'].includes(draft.draft_status) && <p>Tinjau ulang current draft. Versi ini tidak siap dikirim.</p>}
                    <pre className="whitespace-pre-wrap break-words rounded bg-slate-50 p-4 font-sans">{draft.rendered_body}</pre>
                    {canManage && <button type="button" className={button} disabled={busy || draft.draft_status !== 'ready' || (key !== '' && key !== draft.template_key) || (contact !== '' && Number(contact) !== draft.contact_id)} onClick={copy}>Copy ready draft</button>}
                </> : <p>Belum ada draft. Generate dengan data terbaru.</p>}
            </section>}
            {canManage && open && <form className="space-y-4 border bg-white p-5" onSubmit={e => submit(e, 'contact', f => ({ template_draft_id: draft?.id, contact_id: draft?.contact_id, manual_channel: f.get('manual_channel'), sent_at: instant(String(f.get('sent_at'))), next_followup_at: instant(String(f.get('next_followup_at'))), evidence_id: f.get('evidence_id') ? Number(f.get('evidence_id')) : null }))}>
                <h3 className="text-lg font-semibold">Mark contacted — catat pesan yang benar-benar dikirim</h3>
                <p>Form ini mencatat body draft yang ditinjau dan actor Anda. Copy tidak mengubah status contacted.</p>
                <div className="grid gap-4 sm:grid-cols-2"><label>Manual channel<input name="manual_channel" required maxLength={80} className={input} placeholder="WhatsApp manual / email manual" /></label><label>Waktu pengiriman aktual (lokal perangkat)<input name="sent_at" type="datetime-local" required className={input} /></label><label>Next follow-up (lokal perangkat)<input name="next_followup_at" type="datetime-local" required className={input} /></label></div>
                <label>Evidence ID pengiriman (opsional)<input name="evidence_id" type="number" min={1} className={input} /></label>
                <label className="flex items-start gap-2"><input type="checkbox" required className="mt-1" />Saya sudah mengirim body draft ini secara manual kepada contact target yang dipilih.</label>
                <button className={button} disabled={busy || draft?.draft_status !== 'ready'}>Mark contacted</button>
            </form>}
            {canManage && active && <section className="space-y-5 border bg-white p-5">
                <h3 className="text-lg font-semibold">Tindak lanjut dan jawaban client</h3>
                {open && <>
                    <form className="space-y-3" onSubmit={e => submit(e, 'assign', f => ({ assignee_user_id: f.get('assignee_user_id') ? Number(f.get('assignee_user_id')) : null }))}><label>Assignee<select name="assignee_user_id" defaultValue={followup.assignee_user_id || ''} className={input}><option value="">Belum ditugaskan</option>{assignees.map(a => <option key={a.id} value={a.id}>{a.name}</option>)}</select></label><button className={button} disabled={busy}>Simpan assignee</button></form>
                    <form className="space-y-3" onSubmit={e => submit(e, 'waiting_client', f => ({ next_followup_at: instant(String(f.get('next_followup_at'))), blocker: f.get('blocker'), client_commitment: f.get('client_commitment') }))}><label>Deadline tindak lanjut (lokal perangkat)<input name="next_followup_at" type="datetime-local" required className={input} /></label><label>Blocker<input name="blocker" maxLength={2000} defaultValue={followup.blocker || ''} className={input} /></label><label>Commitment client<input name="client_commitment" maxLength={2000} defaultValue={followup.client_commitment || ''} className={input} /></label><button className={button} disabled={busy}>Waiting client</button><p className="text-sm">Critical reminder internal tetap berjalan saat waiting client.</p></form>
                    <form className="space-y-3" onSubmit={e => submit(e, 'client_confirmed', f => ({ response_summary: f.get('response_summary') }))}><label>Ringkasan jawaban client<textarea name="response_summary" required maxLength={2000} defaultValue={followup.response_summary || ''} className={input} /></label><button className={button} disabled={busy}>Catat client-confirmed</button><p className="text-sm">Konfirmasi client/pembayaran belum menjadi verified renewal.</p></form>
                    <div className="flex flex-wrap gap-3"><button type="button" className={button} disabled={busy} onClick={() => action('acknowledge', {})}>Acknowledge</button><button type="button" className={button} disabled={busy} onClick={() => action('claim', {})}>Claim</button><button type="button" className={button} disabled={busy} onClick={() => action('in_progress', {})}>In progress</button></div>
                    <form className="space-y-3" onSubmit={e => submit(e, 'snooze', f => ({ reason: f.get('reason'), snoozed_until: instant(String(f.get('until'))) }))}><label>Reason snooze<input name="reason" required maxLength={2000} className={input} /></label><label>Sampai (lokal perangkat)<input name="until" type="datetime-local" required className={input} /></label><button className={button} disabled={busy}>Snooze / pause berbatas</button><p className="text-sm">Critical maksimal 24 jam, lainnya tujuh hari. Overdue tetap ditampilkan.</p></form>
                </>}
                <form className="space-y-3" onSubmit={e => submit(e, followup.state === 'cancelled' ? 'reopen' : 'cancel', f => ({ reason: f.get('reason') }))}><label>Reason {followup.state === 'cancelled' ? 'reopen' : 'cancel'}<input name="reason" required maxLength={2000} className={input} /></label><button className={button} disabled={busy}>{followup.state === 'cancelled' ? 'Reopen dengan reason' : 'Cancel dengan reason'}</button></form>
            </section>}
            {canManage && open && <form className="space-y-4 border bg-white p-5" onSubmit={e => submit(e, 'verify_renewal', f => ({ subscription_version: service.version, date_precision: f.get('precision'), expiry_date: f.get('precision') === 'date' ? f.get('new_expiry_date') : null, expires_at: f.get('precision') === 'instant' ? instant(String(f.get('new_expires_at'))) : null, source_timezone: f.get('timezone'), source: f.get('source'), renew_by: instant(String(f.get('renew_by'))), evidence_reference: f.get('reference'), provider_evidence_confirmed: f.get('confirmed') === 'on' }))}>
                <h3 className="text-lg font-semibold">Verifikasi renewal dari masa aktif provider</h3>
                <p>Expiry baru harus lebih lama dari cycle saat ini dan di masa depan. Pemeriksaan evidence dilakukan operator; aplikasi tidak menyatakan provider API live telah diverifikasi.</p>
                <div className="grid gap-4 sm:grid-cols-2"><label>Precision<select name="precision" value={precision} onChange={e => setPrecision(e.target.value)} className={input}><option value="date">Tanggal saja, jam tidak diketahui</option><option value="instant">Instant dengan jam</option></select></label><label>Tanggal expiry baru<input name="new_expiry_date" type="date" required={precision === 'date'} className={input} /></label><label>Instant expiry baru (lokal perangkat)<input name="new_expires_at" type="datetime-local" required={precision === 'instant'} className={input} /></label><label>Timezone sumber IANA<input name="timezone" required defaultValue={service.source_timezone || 'Asia/Jakarta'} className={input} /></label><label>Source<input name="source" required maxLength={255} defaultValue="manual_provider_review" className={input} /></label><label>Renew by baru (opsional, lokal perangkat)<input name="renew_by" type="datetime-local" className={input} /></label></div>
                <label>Referensi bukti pada penyimpanan aman<input name="reference" required pattern={'evidence:[A-Za-z0-9\\/_\\-]{3,200}'} placeholder="evidence:renewal/provider-receipt-123" className={input} /></label>
                <label className="flex items-start gap-2"><input type="checkbox" name="confirmed" required className="mt-1" />Saya memeriksa evidence masa aktif provider baru. Evidence ini bukan sekadar pembayaran atau persetujuan.</label>
                <button className={button} disabled={busy}>Verify renewal & buat cycle baru</button>
            </form>}
            {fullScope && <section className="space-y-4 border bg-white p-5"><h3 className="text-lg font-semibold">Histori contact append-only</h3>{attempts.length === 0 ? <p>Belum ada pesan yang dicatat terkirim.</p> : attempts.map(a => <details key={a.id}><summary className="cursor-pointer">{observedTime(a.sent_at)} · {a.manual_channel} · actor #{a.actor_user_id} · contact #{a.contact_id} · draft v{a.draft_version}</summary><pre className="mt-2 whitespace-pre-wrap break-words font-sans">{a.sent_body}</pre></details>)}</section>}
        </div>
    </AuthenticatedLayout>;
}
