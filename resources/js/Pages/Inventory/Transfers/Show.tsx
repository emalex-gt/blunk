import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatTenantDateTime } from '@/lib/tenantDateTime';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Transfer = {
    id: number;
    status: string;
    notes: string | null;
    created_at: string;
    from_branch: { id: number; name: string } | null;
    to_branch: { id: number; name: string } | null;
    created_by: { id: number; name: string } | null;
    cancelled_at?: string | null;
    cancelled_by?: { id: number; name: string } | null;
    cancellation_reason?: string | null;
    lines: {
        id: number;
        quantity: string;
        product: { id: number; name: string; code: string | null; barcode: string | null } | null;
    }[];
};

export default function Show({ transfer, timezone, canCancel }: { transfer: Transfer; timezone: string; canCancel: boolean }) {
    const [cancelOpen, setCancelOpen] = useState(false);
    const cancellation = useForm({ reason: '' });

    function submitCancellation(event: FormEvent) {
        event.preventDefault();
        cancellation.post(route('inventory.transfers.cancel', transfer.id), { preserveScroll: true, onSuccess: () => setCancelOpen(false) });
    }

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
                        <div className="flex flex-wrap gap-2">
                            <a
                                href={route('inventory.transfers.print', transfer.id)}
                                target="_blank"
                                rel="noreferrer"
                                className="rounded-xl bg-orange-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-orange-700"
                            >
                                Imprimir
                            </a>
                            {canCancel && <button type="button" onClick={() => setCancelOpen(true)} className="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-100">Anular traslado</button>}
                            <Link href={route('inventory.transfers.index')} className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                Volver
                            </Link>
                        </div>
                    </div>

                    <div className="mt-5 grid grid-cols-1 gap-4 text-sm md:grid-cols-3">
                        <Info label="Estado" value={transfer.status === 'completed' ? 'Completado' : transfer.status} />
                        <Info label="Fecha" value={formatTenantDateTime(transfer.created_at, timezone)} />
                        <Info label="Creado por" value={transfer.created_by?.name ?? '-'} />
                        <Info label="Notas" value={transfer.notes ?? '-'} />
                    </div>
                    {transfer.status === 'cancelled' && <div className="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"><strong>Traslado anulado.</strong><div className="mt-1">{transfer.cancellation_reason ?? 'Sin motivo registrado.'}</div><div className="mt-1 text-xs">{transfer.cancelled_by?.name ?? '-'} · {transfer.cancelled_at ?? '-'}</div></div>}
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
                                    <td className="px-4 py-3 text-slate-600">{line.product?.code || line.product?.barcode || '-'}</td>
                                    <td className="px-4 py-3 text-right font-semibold text-slate-900">{line.quantity.replace(/\.0+$/, '')}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            {cancelOpen && <div className="fixed inset-0 z-50 flex items-end bg-slate-950/40 p-4 sm:items-center sm:justify-center"><form onSubmit={submitCancellation} className="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl"><h2 className="text-lg font-bold">Anular traslado #{transfer.id}</h2><p className="mt-2 text-sm text-slate-600">Las cantidades volverán desde {transfer.to_branch?.name ?? '-'} hacia {transfer.from_branch?.name ?? '-'} sólo si permanecen disponibles.</p><label className="mt-4 block text-sm font-semibold">Motivo<textarea autoFocus value={cancellation.data.reason} onChange={(event) => cancellation.setData('reason', event.target.value)} className="mt-1 w-full rounded-xl border-slate-300" rows={3} /></label>{(cancellation.errors.reason || cancellation.errors.transfer) && <p className="mt-2 text-sm font-semibold text-red-700">{cancellation.errors.reason || cancellation.errors.transfer}</p>}<div className="mt-5 flex justify-end gap-2"><button type="button" onClick={() => setCancelOpen(false)} className="rounded-xl border px-4 py-2 text-sm font-semibold">Cancelar</button><button disabled={cancellation.processing || cancellation.data.reason.trim() === ''} className="rounded-xl bg-red-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Confirmar anulación</button></div></form></div>}
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
