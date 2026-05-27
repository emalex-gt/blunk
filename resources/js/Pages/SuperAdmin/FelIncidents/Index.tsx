import SuperAdminLayout from '@/Layouts/SuperAdminLayout';
import { Link, router } from '@inertiajs/react';

type Incident = {
    id: number;
    business: { id: number; name: string } | null;
    sale_id: number;
    internal_reference: string;
    type: string;
    severity: string;
    status: string;
    message: string;
    created_at: string | null;
};

type Paginated<T> = {
    data: T[];
};

const statusLabels: Record<string, string> = {
    open: 'Abierta',
    reviewed: 'Revisada',
    resolved: 'Resuelta',
};

export default function Index({ incidents }: { incidents: Paginated<Incident> }) {
    function markReviewed(id: number) {
        router.post(route('super-admin.fel-incidents.review', id), {}, { preserveScroll: true });
    }

    function resolve(id: number) {
        router.post(route('super-admin.fel-incidents.resolve', id), {}, { preserveScroll: true });
    }

    return (
        <SuperAdminLayout title="Incidencias FEL">
            <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                {incidents.data.length === 0 ? (
                    <div className="py-16 text-center">
                        <h2 className="text-xl font-semibold text-gray-900">No hay incidencias FEL</h2>
                        <p className="mt-2 text-sm text-gray-500">Las certificaciones pendientes de revisar aparecerán aquí.</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    <th className="px-4 py-3">Empresa</th>
                                    <th className="px-4 py-3">Venta</th>
                                    <th className="px-4 py-3">Referencia interna</th>
                                    <th className="px-4 py-3">Tipo</th>
                                    <th className="px-4 py-3">Estado</th>
                                    <th className="px-4 py-3">Fecha</th>
                                    <th className="px-4 py-3 text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {incidents.data.map((incident) => (
                                    <tr key={incident.id} className="align-top hover:bg-gray-50">
                                        <td className="px-4 py-3 font-semibold text-gray-900">
                                            {incident.business?.name ?? '-'}
                                            <div className="text-xs font-normal text-gray-500">{incident.message}</div>
                                        </td>
                                        <td className="px-4 py-3 text-gray-600">#{incident.sale_id}</td>
                                        <td className="px-4 py-3 font-mono text-xs text-gray-700">{incident.internal_reference}</td>
                                        <td className="px-4 py-3 text-gray-600">{incident.type}</td>
                                        <td className="px-4 py-3">
                                            <span className="rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-700">
                                                {statusLabels[incident.status] ?? incident.status}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-gray-600">{incident.created_at ?? '-'}</td>
                                        <td className="px-4 py-3">
                                            <div className="flex justify-end gap-2">
                                                <Link
                                                    href={route('sales.show', incident.sale_id)}
                                                    className="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-100"
                                                >
                                                    Ver venta
                                                </Link>
                                                {incident.status === 'open' && (
                                                    <button
                                                        type="button"
                                                        onClick={() => markReviewed(incident.id)}
                                                        className="rounded-md border border-indigo-200 px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-50"
                                                    >
                                                        Marcar revisada
                                                    </button>
                                                )}
                                                {incident.status !== 'resolved' && (
                                                    <button
                                                        type="button"
                                                        onClick={() => resolve(incident.id)}
                                                        className="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700"
                                                    >
                                                        Resolver
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </SuperAdminLayout>
    );
}
