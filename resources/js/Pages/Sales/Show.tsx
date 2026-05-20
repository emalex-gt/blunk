import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Toast from '@/Components/Toast';
import { useToast } from '@/hooks/useToast';
import { formatCurrency } from '@/utils/currency';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type SaleDetail = {
    id: number;
    status: 'completed' | 'cancelled';
    document_type: 'receipt' | 'invoice';
    created_at_local: string | null;
    cancelled_at_local: string | null;
    cancellation_reason: string | null;
    total: number;
    subtotal_before_discount: number;
    discount_type: 'fixed' | 'percent' | null;
    discount_value: number;
    discount_amount: number;
    discount_reason: string | null;
    payment_method: string;
    note: string | null;
    certification_status: string | null;
    fel_uuid: string | null;
    fel_series: string | null;
    fel_number: string | null;
    fel_certified_at: string | null;
    has_fel_xml: boolean;
    has_fel_html: boolean;
    has_fel_pdf: boolean;
    created_by: string | null;
    cancelled_by: string | null;
    customer: {
        name: string;
        doc_type: string | null;
        doc_number: string | null;
        address: string | null;
        phone: string | null;
    } | null;
    items: {
        id: number;
        product_name: string;
        code: string | null;
        quantity: number;
        unit_price: number;
        price_type_name: string | null;
        price_source: 'price_list' | 'last_customer_price' | 'manual' | null;
        manual_price: boolean;
        discount_amount: number;
        total_before_discount: number;
        total_after_discount: number;
        total: number;
    }[];
    payments: {
        id: number;
        method: string;
        amount: number;
        reference: string | null;
        details: Record<string, string>;
    }[];
    electronic_document: {
        id: number;
        status: string;
        uuid: string | null;
        series: string | null;
        number: string | null;
        certification_date: string | null;
        error_message: string | null;
        cancelled_at: string | null;
        has_printable_document: boolean;
        technical_response: Record<string, unknown> | null;
    } | null;
};

const paymentLabels: Record<string, string> = {
    cash: 'Efectivo',
    card: 'Tarjeta',
    transfer: 'Transferencia',
    check: 'Cheque',
    mercadopago: 'MercadoPago',
    bizum: 'Bizum',
    other: 'Otro',
};

const priceSourceLabels: Record<string, string> = {
    price_list: 'Lista',
    last_customer_price: 'Ultimo cliente',
    manual: 'Manual',
};

function priceSourceLabel(source: SaleDetail['items'][number]['price_source']) {
    return source ? (priceSourceLabels[source] ?? source) : '-';
}

