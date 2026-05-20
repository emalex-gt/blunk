import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

type Transfer = {
    id: number;
    status: string;
    created_at: string;
    notes: string | null;
    from_branch: { id: number; name: string } | null;
    to_branch: { id: number; name: string } | null;
    created_by: { id: number; name: string } | null;
};

type Paginated<T> = {
    data: T[];
};

export default function Index({ transfers }: { transfers: Paginated<Transfer> }) {
    return (
        <AuthenticatedLayout>
            <Head title="Traslados" />
            <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6">
                <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold text-slate-950">Traslados</h1>
                        <p className="mt-1 text-sm text-slate-500">Movimientos de inventario entre sucursales.</p>
                    </div>
                    <Link href={route('inventory.transfers.create')} className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        Nuevo traslado
                    </Link>
                </div>

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-3">Fecha</th>
                                <th className="px-4 py-3">Origen</th>
                                <th className="px-4 py-3">Destino</th>
                                <th className="px-4 py-3">Usuario</th>
                                <th className="px-4 py-3">Estado</th>
                                <th className="px-4 py-3 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {transfers.data.map((transfer) => (
                                <tr key={transfer.id}>
                                    <td className="px-4 py-3 text-slate-600">{transfer.created_at}</td>
                                    <td className="px-4 py-3 font-semibold text-slate-900">{transfer.from_branch?.name ?? '-'}</td>
                                    <td className="px-4 py-3 font-semibold text-slate-900">{transfer.to_branch?.name ?? '-'}</td>
                                    <td className="px-4 py-3 text-slate-600">{transfer.created_by?.name ?? '-'}</td>
                                    <td className="px-4 py-3">
                                        <span className="rounded-full bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700">
                                            {transfer.status === 'completed' ? 'Completado' : transfer.status}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <Link href={route('inventory.transfers.show', transfer.id)} className="font-semibold text-indigo-600 hover:text-indigo-700">
                                            Ver
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                            {transfers.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="px-4 py-8 text-center text-slate-500">
                                        No hay traslados registrados.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
