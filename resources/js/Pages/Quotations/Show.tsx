import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Quotation = {
    id: number;
    number: string;
    status: string;
    issued_at: string;
    valid_until: string;
    expired: boolean;
    total: number;
    subtotal_before_discount: number;
    discount_amount: number;
    discount_reason?: string;
    note?: string;
    cancellation_reason?: string;
    converted_sale_id?: number | null;
    customer: Record<string, string | undefined>;
    branch?: { name: string };
    created_by?: { name: string };
    items?: Array<{ id: number; product_name: string; product_code?: string; quantity: number; unit_price: number; discount_amount: number; total: number }>;
};

export default function Show({ quotation }: { quotation: Quotation }) {
    const page = usePage();
    const permissions = ((page.props.auth as { permissions?: string[] })?.permissions ?? []);
    const isSuperAdmin = Boolean((page.props.auth as { user?: { is_super_admin?: boolean } })?.user?.is_super_admin);
    const [reason, setReason] = useState('');
    const can = (permission: string) => isSuperAdmin || permissions.includes(permission);
    const statusLabel = quotation.status === 'cancelled'
        ? 'COTIZACIÓN ANULADA'
        : quotation.status === 'converted'
            ? 'Convertida'
            : quotation.expired
                ? 'Vencida'
                : 'Vigente';

    return (
        <AuthenticatedLayout>
            <Head title={quotation.number} />
            <main className="mx-auto max-w-4xl space-y-5 px-4 py-5">
                <Link href={route('quotations.index')} className="font-semibold text-orange-700">← Cotizaciones</Link>
                <section className="rounded-2xl border bg-white p-5 shadow-sm">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h1 className="text-2xl font-bold text-slate-950">{quotation.number}</h1>
                            <p className="text-sm text-slate-600">Emitida: {quotation.issued_at} · Válida hasta: {quotation.valid_until}</p>
                        </div>
                        <strong className={quotation.status === 'cancelled' ? 'text-rose-700' : 'text-slate-700'}>{statusLabel}</strong>
                    </div>

                    <div className="mt-4 flex flex-wrap gap-2">
                        {can('quotations.print') && <a href={route('quotations.print', { quotation: quotation.id, format: 'ticket' })} target="_blank" className="rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700">Imprimir ticket</a>}
                        {can('quotations.print') && <a href={route('quotations.print', { quotation: quotation.id, format: 'document' })} target="_blank" className="rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700">Imprimir documento</a>}
                        {quotation.status === 'open' && can('quotations.create') && (
                            <Link href={route('quotations.edit', quotation.id)} className="rounded-xl border border-orange-300 px-3 py-2 text-sm font-semibold text-orange-700 hover:bg-orange-50">Editar</Link>
                        )}
                        {can('quotations.create') && (
                            <button type="button" onClick={() => router.post(route('quotations.duplicate', quotation.id))} className="rounded-xl border border-orange-300 px-3 py-2 text-sm font-semibold text-orange-700 hover:bg-orange-50">Duplicar cotización</button>
                        )}
                        {quotation.status === 'open' && can('quotations.convert') && (
                            <button
                                type="button"
                                onClick={() => {
                                    const confirmExpired = quotation.expired;
                                    if (confirmExpired && !window.confirm('Esta cotización ha vencido. Los precios y condiciones cotizados pueden no estar vigentes. ¿Deseas continuar?')) return;
                                    router.post(route('quotations.convert', quotation.id), { confirm_expired: confirmExpired });
                                }}
                                className="rounded-xl bg-orange-600 px-3 py-2 text-sm font-semibold text-white hover:bg-orange-700"
                            >
                                Convertir a venta
                            </button>
                        )}
                        {quotation.status === 'converted' && quotation.converted_sale_id && can('sales.view') && (
                            <Link href={route('sales.show', quotation.converted_sale_id)} className="rounded-xl border border-orange-300 px-3 py-2 text-sm font-semibold text-orange-700">Ver venta</Link>
                        )}
                    </div>

                    <div className="mt-5 rounded-xl bg-slate-50 p-4">
                        <h2 className="font-semibold text-slate-950">{quotation.customer.commercial_name || quotation.customer.name || 'Cliente no especificado'}</h2>
                        {quotation.customer.doc_number && <p className="text-sm text-slate-600">{quotation.customer.doc_type} {quotation.customer.doc_number}</p>}
                        {quotation.customer.address && <p className="mt-2 text-sm text-slate-700">{quotation.customer.address}</p>}
                        {quotation.customer.address_reference && <p className="text-sm text-slate-600">Referencia: {quotation.customer.address_reference}</p>}
                        {quotation.customer.phone && <p className="text-sm text-slate-600">{quotation.customer.phone}</p>}
                    </div>

                    <table className="mt-5 min-w-full text-sm">
                        <thead className="text-left text-slate-600"><tr><th className="py-2">Producto</th><th>Cantidad</th><th>Precio</th><th className="text-right">Total</th></tr></thead>
                        <tbody>
                            {quotation.items?.map((item) => <tr key={item.id} className="border-t"><td className="py-2">{item.product_name}</td><td>{item.quantity}</td><td>Q {Number(item.unit_price).toFixed(2)}</td><td className="text-right">Q {Number(item.total).toFixed(2)}</td></tr>)}
                        </tbody>
                    </table>
                    {quotation.discount_amount > 0 && <div className="mt-3 space-y-1 text-right text-sm text-slate-700"><p>Subtotal: Q {Number(quotation.subtotal_before_discount).toFixed(2)}</p><p>Descuento: -Q {Number(quotation.discount_amount).toFixed(2)}</p>{quotation.discount_reason && <p className="text-xs">Motivo: {quotation.discount_reason}</p>}</div>}
                    <p className="mt-4 text-right text-lg font-bold">Total: Q {Number(quotation.total).toFixed(2)}</p>
                    {quotation.note && <p className="mt-4 text-sm"><strong>Observaciones:</strong> {quotation.note}</p>}
                    <p className="mt-4 text-sm text-slate-600">Sucursal: {quotation.branch?.name ?? '-'} · Creada por: {quotation.created_by?.name ?? '-'}</p>
                    {quotation.cancellation_reason && <p className="mt-2 text-sm text-rose-700">Motivo de anulación: {quotation.cancellation_reason}</p>}

                    {quotation.status === 'open' && can('quotations.cancel') && (
                        <div className="mt-5 flex flex-wrap gap-2 border-t pt-4">
                            <input value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Motivo de anulación" className="rounded-xl border-slate-300" />
                            <button disabled={!reason.trim()} onClick={() => { if (window.confirm('¿Anular esta cotización? Esta acción no modifica inventario.')) router.post(route('quotations.cancel', quotation.id), { cancellation_reason: reason }); }} className="rounded-xl bg-rose-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">Anular</button>
                        </div>
                    )}
                </section>
            </main>
        </AuthenticatedLayout>
    );
}
