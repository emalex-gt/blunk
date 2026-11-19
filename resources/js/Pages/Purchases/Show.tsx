import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatCurrency } from '@/utils/currency';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type PurchaseItem = {
    id: number;
    product_name: string;
    quantity: string;
    unit_cost: string;
    previous_cost: string;
    new_average_cost: string;
    total: string;
    product: { id: number; code: string | null; barcode: string | null } | null;
};

type Purchase = {
    id: number;
    business_number: number | null;
    supplier_invoice_number: string | null;
    created_at: string;
    total: string;
    transport_cost: string;
    note: string | null;
    payment_method: string | null;
    paid_from_cash: boolean;
    cash_register_session: { id: number } | null;
    supplier: { id: number; name: string } | null;
    branch: { id: number; name: string } | null;
    created_by: { id: number; name: string } | null;
    status: string;
    cancelled_at?: string | null;
    cancellation_reason?: string | null;
    cancelled_by?: { id: number; name: string } | null;
    items: PurchaseItem[];
};

export default function Show({ purchase, canCancel }: { purchase: Purchase; canCancel: boolean }) {
    const business = usePage().props.business as { country?: string | null } | null;
    const country = business?.country ?? 'GT';
    const purchaseNumber = `C-${purchase.business_number ?? purchase.id}`;
    const productsSubtotal = Number(purchase.total) - Number(purchase.transport_cost ?? 0);
    const [cancelOpen, setCancelOpen] = useState(false);
    const cancellation = useForm({ reason: '' });

    function submitCancellation(event: FormEvent) {
        event.preventDefault();
        cancellation.post(route('purchases.cancel', purchase.id), {
            preserveScroll: true,
            onSuccess: () => setCancelOpen(false),
        });
    }

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-xl font-semibold text-slate-950">Ver compra</h2>
                    <div className="flex flex-wrap gap-2">
                        <a
                            href={route('purchases.print', purchase.id)}
                            target="_blank"
                            rel="noreferrer"
                            className="rounded-xl bg-orange-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-orange-700"
                        >
                            Imprimir
                        </a>
                        {canCancel && (
                            <button type="button" onClick={() => setCancelOpen(true)} className="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-100">
                                Anular compra
                            </button>
                        )}
                        <Link
                            href={route('purchases.index')}
                            className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50"
                        >
                            Volver
                        </Link>
                    </div>
                </div>
            }
        >
            <Head title={`Compra ${purchaseNumber}`} />

            <div className="py-5">
                <div className="mx-auto max-w-[1800px] space-y-5 px-5 sm:px-6">
                    <section className="rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
                            <Summary label="Compra" value={purchaseNumber} />
                            <Summary label="Factura proveedor" value={purchase.supplier_invoice_number || 'Sin referencia'} />
                            <Summary label="Proveedor" value={purchase.supplier?.name ?? 'Sin proveedor'} />
                            <Summary label="Sucursal" value={purchase.branch?.name ?? '-'} />
                            <Summary label="Forma de pago" value={paymentMethodLabel(purchase.payment_method)} />
                            <Summary label="Usuario" value={purchase.created_by?.name ?? '-'} />
                            <Summary label="Subtotal productos" value={formatCurrency(productsSubtotal, country, 5)} />
                            <Summary label="Transporte" value={formatCurrency(purchase.transport_cost ?? 0, country, 2)} />
                            <Summary label="Total compra" value={formatCurrency(purchase.total, country, 5)} />
                        </div>
                        {purchase.paid_from_cash && (
                            <div className="mt-5 rounded-2xl border border-orange-100 bg-orange-50 p-4 text-sm font-semibold text-orange-700">
                                Compra pagada desde caja
                                {purchase.cash_register_session?.id ? ` #${purchase.cash_register_session.id}` : ''}
                            </div>
                        )}
                        {purchase.status === 'cancelled' && (
                            <div className="mt-5 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                                <div className="font-semibold">Compra anulada</div>
                                <div className="mt-1">{purchase.cancellation_reason ?? 'Sin motivo registrado.'}</div>
                                <div className="mt-1 text-xs">{purchase.cancelled_by?.name ?? '-'} · {purchase.cancelled_at ?? '-'}</div>
                            </div>
                        )}
                        {purchase.note && (
                            <div className="mt-5 rounded-2xl bg-slate-50 p-4 text-sm text-slate-600">
                                {purchase.note}
                            </div>
                        )}
                    </section>

                    <section className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white/95 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="p-5">
                            <h3 className="text-xl font-semibold text-slate-950">Productos comprados</h3>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead>
                                    <tr className="bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        <th className="px-4 py-3">Producto</th>
                                        <th className="px-4 py-3">Código</th>
                                        <th className="px-4 py-3 text-right">Cantidad</th>
                                        <th className="px-4 py-3 text-right">Costo anterior</th>
                                        <th className="px-4 py-3 text-right">Costo unitario</th>
                                        <th className="px-4 py-3 text-right">Nuevo costo promedio</th>
                                        <th className="px-4 py-3 text-right">Total</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {purchase.items.map((item) => (
                                        <tr key={item.id} className="transition-colors hover:bg-orange-50/30">
                                            <td className="px-4 py-3 font-semibold text-slate-950">{item.product_name}</td>
                                            <td className="px-4 py-3 text-slate-600">
                                                {item.product?.barcode || item.product?.code || '-'}
                                            </td>
                                            <td className="px-4 py-3 text-right text-slate-700">{item.quantity.replace(/\.0+$/, '')}</td>
                                            <td className="whitespace-nowrap px-4 py-3 text-right text-slate-700">
                                                {formatCurrency(item.previous_cost, country, 5)}
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3 text-right text-slate-700">
                                                {formatCurrency(item.unit_cost, country, 5)}
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-950">
                                                {formatCurrency(item.new_average_cost, country, 5)}
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-950">
                                                {formatCurrency(item.total, country, 5)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>
            {cancelOpen && (
                <div className="fixed inset-0 z-50 flex items-end bg-slate-950/40 p-4 sm:items-center sm:justify-center">
                    <form onSubmit={submitCancellation} className="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl">
                        <h3 className="text-lg font-bold text-slate-950">Anular compra {purchaseNumber}</h3>
                        <p className="mt-2 text-sm text-slate-600">Proveedor: {purchase.supplier?.name ?? 'Sin proveedor'}. Esta acción revierte el inventario únicamente si sigue disponible.</p>
                        <label className="mt-4 block text-sm font-semibold text-slate-700">Motivo de anulación
                            <textarea autoFocus value={cancellation.data.reason} onChange={(event) => cancellation.setData('reason', event.target.value)} className="mt-1 w-full rounded-xl border-slate-300" rows={3} />
                        </label>
                        {(cancellation.errors.reason || cancellation.errors.purchase) && <p className="mt-2 text-sm font-semibold text-red-700">{cancellation.errors.reason || cancellation.errors.purchase}</p>}
                        <div className="mt-5 flex justify-end gap-2"><button type="button" onClick={() => setCancelOpen(false)} className="rounded-xl border px-4 py-2 text-sm font-semibold">Cancelar</button><button disabled={cancellation.processing || cancellation.data.reason.trim() === ''} className="rounded-xl bg-red-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Confirmar anulación</button></div>
                    </form>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

function Summary({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-2 whitespace-nowrap text-xl font-bold text-slate-950">{value}</div>
        </div>
    );
}

function paymentMethodLabel(method?: string | null) {
    return {
        cash: 'Efectivo',
        card: 'Tarjeta',
        bank_transfer: 'Transferencia',
        check: 'Cheque',
        credit: 'Crédito',
        other: 'Otro',
    }[method ?? ''] ?? '-';
}
