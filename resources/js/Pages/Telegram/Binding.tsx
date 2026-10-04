import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';

type Intent = { id: number; state: string; candidate_user_id: string | null; expires_at: string };
export default function Binding({ organization, binding, intent }: { organization: { id: number; name: string }; binding: { enabled: boolean; telegram_user_id: string } | null; intent: Intent | null }) {
    const [command, setCommand] = useState(''); const [expected, setExpected] = useState(''); const [confirmed, setConfirmed] = useState(false); const [message, setMessage] = useState(''); const [busy, setBusy] = useState(false);
    const base = `/api/v1/organizations/${organization.id}/telegram-binding`;
    const run = async (operation: () => Promise<void>) => { setBusy(true); setMessage(''); try { await operation(); router.reload(); } catch (e) { setMessage(axios.isAxiosError(e) ? (e.response?.status === 403 ? 'Konfirmasikan password/akses terlebih dahulu.' : e.response?.status === 409 ? 'Intent berubah/kedaluwarsa atau identity tidak cocok. Buat intent baru.' : 'Permintaan ditolak. Bot harus dikonfigurasi Owner.') : 'Permintaan gagal.'); } finally { setBusy(false); } };
    return <AuthenticatedLayout header={<h1 className="text-xl font-semibold">Binding Telegram pribadi</h1>}><Head title="Binding Telegram" /><div className="mx-auto max-w-3xl space-y-4 p-4 sm:p-6">
        <p>Mulai dari session OpsHub Anda. Kirim token sekali di private chat bot organisasi, lalu kembali dan konfirmasikan ID Telegram Anda. Token kedaluwarsa setelah 10 menit. Username tidak digunakan untuk binding.</p>
        <Link href={route('password.confirm')} className="text-blue-700 underline">Konfirmasikan password sebelum membuat/mengonfirmasi/mencabut binding</Link>
        <p>Binding: {binding?.enabled ? `aktif · ID ${binding.telegram_user_id}` : 'belum aktif/dicabut'}</p>
        <button disabled={busy} className="rounded bg-gray-800 px-4 py-2 text-white" onClick={() => void run(async () => { const r = await axios.post(base); setCommand(r.data.data.command); setConfirmed(false); setExpected(''); })}>Buat token sekali pakai</button>
        {command && <div className="rounded bg-white p-4"><p>Salin perintah ke private chat bot yang benar. Jangan bagikan token ini.</p><code className="block break-all p-2">{command}</code></div>}
        <button disabled={busy} className="ml-3 underline" onClick={() => { setCommand(''); router.reload(); }}>Refresh kandidat setelah /start</button>
        {intent && <p>Intent #{intent.id}: {intent.state} · sampai {new Date(intent.expires_at).toLocaleString('id-ID', { timeZone: 'Asia/Jakarta' })} WIB</p>}
        {intent?.state === 'candidate' && <form className="space-y-4 rounded bg-white p-4" onSubmit={e => { e.preventDefault(); void run(async () => { await axios.post(`${base}/${intent.id}/confirm`, { telegram_user_id: expected, private_identity_confirmed: confirmed }); setCommand(''); setMessage('Binding dikonfirmasi.'); }); }}><p>Kandidat Telegram ID: <strong>{intent.candidate_user_id}</strong>. Pastikan ID ini milik Anda sebelum konfirmasi. Jika berbeda, buat token baru.</p><label className="block">Ketik ulang ID Telegram Anda<input required className="mt-1 w-full rounded border-gray-300" value={expected} onChange={e => setExpected(e.target.value)} /></label><label className="flex gap-2"><input required type="checkbox" checked={confirmed} onChange={e => setConfirmed(e.target.checked)} />Saya melakukan /start di private chat dan ID ini milik saya</label><button disabled={busy} className="rounded bg-gray-800 px-4 py-2 text-white">Konfirmasikan binding</button></form>}
        {binding?.enabled && <button disabled={busy} className="underline" onClick={() => void run(async () => { await axios.delete(base); setMessage('Binding dicabut.'); })}>Cabut binding pribadi</button>}
        {message && <p role="status">{message}</p>}
    </div></AuthenticatedLayout>;
}
