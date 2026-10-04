import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import MonitoringStatus, { observedTime } from '@/Components/MonitoringStatus';

type Usage = {
    id: number;
    purpose: string;
    asset: { id: number; kind: string; canonical_identity: string; responsibility: string; source: string; verified_at: string | null };
    environment: { id: number; kind: string; display_name: string } | null;
};

export default function RegistryProject({
    organization,
    project,
    monitoring,
}: {
    organization: { id: number; name: string };
    monitoring: { health: string; coverage: string; checks: { id: number; kind: string; state: string; freshness: string; last_observation: { completed_at: string; source_type: string; reason_code: string; fake: boolean } | null }[] };
    project: {
        id: number;
        name: string;
        code: string;
        lifecycle: string;
        criticality: string;
        stack_tags: string[] | null;
        notes: string | null;
        client: { name: string; status: string };
        environments: { id: number; kind: string; display_name: string }[];
        asset_usages: Usage[];
    };
}) {
    return (
        <AuthenticatedLayout
            header={
                <div>
                    <Link className="text-sm font-medium text-indigo-700 underline-offset-4 hover:underline" href={route('registry.page', organization.id)}>← Kembali ke registry</Link>
                    <h2 className="mt-2 text-xl font-semibold leading-tight text-slate-900">{project.name}</h2>
                    <p className="mt-1 font-mono text-sm text-slate-500">{project.code} · {project.client.name}</p>
                </div>
            }
        >
            <Head title={project.name} />
            <div className="mx-auto grid max-w-7xl gap-10 px-4 py-8 sm:px-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:px-8">
                <section aria-labelledby="asset-usages-heading">
                    <section className="mb-8 space-y-3" aria-labelledby="monitoring-heading"><h3 id="monitoring-heading" className="font-semibold">Monitoring</h3><MonitoringStatus value={monitoring.health} /><p className="text-sm">Coverage {monitoring.coverage} · Backup belum dikonfigurasi · Internal health tidak didukung.</p>{monitoring.checks.length === 0 ? <p className="bg-amber-50 p-3">Belum ada monitor terkonfigurasi; status website belum diketahui.</p> : monitoring.checks.map(check => <div className="border bg-white p-3" key={check.id}><span className="mr-3 font-semibold uppercase">{check.kind}</span><MonitoringStatus value={check.state} /> <MonitoringStatus value={check.freshness} /><p className="mt-2 text-sm">{observedTime(check.last_observation?.completed_at ?? null)} · {check.last_observation?.source_type ?? 'belum ada sumber'} {check.last_observation?.fake && '· Fixture'} · {check.last_observation?.reason_code}</p></div>)}</section>
                    <div className="border-b border-slate-200 pb-3"><h3 id="asset-usages-heading" className="text-base font-semibold text-slate-900">Usage asset</h3><p className="mt-1 text-sm text-slate-600">Relasi menunjukkan asset mana yang dipakai project dan environment tertentu; tidak ada pewarisan diam-diam antar environment.</p></div>
                    {project.asset_usages.length === 0 ? <p className="mt-4 border-l-4 border-amber-400 bg-amber-50 px-4 py-4 text-sm text-amber-900">Belum ada asset usage. Monitoring, backup, dan authorization belum dapat mengklaim coverage.</p> : (
                        <ul className="mt-4 divide-y divide-slate-200 border-y border-slate-200 bg-white">
                            {project.asset_usages.map((usage) => <li className="px-4 py-4" key={usage.id}><div className="flex flex-wrap items-baseline justify-between gap-2"><p className="font-medium text-slate-900">{usage.asset.canonical_identity}</p><span className="text-xs font-medium uppercase tracking-wide text-slate-500">{usage.asset.kind}</span></div><p className="mt-1 text-sm text-slate-600">Purpose: {usage.purpose} · Environment: {usage.environment?.display_name ?? 'lintas project'}</p><p className="mt-1 text-xs text-slate-500">Responsibility {usage.asset.responsibility} · sumber {usage.asset.source} · {usage.asset.verified_at ? 'terverifikasi' : 'belum diverifikasi'}</p></li>)}
                        </ul>
                    )}
                </section>
                <aside className="space-y-6">
                    <section className="border-t border-slate-300 pt-3"><h3 className="text-sm font-semibold text-slate-900">Lifecycle</h3><p className="mt-1 text-sm capitalize text-slate-700">{project.lifecycle}</p><p className="mt-3 text-xs leading-5 text-slate-500">Paused atau archived menghentikan pekerjaan masa depan yang belum dimulai; bukti dan histori tetap disimpan.</p></section>
                    <section className="border-t border-slate-300 pt-3"><h3 className="text-sm font-semibold text-slate-900">Environment</h3>{project.environments.length === 0 ? <p className="mt-1 text-sm text-amber-700">Belum dikonfigurasi</p> : <ul className="mt-2 space-y-2 text-sm text-slate-700">{project.environments.map((environment) => <li key={environment.id}><span className="font-medium">{environment.display_name}</span><span className="ml-2 text-xs text-slate-500">{environment.kind}</span></li>)}</ul>}</section>
                    <section className="border-t border-slate-300 pt-3"><h3 className="text-sm font-semibold text-slate-900">Teknologi</h3><p className="mt-1 text-sm text-slate-700">{project.stack_tags?.join(', ') || 'Belum dicatat'}</p></section>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