export default function Show({ sale }: { sale: SaleDetail; canCancel?: boolean }) {
    const page = usePage();
    const business = page.props.business as { country?: string | null } | null;
    const auth = page.props.auth as {
        user?: {
            role?: string | null;
            is_super_admin?: boolean | null;
        } | null;
        permissions?: string[];
    };
    const country = business?.country ?? 'GT';
    const [showCancelModal, setShowCancelModal] = useState(false);
    const [showTechnicalModal, setShowTechnicalModal] = useState(false);
    const cancelForm = useForm({ reason: '' });
    const toast = useToast();
    const isCancelled = sale.status === 'cancelled';
    const canCancelSale =
        sale.status !== 'cancelled' &&
        (
            Boolean(auth.user?.is_super_admin) ||
            auth.user?.role === 'owner' ||
            auth.user?.role === 'admin'
        );
    const canViewFelDocuments = Boolean(auth.user?.is_super_admin)
        || (auth.permissions ?? []).includes('fel.documents.view');

    function printSale() {
        if (sale.document_type === 'receipt') {
            window.open(route('sales.receipt', sale.id), '_blank');
            return;
        }

        if (!canViewFelDocuments) {
            toast.error('No tienes permisos para ver documentos FEL.');
            return;
        }

        if (sale.fel_uuid) {
            window.open(route('sales.fel-document', sale.id), '_blank');
            return;
        }

        if (sale.electronic_document?.status === 'certified' && sale.electronic_document.has_printable_document) {
            window.open(route('sales.invoice-document', sale.id), '_blank');
            return;
        }

        toast.info('No hay documento imprimible disponible.');
    }

    function submitCancel(event: FormEvent) {
        event.preventDefault();

        cancelForm.post(route('sales.cancel', sale.id), {
            preserveScroll: true,
            onSuccess: () => {
                setShowCancelModal(false);
                cancelForm.reset();
                toast.success('Venta anulada correctamente.');
            },
            onError: (errors) => {
                toast.error(errors.reason ?? 'No tienes permisos para anular esta venta.');
            },
        });
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-950">Venta #{sale.id}</h2>}>
            <Head title={`Venta #${sale.id}`} />
            <Toast toasts={toast.toasts} onClose={toast.removeToast} />

            <div className="py-5">
                <div className="mx-auto max-w-[1800px] space-y-5 px-5 sm:px-6">
                    <section className="rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <div className="flex flex-wrap items-center gap-3">
                                    <h1 className="text-2xl font-bold text-slate-950">Venta #{sale.id}</h1>
                                    <StatusBadge status={sale.status} />
                                </div>
                                <div className="mt-2 space-y-1 text-sm text-slate-500">
                                    <p>Fecha: {sale.created_at_local ?? '-'}</p>
                                    <p>Usuario: {sale.created_by ?? '-'}</p>
                                    {sale.customer && (
                                        <p>
                                            Cliente: {sale.customer.name}
                                            {sale.customer.doc_number ? ` · ${sale.customer.doc_type ?? 'Documento'} ${sale.customer.doc_number}` : ''}
                                        </p>
                                    )}
                                    {sale.customer?.address && (
                                        <p>Dirección: {sale.customer.address}</p>
                                    )}
                                </div>
                            </div>

                            <div className="text-right">
                                <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">Total</div>
                                <div className="mt-1 whitespace-nowrap text-3xl font-bold text-slate-950">
                                    {formatCurrency(sale.total, country)}
                                </div>
                                <div className="mt-4 flex justify-end gap-2">
                                    <Link
                                        href={route('reports.sales')}
                                        className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
                                    >
                                        Volver
                                    </Link>
                                    <button
                                        type="button"
                                        onClick={printSale}
                                        className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700"
                                    >
                                        Imprimir
                                    </button>
                                    {canCancelSale && (
                                        <button
                                            type="button"
                                            onClick={() => setShowCancelModal(true)}
                                            className="rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-red-700"
                                        >
                                            Anular venta
                                        </button>
                                    )}
                                </div>
                            </div>
                        </div>

                        {isCancelled && (
                            <div className="mt-5 rounded-2xl border border-red-100 bg-red-50 p-4 text-sm text-red-800">
                                <div className="font-semibold">Venta anulada</div>
                                <div className="mt-1">Motivo: {sale.cancellation_reason}</div>
                                <div className="mt-1">
                                    Anulada el {sale.cancelled_at_local ?? '-'} por {sale.cancelled_by ?? '-'}
                                </div>
                            </div>
                        )}

                        {sale.discount_amount > 0 && (
                            <div className="mt-5 rounded-2xl border border-indigo-100 bg-indigo-50 p-4 text-sm text-indigo-900">
                                <div className="font-semibold">Descuento aplicado</div>
                                <div className="mt-2 grid gap-2 sm:grid-cols-4">
                                    <div>
                                        <span className="block text-xs font-semibold uppercase text-indigo-500">Total antes</span>
                                        <strong>{formatCurrency(sale.subtotal_before_discount, country)}</strong>
                                    </div>
                                    <div>
                                        <span className="block text-xs font-semibold uppercase text-indigo-500">Tipo</span>
                                        <strong>{sale.discount_type === 'percent' ? 'Porcentaje' : 'Monto fijo'}</strong>
                                    </div>
                                    <div>
                                        <span className="block text-xs font-semibold uppercase text-indigo-500">Valor</span>
                                        <strong>
                                            {sale.discount_type === 'percent'
                                                ? `${sale.discount_value}%`
                                                : formatCurrency(sale.discount_value, country)}
                                        </strong>
                                    </div>
                                    <div>
                                        <span className="block text-xs font-semibold uppercase text-indigo-500">Descuento</span>
                                        <strong>{formatCurrency(sale.discount_amount, country)}</strong>
                                    </div>
                                </div>
                                {sale.discount_reason && (
                                    <div className="mt-2">
                                        <span className="font-semibold">Motivo:</span> {sale.discount_reason}
                                    </div>
                                )}
                                <div className="mt-1">
                                    <span className="font-semibold">Aplicado por:</span> {sale.created_by ?? '-'}
                                </div>
                            </div>
                        )}

                        {canViewFelDocuments && sale.electronic_document && (
                            <div className="mt-5 rounded-2xl border border-indigo-100 bg-indigo-50 p-4 text-sm text-indigo-900">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div className="font-semibold">Factura electrónica FEL</div>
                                    {sale.fel_uuid && (
                                        <div className="flex flex-wrap gap-2">
                                            <button
                                                type="button"
                                                onClick={printSale}
                                                className="rounded-xl bg-indigo-600 px-3 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-indigo-700"
                                            >
                                                Ver factura FEL
                                            </button>
                                            {sale.has_fel_xml && (
                                                <button
                                                    type="button"
                                                    onClick={() => window.open(route('sales.fel-download', [sale.id, 'xml']), '_blank')}
                                                    className="rounded-xl border border-indigo-200 bg-white px-3 py-2 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-50"
                                                >
                                                    Descargar XML
                                                </button>
                                            )}
                                            {sale.has_fel_pdf && (
                                                <button
                                                    type="button"
                                                    onClick={() => window.open(route('sales.fel-download', [sale.id, 'pdf']), '_blank')}
                                                    className="rounded-xl border border-indigo-200 bg-white px-3 py-2 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-50"
                                                >
                                                    Descargar PDF
                                                </button>
                                            )}
                                            <button
                                                type="button"
                                                onClick={() => window.open(route('sales.fel-document', sale.id), '_blank')}
                                                className="rounded-xl border border-indigo-200 bg-white px-3 py-2 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-50"
                                            >
                                                Obtener documento desde Digifact
                                            </button>
                                        </div>
                                    )}
                                </div>
                                <div className="mt-1 font-semibold">Estado FEL: {felStatusLabel(sale.electronic_document.status)}</div>
                                <div className="mt-2 grid gap-2 md:grid-cols-2">
                                    <div>UUID: {sale.electronic_document.uuid ?? '-'}</div>
                                    <div>Serie: {sale.electronic_document.series ?? '-'}</div>
                                    <div>Número: {sale.electronic_document.number ?? '-'}</div>
                                    <div>Fecha certificación: {sale.electronic_document.certification_date ?? '-'}</div>
                                    <div>Fecha anulación: {sale.electronic_document.cancelled_at ?? '-'}</div>
                                </div>
                                {sale.electronic_document.status === 'certified' && !sale.electronic_document.has_printable_document && (
                                    <div className="mt-2 rounded-xl border border-amber-100 bg-amber-50 px-3 py-2 text-amber-700">
                                        No hay documento imprimible disponible.
                                    </div>
                                )}
                                {sale.electronic_document.error_message && (
                                    <div className="mt-2 rounded-xl border border-red-100 bg-red-50 px-3 py-2 text-red-700">
                                        {sale.electronic_document.error_message}
                                    </div>
                                )}
                                {sale.electronic_document.technical_response && (
                                    <button
                                        type="button"
                                        onClick={() => setShowTechnicalModal(true)}
                                        className="mt-3 rounded-xl border border-indigo-200 bg-white px-3 py-2 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-50"
                                    >
                                        Ver respuesta técnica
                                    </button>
                                )}
                            </div>
                        )}
                    </section>

                    <section className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white/95 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="border-b border-slate-100 p-5">
                            <h3 className="text-lg font-semibold text-slate-950">Artículos</h3>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead>
                                    <tr className="bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        <th className="px-4 py-3">Producto</th>
                                        <th className="px-4 py-3">Código</th>
                                        <th className="px-4 py-3 text-right">Cantidad</th>
                                        <th className="px-4 py-3 text-right">Precio unitario</th>
                                        <th className="px-4 py-3">Lista / origen</th>
                                        {sale.discount_amount > 0 && (
                                            <th className="px-4 py-3 text-right">Descuento</th>
                                        )}
                                        <th className="px-4 py-3 text-right">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {sale.items.map((item) => (
                                        <tr key={item.id} className="transition-colors hover:bg-indigo-50/30">
                                            <td className="px-4 py-3 font-semibold text-slate-950">{item.product_name}</td>
                                            <td className="px-4 py-3 text-slate-600">{item.code ?? '-'}</td>
                                            <td className="px-4 py-3 text-right text-slate-700">{item.quantity}</td>
                                            <td className="whitespace-nowrap px-4 py-3 text-right text-slate-700">
                                                {formatCurrency(item.unit_price, country)}
                                            </td>
                                            <td className="px-4 py-3 text-slate-600">
                                                <div className="font-semibold text-slate-800">{item.price_type_name ?? '-'}</div>
                                                <div className="mt-1 text-xs text-slate-500">{priceSourceLabel(item.price_source)}</div>
                                            </td>
                                            {sale.discount_amount > 0 && (
                                                <td className="whitespace-nowrap px-4 py-3 text-right text-indigo-700">
                                                    {formatCurrency(item.discount_amount, country)}
                                                </td>
                                            )}
                                            <td className="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-950">
                                                {formatCurrency(item.total, country)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white/95 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="border-b border-slate-100 p-5">
                            <h3 className="text-lg font-semibold text-slate-950">Pagos</h3>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead>
                                    <tr className="bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        <th className="px-4 py-3">Método</th>
                                        <th className="px-4 py-3">Referencia</th>
                                        <th className="px-4 py-3">Detalle</th>
                                        <th className="px-4 py-3 text-right">Monto</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {sale.payments.map((payment) => (
                                        <tr key={payment.id} className="transition-colors hover:bg-indigo-50/30">
                                            <td className="px-4 py-3 font-semibold text-slate-950">
                                                {paymentLabels[payment.method] ?? payment.method}
                                            </td>
                                            <td className="px-4 py-3 text-slate-600">{payment.reference ?? '-'}</td>
                                            <td className="px-4 py-3 text-slate-600">
                                                <PaymentDetails method={payment.method} details={payment.details ?? {}} />
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-950">
                                                {formatCurrency(payment.amount, country)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>

            {showCancelModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 p-4 backdrop-blur-sm">
                    <form onSubmit={submitCancel} className="w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_20px_60px_rgba(15,23,42,0.25)]">
                        <h3 className="text-lg font-semibold text-slate-950">Anular venta</h3>
                        <p className="mt-1 text-sm text-slate-500">
                            Esta acción restaura el stock vendido y marca la venta como anulada.
                        </p>

                        <label className="mt-5 block">
                            <span className="text-sm font-medium text-slate-700">Motivo de anulación</span>
                            <textarea
                                value={cancelForm.data.reason}
                                onChange={(event) => cancelForm.setData('reason', event.target.value)}
                                rows={4}
                                className="mt-1 w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                placeholder="Escribe el motivo"
                            />
                        </label>
                        {cancelForm.errors.reason && (
                            <p className="mt-2 text-sm text-red-600">{cancelForm.errors.reason}</p>
                        )}

                        <div className="mt-5 flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => setShowCancelModal(false)}
                                className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                disabled={cancelForm.processing}
                                className="rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                Confirmar anulación
                            </button>
                        </div>
                    </form>
                </div>
            )}

            {showTechnicalModal && sale.electronic_document?.technical_response && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 p-4 backdrop-blur-sm">
                    <section className="flex max-h-[85vh] w-full max-w-4xl flex-col rounded-2xl border border-slate-200 bg-white shadow-[0_20px_60px_rgba(15,23,42,0.25)]">
                        <div className="flex items-center justify-between border-b border-slate-100 p-5">
                            <h3 className="text-lg font-semibold text-slate-950">Respuesta técnica Digifact</h3>
                            <button
                                type="button"
                                onClick={() => setShowTechnicalModal(false)}
                                className="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                Cerrar
                            </button>
                        </div>
                        <pre className="overflow-auto p-5 text-xs leading-relaxed text-slate-700">
                            {JSON.stringify(sale.electronic_document.technical_response, null, 2)}
                        </pre>
                    </section>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

function StatusBadge({ status }: { status: string }) {
    const cancelled = status === 'cancelled';

    return (
        <span className={[
            'inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold',
            cancelled
                ? 'border-red-100 bg-red-50 text-red-700'
                : 'border-emerald-100 bg-emerald-50 text-emerald-700',
        ].join(' ')}>
            {cancelled ? 'Anulada' : 'Completada'}
        </span>
    );
}

function PaymentDetails({ method, details }: { method: string; details: Record<string, string> }) {
    const rows = paymentDetailRows(method, details);

    if (rows.length === 0) {
        return <span>-</span>;
    }

    return (
        <div className="space-y-1">
            {rows.map(([label, value]) => (
                <div key={label}>
                    <span className="font-semibold text-slate-700">{label}:</span> {value}
                </div>
            ))}
        </div>
    );
}

function paymentDetailRows(method: string, details: Record<string, string>): [string, string][] {
    const fields: Record<string, [string, string][]> = {
        card: [
            ['Autorización', details.authorization],
        ],
        transfer: [
            ['Banco', details.bank],
            ['Referencia', details.transfer_reference],
        ],
        check: [
            ['Banco', details.bank],
            ['Cheque', details.check_number],
        ],
        mercadopago: [
            ['Referencia', details.mercadopago_reference],
        ],
    };

    return (fields[method] ?? []).filter(([, value]) => Boolean(value));
}

function felStatusLabel(status: string): string {
    const labels: Record<string, string> = {
        pending: 'Pendiente',
        certified: 'Certificada',
        failed: 'Error',
        cancellation_pending: 'Anulación pendiente',
        cancelled: 'Anulada',
        cancellation_failed: 'Anulación fallida',
    };

    return labels[status] ?? status;
}
