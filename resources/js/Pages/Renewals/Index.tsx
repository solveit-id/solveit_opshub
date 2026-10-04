import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { observedTime } from '@/Components/MonitoringStatus';
import axios from 'axios';
import { useState } from 'react';

type Item = {
    id: number; service_name: string | null; service_kind: string; version: number;
    date_precision: string; expiry_date: string | null; expires_at: string | null; source_timezone: string | null;
    action_owner: string; payment_status: string; can_manage: boolean;
    impacted_projects: { id: number; name: string }[];
    followup: { id: number; state: string; severity: string; overdue: boolean; next_followup_at: string | null; snoozed_until: string | null } | null;
    history: { sequence: number; state: string; followup_id: number | null }[];
};

export default function Renewals({ organization, items, isOwner }: {
    organization: { id: number; name: string }; items: Item[]; isOwner: boolean;
}) {
    const [busy, setBusy] = useState<number | null>(null);
    const [error, setError] = useState('');
    async function start(item: Item) {
        setBusy(item.id); setError('');
        try {
            const result = await axios.post(`/api/v1/organizations/${organization.id}/services/${item.id}/follow-up`, { version: item.version });
            router.visit(route('followups.show', [organization.id, result.data.data.id]));
        } catch { setError('Follow-up gagal dibuat. Muat ulang untuk memeriksa versi dan izin terbaru.'); }
        finally { setBusy(null); }
    }
    return <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Renewal & tindak lanjut</h2>}>
        <Head title="Renewal & tindak lanjut" />
        <div className="mx-auto max-w-6xl space-y-5 px-4 py-8">
            <div className="flex flex-wrap gap-4">
                <Link className="underline" href={route('registry.page', organization.id)}>Registry & data layanan</Link>
                {isOwner && <Link className="underline" href={route('client-templates.page', organization.id)}>Kelola template</Link>}
            </div>
            <p>Pengiriman pesan ke client dilakukan manual sesudah ditinjau. Pembayaran dan verifikasi masa aktif dicatat terpisah.</p>
            {error && <p role="alert" className="border border-red-300 bg-red-50 p-3">{error}</p>}
            {items.length === 0 && <section className="border bg-white p-6"><h3 className="font-semibold">Belum ada layanan dalam scope Anda</h3><p>Catat service subscription dan hubungkan dengan proyek di registry. Expiry unknown tetap perlu diverifikasi.</p></section>}
            <div className="grid gap-5 md:grid-cols-2">
                {items.map(item => <article key={item.id} className="space-y-3 border bg-white p-5">
                    <h3 className="text-lg font-semibold">{item.service_name || `Layanan #${item.id}`}</h3>
                    <p>{item.impacted_projects.map(p => p.name).join(', ') || 'Belum terhubung proyek'} · {item.service_kind}</p>
                    <dl className="space-y-1 text-sm">
                        <div><dt className="inline font-semibold">Expiry: </dt><dd className="inline">{item.date_precision === 'unknown' ? 'Unknown — verifikasi diperlukan' : item.date_precision === 'date' ? `${item.expiry_date?.slice(0, 10)} · jam tidak diketahui · ${item.source_timezone}` : observedTime(item.expires_at)}</dd></div>
                        <div><dt className="inline font-semibold">Penanggung jawab: </dt><dd className="inline">{item.action_owner}</dd></div>
                        <div><dt className="inline font-semibold">Pembayaran: </dt><dd className="inline">{item.payment_status}</dd></div>
                    </dl>
                    {item.followup ? <>
                        <p className="font-medium">{item.followup.state} · {item.followup.severity}{item.followup.overdue && ' · Overdue'}</p>
                        <p className="text-sm">Tindak lanjut: {observedTime(item.followup.next_followup_at)}{item.followup.snoozed_until && ` · Snoozed sampai ${observedTime(item.followup.snoozed_until)}`}</p>
                        <Link className="inline-block rounded border px-4 py-2 font-semibold" href={route('followups.show', [organization.id, item.followup.id])}>Buka follow-up</Link>
                    </> : <><p>Belum ada primary follow-up pada cycle aktif.</p>{item.can_manage && <button type="button" className="rounded border px-4 py-2 disabled:opacity-50" disabled={busy !== null} onClick={() => start(item)}>{busy === item.id ? 'Membuat…' : 'Buat follow-up & draft'}</button>}</>}
                    {item.history.length > 0 && <details><summary className="cursor-pointer">Histori cycle</summary><ul className="mt-2 space-y-1">{item.history.map(c => <li key={c.sequence}>Cycle {c.sequence} · {c.state} {c.followup_id && <Link className="underline" href={route('followups.show', [organization.id, c.followup_id])}>Histori follow-up</Link>}</li>)}</ul></details>}
                </article>)}
            </div>
        </div>
    </AuthenticatedLayout>;
}
