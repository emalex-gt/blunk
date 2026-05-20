import SuperAdminLayout from '@/Layouts/SuperAdminLayout';
import { Link } from '@inertiajs/react';

type Stats = {
    tenants: number;
    active_tenants: number;
    users: number;
    active_subscriptions: number;
};

export default function Dashboard({ stats }: { stats: Stats }) {
    const cards = [
        ['Negocios', stats.tenants],
        ['Negocios activos', stats.active_tenants],
        ['Usuarios', stats.users],
        ['Suscripciones activas', stats.active_subscriptions],
    ];

    return (
        <SuperAdminLayout
            title="Panel administrativo"
            actions={
                <Link
                    href={route('super-admin.tenants.create')}
                    className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                >
                    Crear negocio
                </Link>
            }
        >
            <div className="space-y-6">
                <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 className="text-xl font-semibold text-gray-900">Resumen de plataforma</h2>
                            <p className="mt-1 text-sm text-gray-500">
                                Estado general de negocios, usuarios y suscripciones.
                            </p>
                        </div>
                        <Link
                            href={route('super-admin.tenants.index')}
                            className="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100"
                        >
                            Ver negocios
                        </Link>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {cards.map(([label, value]) => (
                        <div
                            key={label}
                            className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm"
                        >
                            <div className="text-sm font-medium text-gray-500">{label}</div>
                            <div className="mt-3 text-3xl font-bold text-gray-900">{value}</div>
                        </div>
                    ))}
                </div>

                <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <Link
                        href={route('super-admin.tenants.index')}
                        className="inline-flex rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                    >
                        Administrar negocios
                    </Link>
                </div>
            </div>
        </SuperAdminLayout>
    );
}
