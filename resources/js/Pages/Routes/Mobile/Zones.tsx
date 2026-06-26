import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';

type Zone = { id: number; name: string; description: string | null; active_customers_count: number };

export default function Zones({ zones, branch }: { zones: Zone[]; branch: { id: number; name: string } }) {
    return (
        <AuthenticatedLayout>
            <Head title="Mis rutas" />
            <div className="mx-auto max-w-xl space-y-4 px-4 py-5">
                <div>
                    <h1 className="text-2xl font-semibold text-slate-950">Mis rutas</h1>
                    <p className="text-sm text-slate-500">Sucursal: {branch.name}</p>
                </div>
                {zones.length === 0 && (
                    <div className="rounded-lg bg-white p-5 text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">
                        No tienes zonas asignadas para esta sucursal.
                    </div>
                )}
                {zones.map((zone) => (
                    <div key={zone.id} className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <h2 className="text-lg font-semibold text-slate-950">{zone.name}</h2>
                                <p className="mt-1 text-sm text-slate-500">{zone.description ?? 'Zona de preventa'}</p>
                                <p className="mt-2 text-sm font-medium text-indigo-700">{zone.active_customers_count} clientes</p>
                            </div>
                        </div>
                        <button
                            onClick={() => router.post(route('routes.mobile.zones.work-day.start', zone.id))}
                            className="mt-4 w-full rounded-xl bg-indigo-600 px-4 py-3 text-base font-semibold text-white"
                        >
                            Trabajar zona
                        </button>
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
