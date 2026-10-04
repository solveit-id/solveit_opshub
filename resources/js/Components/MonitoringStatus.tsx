const labels: Record<string, string> = { healthy: '✓ Sehat', warning: '⚠ Perhatian', critical: '! Kritis', unknown: '? Belum diketahui', fresh: '● Data terkini', stale: '◷ Data kedaluwarsa', never_observed: '○ Belum diamati', up: '✓ Up', down: '! Down', suspect: '⚠ Suspect', recovering: '◷ Memulihkan', paused: 'Ⅱ Dijeda', pass: '✓ Lolos', fail: '! Gagal', warn: '⚠ Peringatan', unsupported: '○ Tidak didukung', not_configured: '○ Belum dikonfigurasi' };
export default function MonitoringStatus({ value }: { value: string }) {
    return <span className="inline-flex rounded border border-slate-300 bg-slate-50 px-2 py-1 text-xs font-semibold text-slate-800">{labels[value] ?? value}</span>;
}
export function observedTime(value: string | null, timezone = 'Asia/Jakarta') {
    return value ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'medium', timeZone: timezone }).format(new Date(value)) : 'Belum tersedia';
}
