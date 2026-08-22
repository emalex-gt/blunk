import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { makeOperationKey } from '@/lib/idempotency';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent, useRef, useState } from 'react';

type Related = { id: number; name: string };

type PickItem = {
    id: number;
    product_code?: string | null;
    product_barcode?: string | null;
    product_name?: string | null;
    quantity: number;
    reserved_quantity: number;
    picked_quantity: number;
    picking_note?: string | null;
    unit_price: number;
    physical_stock: number;
    reserved_total: number;
    available_stock: number;
};

type PreSale = {
    id: number;
    status: string;
    submitted_at?: string | null;
    processing_started_at?: string | null;
    branch?: Related | null;
    zone?: Related | null;
    seller?: Related | null;
    customer?: {
        name: string;
        commercial_name?: string | null;
        doc_number?: string | null;
    } | null;
    items: PickItem[];
};

type Props = { preSale: PreSale };

export default function Pick({ preSale }: Props) {
    const [idempotencyKey, setIdempotencyKey] = useState(() => makeOperationKey('pre-sale-pick'));
    const submitLockedRef = useRef(false);
    const form = useForm({
        idempotency_key: idempotencyKey,
        items: preSale.items.map((item) => ({
            id: item.id,
            picked_quantity: String(item.picked_quantity ?? Math.min(item.quantity, item.reserved_quantity)),
            picking_note: item.picking_note ?? '',
        })),
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (submitLockedRef.current || form.processing) {
            return;
        }

        submitLockedRef.current = true;
        form.setData('idempotency_key', idempotencyKey);
        form.post(route('routes.pre-sales.pick.store', preSale.id), {
            preserveScroll: true,
            onSuccess: () => setIdempotencyKey(makeOperationKey('pre-sale-pick')),
            onFinish: () => {
                submitLockedRef.current = false;
            },
        });
    };

    const updateItem = (index: number, field: 'picked_quantity' | 'picking_note', value: string) => {
        const items = [...form.data.items];
        items[index] = { ...items[index], [field]: value };
        form.setData('items', items);
    };

    return (
        <AuthenticatedLayout>
            <Head title={`Preparar preventa #${preSale.id}`} />
            <form onSubmit={submit} className="mx-auto max-w-[1800px] space-y-5 px-4 py-6 sm:px-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <Link href={route('routes.pre-sales.show', preSale.id)} className="text-sm font-semibold text-indigo-600 hover:text-indigo-800">
                            Volver al detalle
                        </Link>
                        <h1 className="mt-2 text-2xl font-semibold text-slate-950">Preparar pedido #{preSale.id}</h1>
                        <p className="text-sm text-slate-500">Ajusta las cantidades preparadas antes de dejar la preventa lista para facturar.</p>
                    </div>
                    <button disabled={form.processing} className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60">
                        {form.processing ? 'Guardando...' : 'Marcar listo para facturar'}
                    </button>
                </div>

                <section className="grid gap-4 md:grid-cols-4">
                    <Info label="Cliente" value={preSale.customer?.commercial_name || preSale.customer?.name} />
                    <Info label="NIT" value={preSale.customer?.doc_number} />
                    <Info label="Sucursal" value={preSale.branch?.name} />
                    <Info label="Zona" value={preSale.zone?.name} />
                </section>

                {(errors.pre_sale || errors.items) && (
                    <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                        {errors.pre_sale || errors.items}
                    </div>
                )}

                <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="min-w-[1120px] text-sm">
                            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                                <tr>
                                    <th className="px-4 py-3">Código</th>
                                    <th className="px-4 py-3">Producto</th>
                                    <th className="px-4 py-3">Solicitado</th>
                                    <th className="px-4 py-3">Reservado actual</th>
                                    <th className="px-4 py-3">Stock físico</th>
                                    <th className="px-4 py-3">Reservado total</th>
                                    <th className="px-4 py-3">Disponible</th>
                                    <th className="px-4 py-3">Preparado</th>
                                    <th className="px-4 py-3">Nota</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {preSale.items.map((item, index) => (
                                    <tr key={item.id}>
                                        <td className="px-4 py-3">
                                            {item.product_code || '-'}
                                            {item.product_barcode && <div className="text-xs text-slate-500">{item.product_barcode}</div>}
                                        </td>
                                        <td className="min-w-64 px-4 py-3 font-medium text-slate-900" title={item.product_name ?? ''}>{item.product_name}</td>
                                        <td className="px-4 py-3">{formatNumber(item.quantity)}</td>
                                        <td className="px-4 py-3">{formatNumber(item.reserved_quantity)}</td>
                                        <td className="px-4 py-3">{formatNumber(item.physical_stock)}</td>
                                        <td className="px-4 py-3">{formatNumber(item.reserved_total)}</td>
                                        <td className="px-4 py-3">{formatNumber(item.available_stock)}</td>
                                        <td className="px-4 py-3">
                                            <input
                                                type="number"
                                                min="0"
                                                max={Math.min(item.quantity, item.reserved_quantity)}
                                                step="0.0001"
                                                value={form.data.items[index]?.picked_quantity ?? ''}
                                                onChange={(event) => updateItem(index, 'picked_quantity', event.target.value)}
                                                className="h-10 w-28 rounded-lg border-slate-200 text-sm"
                                            />
                                        </td>
                                        <td className="px-4 py-3">
                                            <input
                                                value={form.data.items[index]?.picking_note ?? ''}
                                                onChange={(event) => updateItem(index, 'picking_note', event.target.value)}
                                                placeholder="Nota opcional"
                                                className="h-10 min-w-56 rounded-lg border-slate-200 text-sm"
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <div className="flex justify-end gap-2">
                    <Link href={route('routes.pre-sales.show', preSale.id)} className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Volver
                    </Link>
                    <button disabled={form.processing} className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60">
                        {form.processing ? 'Guardando...' : 'Marcar listo para facturar'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}

function Info({ label, value }: { label: string; value?: string | number | null }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div className="text-xs font-semibold uppercase text-slate-500">{label}</div>
            <div className="mt-1 text-sm font-medium text-slate-900">{value || '-'}</div>
        </div>
    );
}

function formatNumber(value: number) {
    return Number(value ?? 0).toLocaleString('en-US', { maximumFractionDigits: 4 });
}
