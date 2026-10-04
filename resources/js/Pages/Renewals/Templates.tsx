import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';

type Template = { template_key: string; version: number; published_version: number; current: { body: string; mandatory_variables: string[]; allowed_variables: string[]; trigger: string } };
export default function Templates({ organization, templates }: { organization: { id: number }; templates: Template[] }) {
    const [selected, setSelected] = useState(templates[0].template_key);
    const current = templates.find(t => t.template_key === selected)!;
    const [body, setBody] = useState(current.current.body);
    const [variables, setVariables] = useState('{}');
    const [preview, setPreview] = useState<{ status: string; body: string; missing: string[] } | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    async function send(action: 'preview' | 'publish') {
        setBusy(true); setError('');
        try {
            const result = await axios.post(`/api/v1/organizations/${organization.id}/client-templates/${selected}/${action}`, { body, version: current.version, variables: JSON.parse(variables) });
            if (action === 'preview') setPreview(result.data.data);
            else router.reload({ onSuccess: () => { setPreview(null); } });
        } catch (e) { setError(axios.isAxiosError(e) && e.response?.status === 409 ? 'Versi berubah. Muat ulang sebelum publish.' : 'Preview/publish gagal. Periksa syntax, JSON variables dan data sensitif.'); }
        finally { setBusy(false); }
    }
    return <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Template client · Owner</h2>}>
        <Head title="Template client" />
        <div className="mx-auto max-w-5xl space-y-5 px-4 py-8">
            <Link className="underline" href={route('renewals.index', organization.id)}>Kembali ke renewal</Link>
            <p>Publish membuat versi baru. Body histori contact tidak diganti. Preview harus ditinjau dan bukan draft siap kirim.</p>
            <label className="block">Template<select className="mt-1 block w-full rounded border-slate-300" value={selected} onChange={e => { const t = templates.find(x => x.template_key === e.target.value)!; setSelected(t.template_key); setBody(t.current.body); setPreview(null); setError(''); }}>{templates.map(t => <option key={t.template_key}>{t.template_key}</option>)}</select></label>
            <p>Published v{current.published_version} · trigger {current.current.trigger}</p>
            <p>Mandatory: {current.current.mandatory_variables.join(', ')}</p>
            <details><summary>Allowed variables dan syntax</summary><p>{current.current.allowed_variables.join(', ')}</p><p>Variable: {'{{variable_name}}'}. Conditional utuh: {'[[if provider_name]]Provider {{provider_name}}.[[endif]]'}. Tidak ada nesting, secret atau URL.</p></details>
            <label className="block">Wording<textarea className="mt-1 block min-h-72 w-full rounded border-slate-300" maxLength={20000} value={body} onChange={e => { setBody(e.target.value); setPreview(null); }} /></label>
            <label className="block">Variables preview (JSON plain text, tanpa data client nyata/secret)<textarea className="mt-1 block min-h-24 w-full rounded border-slate-300 font-mono" value={variables} onChange={e => { setVariables(e.target.value); setPreview(null); }} /></label>
            <div className="flex gap-3"><button type="button" disabled={busy} className="rounded border px-4 py-2 disabled:opacity-50" onClick={() => send('preview')}>Preview</button><button type="button" disabled={busy || !preview || preview.status !== 'ready'} className="rounded border px-4 py-2 disabled:opacity-50" onClick={() => send('publish')}>Publish versi baru</button></div>
            {error && <p role="alert">{error} <button className="underline" onClick={() => router.reload()}>Muat ulang</button></p>}
            {preview && <section className="space-y-3 border bg-white p-5"><h3 className="font-semibold">Preview · {preview.status}</h3>{preview.missing.length > 0 && <p>Missing: {preview.missing.join(', ')}</p>}<pre className="whitespace-pre-wrap break-words font-sans">{preview.body}</pre></section>}
        </div>
    </AuthenticatedLayout>;
}
