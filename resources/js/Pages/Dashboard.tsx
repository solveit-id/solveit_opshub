import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

import { Link, usePage } from '@inertiajs/react';

export default function Dashboard() {
    const organization = usePage().props.organization as
        | { id: number; name: string }
        | undefined;
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Dashboard
                </h2>
            }
        >
            <Head title="Dashboard" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="p-6 text-gray-900">
                            {organization ? (
                                <Link
                                    className="font-medium text-indigo-700 underline underline-offset-4"
                                    href={route('monitoring.overview', organization.id)}
                                >
                                    Buka overview operasional {organization.name}
                                </Link>
                            ) : (
                                'Belum ada organisasi aktif yang dapat ditampilkan.'
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
