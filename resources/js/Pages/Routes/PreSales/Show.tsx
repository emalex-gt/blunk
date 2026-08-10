import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, ReactNode, useState } from 'react';

type Related = { id: number; name: string };

type PreSaleItem = {
    id: number;
    product_code?: string | null;
    product_barcode?: string | null;
    product_name?: string | null;
    quantity: number;
    picked_quantity?: number | null;
    picking_note?: string | null;
    reserved_quantity: number;
    unit_price: number;
    discount: number;
    total: number;
    physical_stock: number;
    reserved_total: number;
    available_stock: number;
};

type PreSale = {
    id: number;
    status: string;
    created_at?: string | null;
    submitted_at?: string | null;
    processing_started_at?: string | null;
    picked_at?: string | null;
    picked_by?: Related | null;
    cancelled_at?: string | null;
    cancellation_reason?: string | null;
    cancellation_note?: string | null;
    notes?: string | null;
    subtotal: number;
    discount_total: number;
    total: number;
    branch?: Related | null;
    zone?: Related | null;
    seller?: Related | null;
    customer?: {
        name: string;
        commercial_name?: string | null;
        contact_name?: string | null;
        doc_number?: string | null;
        address?: string | null;
        phone?: string | null;
    } | null;
    work_day?: { work_date?: string | null; status?: string | null; started_at?: string | null; closed_at?: string | null } | null;
    visit?: { status?: string | null; visit_order?: number | null; no_sale_reason?: string | null; no_sale_note?: string | null } | null;
    items: PreSaleItem[];
};

type Props = { preSale: PreSale };

const cancellationReasons = ['Cliente canceló', 'Producto no disponible', 'Duplicada', 'Error de captura', 'Otro'];

