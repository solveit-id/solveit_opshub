import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

type Client = {
    id: number;
    name: string;
    status: string;
    projects_count: number;
    contacts_count: number;
};

type Project = {
    id: number;
    code: string;
    name: string;
    lifecycle: string;
    criticality: string;
    stack_tags: string[] | null;
    client: { id: number; name: string };
    environments: { id: number; kind: string; display_name: string }[];
};

type Asset = {
    id: number;
    kind: string;
    canonical_identity: string;
    responsibility: string;
    source: string;
    verified_at: string | null;
    usages_count: number;
};

const lifecycleTone: Record<string, string> = {
    active: 'bg-emerald-50 text-emerald-800 ring-emerald-700/20',
    onboarding: 'bg-sky-50 text-sky-800 ring-sky-700/20',
    paused: 'bg-amber-50 text-amber-800 ring-amber-700/20',
    archived: 'bg-stone-100 text-stone-700 ring-stone-500/20',
    draft: 'bg-slate-100 text-slate-700 ring-slate-500/20',
};

function Badge({ value }: { value: string }) {
    return (
        <span
            className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${lifecycleTone[value] ?? 'bg-slate-100 text-slate-700 ring-slate-500/20'}`}
        >
            {value}
        </span>
    );
}

export default function RegistryIndex({
    organization,
    canManage,
    clients,
    projects,
    assets,
}: {
    organization: { id: number; name: string; timezone: string };
    canManage: boolean;
    clients: Client[];
    projects: Project[];
    assets: Asset[];
}) {
    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="text-sm font-medium text-slate-500">
                            {organization.name} · {organization.timezone}
                        </p>
                        <h2 className="text-xl font-semibold leading-tight text-slate-900">
                            Registry operasi
                        </h2>
                    </div>
                    <p className="text-sm text-slate-600">
                        {canManage
                            ? 'Perubahan registry dilakukan melalui API terotorisasi.'
                            : 'Akses baca saja untuk scope organisasi ini.'}
                    </p>
                </div>
            }
        >
            <Head title="Registry operasi" />

            <div className="mx-auto max-w-7xl space-y-10 px-4 py-8 sm:px-6 lg:px-8">
                <section aria-labelledby="projects-heading">
                    <div className="mb-3 flex items-baseline justify-between gap-4 border-b border-slate-200 pb-3">
                        <div>
                            <h3 id="projects-heading" className="text-base font-semibold text-slate-900">
                                Proyek
                            </h3>
                            <p className="mt-1 text-sm text-slate-600">
                                Lifecycle dan environment dicatat terpisah agar konfigurasi produksi tidak ikut berubah saat staging diedit.
                            </p>
                        </div>
                        <span className="text-sm tabular-nums text-slate-500">{projects.length} terdaftar</span>
                    </div>
                    {projects.length === 0 ? (
                        <p className="border-l-4 border-slate-300 bg-white px-4 py-5 text-sm text-slate-600 shadow-sm">
                            Belum ada proyek. Tambahkan client dan proyek melalui endpoint registry yang sesuai sebelum monitoring diaktifkan.
                        </p>
                    ) : (
                        <div className="overflow-x-auto border border-slate-200 bg-white shadow-sm">
                            <table className="min-w-full divide-y divide-slate-200 text-left text-sm">
                                <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-600">
                                    <tr>
                                        <th className="px-4 py-3">Proyek</th>
                                        <th className="px-4 py-3">Client</th>
                                        <th className="px-4 py-3">Environment</th>
                                        <th className="px-4 py-3">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 text-slate-700">
                                    {projects.map((project) => (
                                        <tr key={project.id}>
                                            <td className="px-4 py-3">
                                                <Link className="font-semibold text-indigo-700 underline-offset-4 hover:underline" href={route('registry.projects.show', [organization.id, project.id])}>
                                                    {project.name}
                                                </Link>
                                                <p className="mt-0.5 font-mono text-xs text-slate-500">{project.code}</p>
                                            </td>
                                            <td className="px-4 py-3">{project.client.name}</td>
                                            <td className="px-4 py-3">
                                                {project.environments.length === 0 ? (
                                                    <span className="text-amber-700">Belum dikonfigurasi</span>
                                                ) : (
                                                    project.environments.map((environment) => environment.display_name).join(', ')
                                                )}
                                            </td>
                                            <td className="px-4 py-3"><Badge value={project.lifecycle} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <div className="grid gap-10 lg:grid-cols-2">
                    <section aria-labelledby="clients-heading">
                        <div className="mb-3 border-b border-slate-200 pb-3">
                            <h3 id="clients-heading" className="text-base font-semibold text-slate-900">Client & PIC</h3>
                            <p className="mt-1 text-sm text-slate-600">Kontak client bukan akun sistem dan tidak memberi akses masuk.</p>
                        </div>
                        {clients.length === 0 ? <p className="text-sm text-slate-600">Belum ada client terdaftar.</p> : (
                            <ul className="divide-y divide-slate-200 border-y border-slate-200 bg-white">
                                {clients.map((client) => (
                                    <li className="flex items-center justify-between gap-4 px-4 py-3" key={client.id}>
                                        <div><p className="font-medium text-slate-900">{client.name}</p><p className="text-xs text-slate-500">{client.projects_count} proyek · {client.contacts_count} kontak</p></div>
                                        <Badge value={client.status} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section aria-labelledby="assets-heading">
                        <div className="mb-3 border-b border-slate-200 pb-3">
                            <h3 id="assets-heading" className="text-base font-semibold text-slate-900">Asset canonical</h3>
                            <p className="mt-1 text-sm text-slate-600">Satu domain atau account yang dipakai bersama muncul sekali, lalu dihubungkan sebagai usage.</p>
                        </div>
                        {assets.length === 0 ? <p className="text-sm text-slate-600">Belum ada asset canonical. Data monitoring tidak boleh diasumsikan dari placeholder.</p> : (
                            <ul className="divide-y divide-slate-200 border-y border-slate-200 bg-white">
                                {assets.map((asset) => (
                                    <li className="px-4 py-3" key={asset.id}>
                                        <div className="flex items-start justify-between gap-3"><div className="min-w-0"><p className="truncate font-medium text-slate-900">{asset.canonical_identity}</p><p className="mt-0.5 text-xs text-slate-500">{asset.kind} · sumber: {asset.source}</p></div><span className="shrink-0 text-xs tabular-nums text-slate-500">{asset.usages_count} usage</span></div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
