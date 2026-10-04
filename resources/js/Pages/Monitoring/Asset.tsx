import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { observedTime } from "@/Components/MonitoringStatus";
import { Head, Link } from "@inertiajs/react";
export default function Asset({
    organization,
    asset,
    usages,
}: {
    organization: { id: number; timezone: string };
    asset: {
        id: number;
        kind: string;
        canonical_identity: string;
        responsibility: string;
        source: string | null;
        verified_at: string | null;
        notes: string | null;
    };
    usages: {
        id: number;
        purpose: string;
        project: { id: number; name: string };
        environment: { display_name: string } | null;
    }[];
}) {
    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold">Asset canonical</h2>}
        >
            <Head title="Asset" />
            <div className="mx-auto max-w-5xl space-y-6 px-4 py-8">
                <Link
                    className="underline"
                    href={route("registry.page", organization.id)}
                >
                    ← Registry
                </Link>
                <section className="border bg-white p-5">
                    <h3 className="break-all text-lg font-semibold">
                        {asset.canonical_identity}
                    </h3>
                    <p className="mt-3">
                        {asset.kind} · Responsibility {asset.responsibility}
                    </p>
                    <p className="mt-2">
                        Sumber {asset.source ?? "belum dicatat"} · Verifikasi{" "}
                        {observedTime(asset.verified_at, organization.timezone)}
                    </p>
                    <p className="mt-3">{asset.notes}</p>
                </section>
                <section>
                    <h3 className="font-semibold">
                        Usage yang boleh Anda lihat
                    </h3>
                    {usages.length === 0 ? (
                        <p className="mt-3">
                            Belum ada usage. Coverage belum terkonfigurasi.
                        </p>
                    ) : (
                        <ul className="mt-3 divide-y border-y bg-white">
                            {usages.map((usage) => (
                                <li className="p-4" key={usage.id}>
                                    <Link
                                        className="underline"
                                        href={route("registry.projects.show", [
                                            organization.id,
                                            usage.project.id,
                                        ])}
                                    >
                                        {usage.project.name}
                                    </Link>{" "}
                                    ·{" "}
                                    {usage.environment?.display_name ??
                                        "lintas project"}{" "}
                                    · {usage.purpose}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
