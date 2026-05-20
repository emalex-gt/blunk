import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

type Branch = {
    id: number;
    name: string;
    code: string | null;
    address: string | null;
    phone: string | null;
    is_active: boolean;
};

export default function Index({ branches }: { branches: Branch[] }) {
    return (
        <AuthenticatedLayout>
            <Head title="Sucursales" />
            <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6">
                <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold text-slate-950">Sucursales</h1>
                        <p className="mt-1 text-sm text-slate-500">Gestiona las sucursales activas del tenant.</p>
                    </div>
                    <Link
                        href={route('branches.create')}
                        className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                    >
                        Nueva sucursal
                    </Link>
                </div>

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-3">Sucursal</th>
                                <th className="px-4 py-3">Código</th>
                                <th className="px-4 py-3">Dirección</th>
                                <th className="px-4 py-3">Teléfono</th>
                                <th className="px-4 py-3">Estado</th>
                                <th className="px-4 py-3 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {branches.map((branch) => (
                                <tr key={branch.id}>
                                    <td className="px-4 py-3 font-semibold text-slate-900">{branch.name}</td>
                                    <td className="px-4 py-3 text-slate-600">{branch.code ?? '-'}</td>
                                    <td className="px-4 py-3 text-slate-600">{branch.address ?? '-'}</td>
                                    <td className="px-4 py-3 text-slate-600">{branch.phone ?? '-'}</td>
                                    <td className="px-4 py-3">
                                        <span className={`rounded-full px-2 py-1 text-xs font-semibold ${branch.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>
                                            {branch.is_active ? 'Activa' : 'Inactiva'}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <Link href={route('branches.edit', branch.id)} className="font-semibold text-indigo-600 hover:text-indigo-700">
                                            Editar
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                            {branches.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="px-4 py-8 text-center text-slate-500">
                                        No hay sucursales registradas.
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
