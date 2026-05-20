import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

type Transfer = {
    id: number;
    status: string;
    notes: string | null;
    created_at: string;
    from_branch: { id: number; name: string } | null;
    to_branch: { id: number; name: string } | null;
    created_by: { id: number; name: string } | null;
    lines: {
        id: number;
        quantity: number;
        product: { id: number; name: string; code: string | null } | null;
    }[];
};

export default function Show({ transfer }: { transfer: Transfer }) {
    return (
        <AuthenticatedLayout>
            <Head title={`Traslado #${transfer.id}`} />
            <div className="mx-auto max-w-5xl space-y-5 px-4 py-6 sm:px-6">
                <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h1 className="text-2xl font-semibold text-slate-950">Traslado #{transfer.id}</h1>
                            <p className="mt-1 text-sm text-slate-500">
                                {transfer.from_branch?.name ?? '-'} hacia {transfer.to_branch?.name ?? '-'}
                            </p>
                        </div>
                        <Link href={route('inventory.transfers.index')} className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            Volver
                        </Link>
                    </div>

                    <div className="mt-5 grid grid-cols-1 gap-4 text-sm md:grid-cols-3">
                        <Info label="Estado" value={transfer.status === 'completed' ? 'Completado' : transfer.status} />
                        <Info label="Creado por" value={transfer.created_by?.name ?? '-'} />
                        <Info label="Notas" value={transfer.notes ?? '-'} />
                    </div>
                </div>

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-3">Producto</th>
                                <th className="px-4 py-3">Código</th>
                                <th className="px-4 py-3 text-right">Cantidad</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {transfer.lines.map((line) => (
                                <tr key={line.id}>
                                    <td className="px-4 py-3 font-semibold text-slate-900">{line.product?.name ?? '-'}</td>
                                    <td className="px-4 py-3 text-slate-600">{line.product?.code ?? '-'}</td>
                                    <td className="px-4 py-3 text-right font-semibold text-slate-900">{line.quantity}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Info({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-1 font-semibold text-slate-900">{value}</div>
        </div>
    );
}