export default function Show({ preSale }: Props) {
    const [cancelOpen, setCancelOpen] = useState(false);
    const cancelForm = useForm({ cancellation_reason: '', cancellation_note: '' });

    const markProcessing = () => {
        router.post(route('routes.pre-sales.processing', preSale.id), {}, { preserveScroll: true });
    };

    const submitCancellation = (event: FormEvent) => {
        event.preventDefault();
        cancelForm.post(route('routes.pre-sales.cancel', preSale.id), {
            preserveScroll: true,
            onSuccess: () => setCancelOpen(false),
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title={`Preventa #${preSale.id}`} />
            <div className="mx-auto max-w-[1600px] space-y-5 px-4 py-6 sm:px-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <Link href={route('routes.pre-sales.index')} className="text-sm font-semibold text-indigo-600 hover:text-indigo-800">Volver a preventas</Link>
                        <h1 className="mt-2 text-2xl font-semibold text-slate-950">Preventa #{preSale.id}</h1>
                        <p className="text-sm text-slate-500">Revisión administrativa de pedido enviado desde ruta.</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {preSale.status === 'submitted' && (
                            <button onClick={markProcessing} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                                Marcar en preparación
                            </button>
                        )}
                        {['submitted', 'processing'].includes(preSale.status) && (
                            <Link href={route('routes.pre-sales.pick', preSale.id)} className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                                Preparar pedido
                            </Link>
                        )}
                        {['submitted', 'processing'].includes(preSale.status) && (
                            <button onClick={() => setCancelOpen(true)} className="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                                Cancelar preventa
                            </button>
                        )}
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <InfoCard title="Cliente">
                        <Info label="Nombre comercial" value={preSale.customer?.commercial_name || preSale.customer?.name} />
                        {preSale.customer?.commercial_name && <Info label="Nombre fiscal" value={preSale.customer.name} />}
                        <Info label="NIT" value={preSale.customer?.doc_number} />
                        <Info label="Contacto" value={preSale.customer?.contact_name} />
                        <Info label="Teléfono" value={preSale.customer?.phone} />
                        <Info label="Dirección" value={preSale.customer?.address} />
                    </InfoCard>
                    <InfoCard title="Ruta">
                        <Info label="Sucursal" value={preSale.branch?.name} />
                        <Info label="Zona" value={preSale.zone?.name} />
                        <Info label="Vendedor" value={preSale.seller?.name} />
                        <Info label="Jornada" value={preSale.work_day?.work_date} />
                        <Info label="Estado visita" value={preSale.visit?.status} />
                    </InfoCard>
                    <InfoCard title="Estado">
                        <Info label="Estado preventa" value={statusLabel(preSale.status)} />
                        <Info label="Creada" value={formatDate(preSale.created_at)} />
                        <Info label="Enviada" value={formatDate(preSale.submitted_at)} />
                        <Info label="En preparación" value={formatDate(preSale.processing_started_at)} />
                        <Info label="Lista para facturar" value={formatDate(preSale.picked_at)} />
                        <Info label="Preparada por" value={preSale.picked_by?.name} />
                        <Info label="Cancelada" value={formatDate(preSale.cancelled_at)} />
                        {preSale.cancellation_reason && <Info label="Motivo cancelación" value={preSale.cancellation_reason} />}
                    </InfoCard>
                </div>

                {preSale.notes && (
                    <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <h2 className="text-sm font-semibold text-slate-900">Notas</h2>
                        <p className="mt-2 text-sm text-slate-600">{preSale.notes}</p>
                    </section>
                )}

                <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div className="border-b border-slate-100 p-4">
                        <h2 className="text-sm font-semibold text-slate-900">Productos</h2>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                                <tr>
                                    <th className="px-4 py-3">Código</th>
                                    <th className="px-4 py-3">Producto</th>
                                    <th className="px-4 py-3">Solicitado</th>
                                    <th className="px-4 py-3">Reservado</th>
                                    <th className="px-4 py-3">Preparado</th>
                                    <th className="px-4 py-3">Precio</th>
                                    <th className="px-4 py-3">Subtotal</th>
                                    <th className="px-4 py-3">Stock físico</th>
                                    <th className="px-4 py-3">Reservado total</th>
                                    <th className="px-4 py-3">Disponible</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {preSale.items.map((item) => (
                                    <tr key={item.id}>
                                        <td className="px-4 py-3">
                                            {item.product_code || '-'}
                                            {item.product_barcode && <div className="text-xs text-slate-500">{item.product_barcode}</div>}
                                        </td>
                                        <td className="px-4 py-3 font-medium text-slate-900">{item.product_name}</td>
                                        <td className="px-4 py-3">{formatNumber(item.quantity)}</td>
                                        <td className="px-4 py-3">{formatNumber(item.reserved_quantity)}</td>
                                        <td className="px-4 py-3">
                                            {item.picked_quantity === null || item.picked_quantity === undefined ? '-' : formatNumber(item.picked_quantity)}
                                            {item.picking_note && <div className="text-xs text-slate-500">{item.picking_note}</div>}
                                        </td>
                                        <td className="px-4 py-3">Q {formatMoney(item.unit_price)}</td>
                                        <td className="px-4 py-3">Q {formatMoney(item.total)}</td>
                                        <td className="px-4 py-3">{formatNumber(item.physical_stock)}</td>
                                        <td className="px-4 py-3">{formatNumber(item.reserved_total)}</td>
                                        <td className="px-4 py-3">{formatNumber(item.available_stock)}</td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot className="bg-slate-50 text-sm font-semibold text-slate-900">
                                <tr>
                                    <td colSpan={6} className="px-4 py-3 text-right">Total</td>
                                    <td className="px-4 py-3">Q {formatMoney(preSale.total)}</td>
                                    <td colSpan={3}></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>
            </div>

            {cancelOpen && (
                <div className="fixed inset-0 z-50 flex items-end bg-slate-950/50 p-4 sm:items-center sm:justify-center">
                    <form onSubmit={submitCancellation} className="w-full rounded-xl bg-white p-5 shadow-xl sm:max-w-lg">
                        <h2 className="text-lg font-semibold text-slate-950">Cancelar preventa</h2>
                        <p className="mt-2 text-sm text-slate-600">Se liberarán las reservas. No se descontará stock físico ni se creará venta.</p>
                        <label className="mt-4 block">
                            <span className="text-xs font-semibold text-slate-500">Motivo</span>
                            <select value={cancelForm.data.cancellation_reason} onChange={(event) => cancelForm.setData('cancellation_reason', event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-200 text-sm">
                                <option value="">Selecciona</option>
                                {cancellationReasons.map((reason) => <option key={reason} value={reason}>{reason}</option>)}
                            </select>
                            {cancelForm.errors.cancellation_reason && <p className="mt-1 text-xs font-semibold text-red-600">{cancelForm.errors.cancellation_reason}</p>}
                        </label>
                        <label className="mt-3 block">
                            <span className="text-xs font-semibold text-slate-500">Observación</span>
                            <textarea value={cancelForm.data.cancellation_note} onChange={(event) => cancelForm.setData('cancellation_note', event.target.value)} rows={4} className="mt-1 w-full rounded-lg border-slate-200 text-sm" />
                            {cancelForm.errors.cancellation_note && <p className="mt-1 text-xs font-semibold text-red-600">{cancelForm.errors.cancellation_note}</p>}
                        </label>
                        <div className="mt-5 flex justify-end gap-2">
                            <button type="button" onClick={() => setCancelOpen(false)} disabled={cancelForm.processing} className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60">
                                Volver
                            </button>
                            <button disabled={cancelForm.processing} className="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-60">
                                {cancelForm.processing ? 'Cancelando...' : 'Cancelar preventa'}
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

function InfoCard({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <h2 className="text-sm font-semibold text-slate-900">{title}</h2>
            <div className="mt-3 space-y-2">{children}</div>
        </section>
    );
}

function Info({ label, value }: { label: string; value?: string | number | null }) {
    return (
        <div>
            <div className="text-xs font-semibold uppercase text-slate-500">{label}</div>
            <div className="text-sm text-slate-800">{value || '-'}</div>
        </div>
    );
}

function statusLabel(status: string) {
    const labels: Record<string, string> = {
        draft: 'Borrador',
        submitted: 'Enviada',
        processing: 'En preparación',
        picked: 'Listo para facturar',
        cancelled: 'Cancelada',
    };

    return labels[status] ?? status;
}

function formatDate(value?: string | null) {
    return value ? new Date(value).toLocaleString() : '-';
}

function formatNumber(value: number) {
    return Number(value ?? 0).toLocaleString('en-US', { maximumFractionDigits: 2 });
}

function formatMoney(value: number) {
    return Number(value ?? 0).toFixed(2);
}
