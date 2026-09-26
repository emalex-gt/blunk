import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { makeOperationKey } from '@/lib/idempotency';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, ReactNode, useRef, useState } from 'react';

type Related = { id: number; name: string };

type PreSaleItem = {
    id: number;
    product_code?: string | null;
    product_barcode?: string | null;
    product_name?: string | null;
    quantity: number;
    picked_quantity?: number | null;
    stock_deducted_quantity?: number;
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
    converted_at?: string | null;
    converted_by?: Related | null;
    converted_sale?: { id: number; business_number?: number | null; document_type?: string | null; total?: number | null; status?: string | null; payment_status?: 'paid' | 'unpaid' | null; amount_paid?: number | null; payment_method?: 'cash' | 'card' | 'transfer' | 'check' | null } | null;
    operational_status?: string;
    fel?: FelState;
    fel_eligibility?: { eligible: boolean; status: 'eligible' | 'not_eligible'; reason_code?: string | null; reason?: string | null };
    fel_availability?: { available: boolean; reason_code?: string | null; reason?: string | null };
    cancelled_at?: string | null;
    cancellation_reason?: string | null;
    cancellation_note?: string | null;
    notes?: string | null;
    payment_method?: 'cash' | 'card' | 'transfer' | 'check' | null;
    agreed_payment_method?: 'cash' | 'card' | 'transfer' | 'check' | null;
    collection_responsibility?: 'pre_seller' | 'delivery_agent';
    collection_message?: string | null;
    collection_status?: string;
    collection?: { amount: number; payment_method: string; reference?: string | null; collected_by?: Related | null; recorded_by?: Related | null; collected_at?: string | null; custody_status?: string | null } | null;
    subtotal: number;
    discount_total: number;
    total: number;
    prepared_total: number;
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

type InvoiceOptions = {
    mode: 'manual' | 'automatic_all';
    document_types: Array<'receipt' | 'invoice'>;
    credit_enabled: boolean;
    payment_methods: Array<'cash' | 'card' | 'transfer' | 'check'>;
    fel_automation_enabled: boolean;
};

type FelState = { status: 'not_requested' | 'pending' | 'failed' | 'unknown' | 'certified'; error_message?: string | null; uuid?: string | null };
type PostCollection = { entry_id: number; eligible: boolean; sale_id: number; total: number; agreed_method: 'cash' | 'card' | 'transfer' | 'check' | null; payment_policy: { available: boolean; allowed_methods: ('cash' | 'card' | 'transfer' | 'check')[]; reason: string | null }; active_collection: { id: number; payment_method: 'cash' | 'card' | 'transfer' | 'check'; collected_by?: Related | null; recorded_by?: Related | null; collected_at?: string | null } | null; history: Array<{ id: number; status: string; payment_method: string; collected_at?: string | null; reversal?: { reason_code: string; explanation: string } | null }>; reversal_context: { state: string; available: boolean; message: string; requires_cash_adjustment_confirmation: boolean } | null; can_collect: boolean; can_override: boolean; can_reverse: boolean; collectors: Related[] };
type Props = { preSale: PreSale; canInvoice: boolean; canCertifyFel: boolean; canRegisterCollection: boolean; canOverrideCollection: boolean; requiresCollectionOverride: boolean; collectionCollectors: Related[]; invoiceOptions: InvoiceOptions; stockDeductionTiming: 'picking' | 'invoice'; routeCash: { is_open: boolean }; postConversionCollection: PostCollection | null };

const cancellationReasons = ['Cliente canceló', 'Producto no disponible', 'Duplicada', 'Error de captura', 'Otro'];

export default function Show({ preSale, canInvoice, canCertifyFel, canRegisterCollection, canOverrideCollection, requiresCollectionOverride, collectionCollectors, invoiceOptions, stockDeductionTiming, routeCash, postConversionCollection }: Props) {
    const [cancelOpen, setCancelOpen] = useState(false);
    const [invoiceOpen, setInvoiceOpen] = useState(false);
    const [collectionOpen, setCollectionOpen] = useState(false);
    const processingLockedRef = useRef(false);
    const invoiceSubmitLockedRef = useRef(false);
    const cancelForm = useForm({ idempotency_key: makeOperationKey('pre-sale-cancel'), cancellation_reason: '', cancellation_note: '' });
    const invoiceForm = useForm({
        idempotency_key: makeOperationKey('pre-sale-invoice'),
        payment_condition: 'paid',
        payment_method: preSale.payment_method ?? '',
        due_date: '',
        note: '',
        pre_sale: '',
    });
    const felForm = useForm({ idempotency_key: makeOperationKey('pre-sale-fel') });
    const collectionForm = useForm({
        idempotency_key: makeOperationKey('pre-sale-collection'),
        amount: String(preSale.total),
        payment_method: preSale.agreed_payment_method ?? '',
        reference: '',
        collected_by: preSale.seller?.id ? String(preSale.seller.id) : '',
        collected_at: '',
        override_reason: '',
    });
    const postCollectionForm = useForm({ idempotency_key: makeOperationKey('post-conversion-collection'), route_delivery_batch_pre_sale_id: postConversionCollection?.entry_id ?? 0, payment_method: postConversionCollection?.payment_policy.allowed_methods[0] ?? '', collected_by: '', override_reason: '' });
    const reversalForm = useForm({ idempotency_key: makeOperationKey('post-conversion-reversal'), reason_code: 'payment_recorded_by_mistake', explanation: '', confirmed: false, confirm_cash_adjustment: false });
    const [postCollectionOpen, setPostCollectionOpen] = useState(false);
    const [postReversalOpen, setPostReversalOpen] = useState(false);
    const felErrors = felForm.errors as Record<string, string>;
    const fel = preSale.fel ?? { status: 'not_requested' };

    const markProcessing = () => {
        if (processingLockedRef.current) {
            return;
        }

        processingLockedRef.current = true;
        router.post(route('routes.pre-sales.processing', preSale.id), {
            idempotency_key: makeOperationKey('pre-sale-processing'),
        }, {
            preserveScroll: true,
            onFinish: () => {
                processingLockedRef.current = false;
            },
        });
    };

    const submitCancellation = (event: FormEvent) => {
        event.preventDefault();
        cancelForm.post(route('routes.pre-sales.cancel', preSale.id), {
            preserveScroll: true,
            onSuccess: () => {
                setCancelOpen(false);
                cancelForm.setData({
                    idempotency_key: makeOperationKey('pre-sale-cancel'),
                    cancellation_reason: '',
                    cancellation_note: '',
                });
            },
        });
    };

    const submitInvoice = (event: FormEvent) => {
        event.preventDefault();

        if (invoiceSubmitLockedRef.current || invoiceForm.processing) {
            return;
        }

        invoiceSubmitLockedRef.current = true;
        invoiceForm.post(route('routes.pre-sales.invoice', preSale.id), {
            preserveScroll: true,
            onSuccess: () => {
                setInvoiceOpen(false);
                invoiceForm.setData({
                    idempotency_key: makeOperationKey('pre-sale-invoice'),
                    payment_condition: 'paid',
                    payment_method: preSale.payment_method ?? '',
                    due_date: '',
                    note: '',
                    pre_sale: '',
                });
            },
            onFinish: () => {
                invoiceSubmitLockedRef.current = false;
            },
        });
    };

    const certifyFel = () => {
        if (!felForm.processing) {
            felForm.post(route('routes.pre-sales.fel.certify', preSale.id), {
                preserveScroll: true,
                onSuccess: () => felForm.setData('idempotency_key', makeOperationKey('pre-sale-fel')),
                onError: () => felForm.setData('idempotency_key', makeOperationKey('pre-sale-fel')),
            });
        }
    };

    const submitCollection = (event: FormEvent) => {
        event.preventDefault();
        if (collectionForm.processing) return;

        collectionForm.post(route('routes.pre-sales.collection.store', preSale.id), {
            preserveScroll: true,
            onSuccess: () => {
                setCollectionOpen(false);
                collectionForm.setData({
                    idempotency_key: makeOperationKey('pre-sale-collection'),
                    amount: String(preSale.total),
                    payment_method: preSale.agreed_payment_method ?? '',
                    reference: '',
                    collected_by: preSale.seller?.id ? String(preSale.seller.id) : '',
                    collected_at: '',
                    override_reason: '',
                });
            },
        });
    };
    const submitPostCollection = (event: FormEvent) => {
        event.preventDefault();
        if (!postConversionCollection || postCollectionForm.processing) return;
        postCollectionForm.post(route('routes.post-conversion-collections.store'), { preserveScroll: true, onSuccess: () => setPostCollectionOpen(false) });
    };
    const submitPostReversal = (event: FormEvent) => {
        event.preventDefault();
        if (!postConversionCollection?.active_collection || reversalForm.processing) return;
        reversalForm.post(route('routes.post-conversion-collections.reversals.store', postConversionCollection.active_collection.id), { preserveScroll: true, onSuccess: () => setPostReversalOpen(false) });
    };

    return (
        <AuthenticatedLayout>
            <Head title={`Preventa #${preSale.id}`} />
            <div className="mx-auto max-w-[1600px] space-y-5 px-4 py-6 sm:px-6">
                {!routeCash.is_open && <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">No hay caja abierta para operar rutas y registrar comprobantes.</div>}
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <Link href={route('routes.pre-sales.index')} className="text-sm font-semibold text-indigo-600 hover:text-indigo-800">Volver a preventas</Link>
                        <h1 className="mt-2 text-2xl font-semibold text-slate-950">Preventa #{preSale.id}</h1>
                        <p className="text-sm text-slate-500">Revisión administrativa de pedido enviado desde ruta.</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {routeCash.is_open && preSale.status === 'submitted' && (
                            <button onClick={markProcessing} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                                Marcar en preparación
                            </button>
                        )}
                        {routeCash.is_open && ['submitted', 'processing'].includes(preSale.status) && (
                            <Link href={route('routes.pre-sales.pick', preSale.id)} className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                                Preparar pedido
                            </Link>
                        )}
                        {preSale.status === 'picked' && canInvoice && !preSale.converted_sale && (
                            <button onClick={() => setInvoiceOpen(true)} className="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700">
                                Generar comprobante
                            </button>
                        )}
                        {preSale.status === 'converted' && preSale.converted_sale && (
                            <Link href={route('sales.show', preSale.converted_sale.id)} className="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700">
                                Ver venta {preSale.converted_sale.business_number ? `V-${preSale.converted_sale.business_number}` : ''}
                            </Link>
                        )}
                        {preSale.status === 'converted' && !['operation_cancelled', 'operation_returned'].includes(preSale.operational_status ?? '') && preSale.converted_sale && canCertifyFel && ['not_requested', 'failed'].includes(fel.status) && (
                            <button type="button" onClick={certifyFel} disabled={felForm.processing} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60">
                                {felForm.processing ? 'Certificando FEL...' : 'Certificar FEL'}
                            </button>
                        )}
                        {['submitted', 'processing'].includes(preSale.status) && !preSale.collection && (
                            <button onClick={() => setCancelOpen(true)} className="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                                Cancelar preventa
                            </button>
                        )}
                        {['submitted', 'processing'].includes(preSale.status) && preSale.collection && (
                            <p className="text-sm font-semibold text-amber-800">Esta preventa ya tiene un cobro registrado y no puede cancelarse.</p>
                        )}
                    </div>
                </div>
                <section className="rounded-lg border border-slate-200 bg-white p-4">
                    <h2 className="text-sm font-semibold text-slate-900">Cobro</h2>
                    <p className="mt-1 text-sm text-slate-600">Método de pago acordado: {paymentMethodLabel(preSale.agreed_payment_method)}</p>
                        {preSale.operational_status === 'operation_returned' ? <p className="mt-2 text-sm font-semibold text-red-800">Operación devuelta. La entrega original se conserva y no hay cobro pendiente.</p> : preSale.operational_status === 'operation_cancelled' ? <p className="mt-2 text-sm font-semibold text-red-800">Operación anulada. No hay cobro pendiente.</p> : preSale.collection ? <div className="mt-2 grid gap-1 text-sm text-slate-700"><div><span className="font-semibold">Cobro registrado</span>: {paymentMethodLabel(preSale.collection.payment_method as PreSale['payment_method'])} · Q {preSale.collection.amount.toFixed(2)}</div><div>Cobrador: {preSale.collection.collected_by?.name ?? '-'} · Fecha/hora: {preSale.collection.collected_at ? new Date(preSale.collection.collected_at).toLocaleString() : '-'}</div>{preSale.collection.recorded_by && preSale.collection.recorded_by.id !== preSale.collection.collected_by?.id && <div>Registrado por: {preSale.collection.recorded_by.name}</div>}<div>Custodia: {custodyLabel(preSale.collection.custody_status)}</div>{preSale.collection.reference && <div>Referencia: {preSale.collection.reference}</div>}</div> : preSale.collection_message ? <p className="mt-2 text-sm font-semibold text-amber-800">{preSale.collection_message}</p> : preSale.converted_sale?.payment_status === 'paid' ? null : <div className="mt-2 flex items-center gap-3"><span className="text-sm font-semibold text-amber-800">Cobro pendiente</span>{canRegisterCollection && <button type="button" onClick={() => setCollectionOpen(true)} className="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Registrar cobro</button>}{canOverrideCollection && <span className="text-xs text-slate-500">Override administrativo disponible</span>}</div>}
                </section>
                {postConversionCollection && <section className="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                    <h2 className="text-sm font-semibold text-emerald-950">Cobro post-conversión</h2>
                    <p className="mt-1 text-sm text-slate-700">Método acordado: <strong>{paymentMethodLabel(postConversionCollection.agreed_method)}</strong> · Total: <strong>Q {postConversionCollection.total.toFixed(2)}</strong></p>
                    {postConversionCollection.active_collection ? <div className="mt-2 text-sm text-slate-700"><p><strong>Pagado:</strong> {paymentMethodLabel(postConversionCollection.active_collection.payment_method)} · {postConversionCollection.active_collection.collected_at ? formatDate(postConversionCollection.active_collection.collected_at) : '-'}</p><p>Cobrador: {postConversionCollection.active_collection.collected_by?.name ?? '-'} · Registrado por: {postConversionCollection.active_collection.recorded_by?.name ?? '-'}</p>{postConversionCollection.can_reverse && <button type="button" disabled={!postConversionCollection.reversal_context?.available} onClick={() => setPostReversalOpen(true)} className="mt-3 rounded-lg bg-rose-700 px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">Revertir cobro</button>}{postConversionCollection.reversal_context && <p className="mt-2 text-xs font-semibold text-amber-800">{postConversionCollection.reversal_context.message}</p>}</div> : <div className="mt-2"><p className="text-sm font-semibold text-amber-800">Pendiente de cobro</p>{postConversionCollection.payment_policy.available ? (postConversionCollection.eligible && postConversionCollection.can_collect && <button type="button" onClick={() => setPostCollectionOpen(true)} className="mt-2 rounded-lg bg-emerald-700 px-3 py-2 text-sm font-semibold text-white">Registrar cobro</button>) : <p className="mt-2 text-sm font-semibold text-amber-800">{postConversionCollection.payment_policy.reason}</p>}</div>}
                    {postConversionCollection.history.some(item => item.status === 'reversed') && <div className="mt-3 border-t border-emerald-200 pt-3 text-xs text-slate-600">{postConversionCollection.history.filter(item => item.status === 'reversed').map(item => <p key={item.id}>Cobro revertido: {paymentMethodLabel(item.payment_method as PreSale['payment_method'])} · {item.reversal?.explanation}</p>)}</div>}
                </section>}
                {preSale.converted_sale?.payment_status === 'paid' && !postConversionCollection && <section className="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                    <h2 className="text-sm font-semibold text-emerald-950">Venta generada</h2>
                    <div className="mt-2 grid gap-1 text-sm text-slate-700">
                        <p><strong>Estado:</strong> Pagada</p>
                        <p><strong>Método cobrado:</strong> {paymentMethodLabel(preSale.converted_sale.payment_method)}</p>
                        <p><strong>Importe pagado:</strong> Q {formatMoney(preSale.converted_sale.amount_paid ?? preSale.converted_sale.total ?? 0)}</p>
                    </div>
                </section>}
                {postCollectionOpen && postConversionCollection && <div className="fixed inset-0 z-50 flex items-end bg-slate-950/40 p-4 sm:items-center sm:justify-center"><form onSubmit={submitPostCollection} className="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl"><h2 className="text-lg font-semibold">Registrar cobro post-conversión</h2><p className="mt-1 text-sm text-slate-600">Método acordado: {paymentMethodLabel(postConversionCollection.agreed_method)}</p><label className="mt-3 block text-sm font-medium">Método real<select value={postCollectionForm.data.payment_method} onChange={event => postCollectionForm.setData('payment_method', event.target.value)} className="mt-1 w-full rounded-lg border-slate-300">{postConversionCollection.payment_policy.allowed_methods.map(method => <option key={method} value={method}>{paymentMethodLabel(method)}</option>)}</select></label>{postConversionCollection.can_override && <details className="mt-3"><summary className="cursor-pointer text-sm font-medium">Registrar por otro cobrador</summary><select value={postCollectionForm.data.collected_by} onChange={event => postCollectionForm.setData('collected_by', event.target.value)} className="mt-2 w-full rounded-lg border-slate-300"><option value="">Yo registré el cobro</option>{postConversionCollection.collectors.map(collector => <option key={collector.id} value={collector.id}>{collector.name}</option>)}</select><textarea value={postCollectionForm.data.override_reason} onChange={event => postCollectionForm.setData('override_reason', event.target.value)} placeholder="Motivo obligatorio si cambia cobrador" className="mt-2 w-full rounded-lg border-slate-300" rows={2} /></details>}<div className="mt-5 flex justify-end gap-2"><button type="button" onClick={() => setPostCollectionOpen(false)} className="rounded-lg border px-4 py-2 text-sm font-semibold">Cancelar</button><button disabled={postCollectionForm.processing} className="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Registrar cobro</button></div></form></div>}
                {postReversalOpen && postConversionCollection?.active_collection && postConversionCollection.reversal_context && <div className="fixed inset-0 z-50 flex items-end bg-slate-950/40 p-4 sm:items-center sm:justify-center"><form onSubmit={submitPostReversal} className="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl"><h2 className="text-lg font-semibold text-rose-900">Revertir cobro</h2><p className="mt-1 text-sm text-rose-800">{postConversionCollection.reversal_context.message}</p><select value={reversalForm.data.reason_code} onChange={event => reversalForm.setData('reason_code', event.target.value)} className="mt-3 w-full rounded-lg border-slate-300">{['payment_recorded_by_mistake','wrong_customer','duplicate_collection','wrong_amount','other'].map(reason => <option key={reason} value={reason}>{reason}</option>)}</select><textarea required value={reversalForm.data.explanation} onChange={event => reversalForm.setData('explanation', event.target.value)} placeholder="Explicación obligatoria" className="mt-2 w-full rounded-lg border-slate-300" rows={3} /><label className="mt-2 flex gap-2 text-sm"><input type="checkbox" checked={reversalForm.data.confirmed} onChange={event => reversalForm.setData('confirmed', event.target.checked)} />Confirmo la corrección administrativa.</label>{postConversionCollection.reversal_context.requires_cash_adjustment_confirmation && <label className="mt-2 flex gap-2 text-sm"><input type="checkbox" checked={reversalForm.data.confirm_cash_adjustment} onChange={event => reversalForm.setData('confirm_cash_adjustment', event.target.checked)} />Confirmo el ajuste negativo en la misma caja.</label>}<div className="mt-5 flex justify-end gap-2"><button type="button" onClick={() => setPostReversalOpen(false)} className="rounded-lg border px-4 py-2 text-sm font-semibold">Cancelar</button><button disabled={reversalForm.processing || !reversalForm.data.confirmed} className="rounded-lg bg-rose-700 px-4 py-2 text-sm font-semibold text-white">Revertir cobro</button></div></form></div>}

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
                        {preSale.operational_status === 'operation_cancelled' && <Info label="Estado operativo" value="Operación anulada" />}
                        {preSale.operational_status === 'operation_returned' && <Info label="Estado operativo" value="Operación devuelta" />}
                        <Info label="Creada" value={formatDate(preSale.created_at)} />
                        <Info label="Enviada" value={formatDate(preSale.submitted_at)} />
                        <Info label="En preparación" value={formatDate(preSale.processing_started_at)} />
                        <Info label="Lista para facturar" value={formatDate(preSale.picked_at)} />
                        <Info label="Preparada por" value={preSale.picked_by?.name} />
                        <Info label="Comprobante generado" value={formatDate(preSale.converted_at)} />
                        <Info label="Generado por" value={preSale.converted_by?.name} />
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

                {preSale.status === 'picked' && invoiceOptions.mode === 'automatic_all' && !invoiceOptions.fel_automation_enabled && (
                    <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                        Automatización FEL deshabilitada. Los comprobantes quedarán pendientes de certificación manual.
                    </div>
                )}

                {preSale.status === 'picked' && invoiceOptions.mode === 'automatic_all' && invoiceOptions.fel_automation_enabled && preSale.fel_availability && !preSale.fel_availability.available && (
                    <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                        {preSale.fel_availability.reason ?? 'FEL no configurado para certificación automática.'}
                    </div>
                )}

                {preSale.status === 'converted' && (
                    <div className="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                        <span className="font-semibold">FEL:</span> {felLabel(fel.status)}
                        {preSale.fel_eligibility && <span className={preSale.fel_eligibility.eligible ? 'ml-2 font-semibold text-emerald-700' : 'ml-2 font-semibold text-amber-700'}>
                            {preSale.fel_eligibility.eligible ? 'Elegible' : 'No elegible'}
                        </span>}
                        {preSale.fel_eligibility?.reason && <div className="mt-1 text-amber-700">{preSale.fel_eligibility.reason}</div>}
                        {preSale.fel_availability && !preSale.fel_availability.available && <div className="mt-1 text-amber-700">{preSale.fel_availability.reason ?? 'FEL no configurado para certificación automática.'}</div>}
                        {fel.error_message && <span className="ml-1 text-red-700">{fel.error_message}</span>}
                        {fel.status === 'unknown' && <span className="ml-1 text-amber-700">Requiere conciliación antes de reintentar.</span>}
                        {felErrors.fel && <div className="mt-1 font-semibold text-red-700">{felErrors.fel}</div>}
                    </div>
                )}

                {preSale.status === 'picked' && preSale.fel_eligibility && !preSale.fel_eligibility.eligible && (
                    <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        <span className="font-semibold">FEL no elegible:</span> {preSale.fel_eligibility.reason}
                    </div>
                )}

                <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div className="border-b border-slate-100 p-4">
                        <h2 className="text-sm font-semibold text-slate-900">Productos</h2>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-[1120px] text-sm">
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
                                        <td className="min-w-64 px-4 py-3 font-medium text-slate-900" title={item.product_name ?? ''}>{item.product_name}</td>
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
                                    <td colSpan={6} className="px-4 py-3 text-right">Total preparado</td>
                                    <td className="px-4 py-3">Q {formatMoney(preSale.prepared_total)}</td>
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

            {collectionOpen && (
                <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-950/50 p-4 sm:flex sm:items-center sm:justify-center">
                    <form onSubmit={submitCollection} className="mx-auto w-full max-w-lg rounded-xl bg-white p-5 shadow-xl">
                        <div className="flex items-start justify-between gap-4"><div><h2 className="text-lg font-semibold text-slate-950">Registrar cobro</h2><p className="mt-1 text-sm text-slate-600">Registra el cobro real completo de esta preventa.</p></div><button type="button" onClick={() => setCollectionOpen(false)} disabled={collectionForm.processing} className="text-sm font-semibold text-slate-500 hover:text-slate-800">Cerrar</button></div>
                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                            <label className="block"><span className="text-xs font-semibold text-slate-600">Método real</span><select value={collectionForm.data.payment_method} onChange={(event) => collectionForm.setData('payment_method', event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-200 text-sm"><option value="">Selecciona</option>{invoiceOptions.payment_methods.map((method) => <option key={method} value={method}>{paymentMethodLabel(method)}</option>)}</select>{collectionForm.errors.payment_method && <p className="mt-1 text-xs font-semibold text-red-600">{collectionForm.errors.payment_method}</p>}</label>
                            <label className="block"><span className="text-xs font-semibold text-slate-600">Importe completo</span><input type="number" min="0.01" step="0.01" value={collectionForm.data.amount} onChange={(event) => collectionForm.setData('amount', event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-200 text-sm" />{collectionForm.errors.amount && <p className="mt-1 text-xs font-semibold text-red-600">{collectionForm.errors.amount}</p>}</label>
                        </div>
                        <label className="mt-4 block"><span className="text-xs font-semibold text-slate-600">Referencia</span><input value={collectionForm.data.reference} onChange={(event) => collectionForm.setData('reference', event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-200 text-sm" />{collectionForm.errors.reference && <p className="mt-1 text-xs font-semibold text-red-600">{collectionForm.errors.reference}</p>}</label>
                        {requiresCollectionOverride && <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4"><p className="text-sm font-semibold text-amber-900">Registro por override</p><div className="mt-3 grid gap-3 sm:grid-cols-2"><label><span className="text-xs font-semibold text-slate-600">Cobrador</span><select value={collectionForm.data.collected_by} onChange={(event) => collectionForm.setData('collected_by', event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-200 text-sm"><option value="">Selecciona</option>{collectionCollectors.map((collector) => <option key={collector.id} value={collector.id}>{collector.name}</option>)}</select>{collectionForm.errors.collected_by && <p className="mt-1 text-xs font-semibold text-red-600">{collectionForm.errors.collected_by}</p>}</label><label><span className="text-xs font-semibold text-slate-600">Fecha y hora de cobro</span><input type="datetime-local" value={collectionForm.data.collected_at} onChange={(event) => collectionForm.setData('collected_at', event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-200 text-sm" />{collectionForm.errors.collected_at && <p className="mt-1 text-xs font-semibold text-red-600">{collectionForm.errors.collected_at}</p>}</label></div><label className="mt-3 block"><span className="text-xs font-semibold text-slate-600">Motivo del override</span><textarea rows={3} value={collectionForm.data.override_reason} onChange={(event) => collectionForm.setData('override_reason', event.target.value)} className="mt-1 w-full rounded-lg border-slate-200 text-sm" />{collectionForm.errors.override_reason && <p className="mt-1 text-xs font-semibold text-red-600">{collectionForm.errors.override_reason}</p>}</label></div>}
                        {(collectionForm.errors as Record<string, string | undefined>).collection && <p className="mt-3 text-sm font-semibold text-red-600">{(collectionForm.errors as Record<string, string | undefined>).collection}</p>}
                        <div className="mt-5 flex justify-end gap-2"><button type="button" onClick={() => setCollectionOpen(false)} disabled={collectionForm.processing} className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60">Volver</button><button disabled={collectionForm.processing} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60">{collectionForm.processing ? 'Registrando...' : 'Confirmar cobro'}</button></div>
                    </form>
                </div>
            )}

            {invoiceOpen && (
                <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-950/50 p-4 sm:flex sm:items-center sm:justify-center">
                    <form onSubmit={submitInvoice} className="mx-auto w-full max-w-3xl rounded-xl bg-white p-5 shadow-xl">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <h2 className="text-lg font-semibold text-slate-950">Facturar preventa</h2>
                                <p className="mt-1 text-sm text-slate-600">Se usarán únicamente las cantidades preparadas y los precios guardados en la preventa.</p>
                            </div>
                            <button type="button" onClick={() => setInvoiceOpen(false)} disabled={invoiceForm.processing} className="text-sm font-semibold text-slate-500 hover:text-slate-800">Cerrar</button>
                        </div>

                        <div className="mt-4 grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm sm:grid-cols-2">
                            <div><span className="text-slate-500">Cliente</span><div className="font-semibold text-slate-900">{preSale.customer?.commercial_name || preSale.customer?.name || '-'}</div></div>
                            <div><span className="text-slate-500">NIT</span><div className="font-semibold text-slate-900">{preSale.customer?.doc_number || '-'}</div></div>
                            <div><span className="text-slate-500">Sucursal</span><div className="font-semibold text-slate-900">{preSale.branch?.name || '-'}</div></div>
                            <div><span className="text-slate-500">Jornada</span><div className="font-semibold text-slate-900">{preSale.work_day?.work_date || '-'}</div></div>
                        </div>

                        <div className="mt-4 overflow-x-auto rounded-lg border border-slate-200">
                            <table className="min-w-full text-sm">
                                <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th className="px-3 py-2">Producto</th><th className="px-3 py-2 text-right">Preparado</th><th className="px-3 py-2 text-right">Precio</th><th className="px-3 py-2 text-right">Total</th></tr></thead>
                                <tbody className="divide-y divide-slate-100">
                                    {preSale.items.filter((item) => (item.picked_quantity ?? 0) > 0).map((item) => <tr key={item.id}><td className="px-3 py-2 font-medium text-slate-900">{item.product_name}</td><td className="px-3 py-2 text-right">{formatNumber(item.picked_quantity ?? 0)}</td><td className="px-3 py-2 text-right">Q {formatMoney(item.unit_price)}</td><td className="px-3 py-2 text-right">Q {formatMoney(preparedLineTotal(item))}</td></tr>)}
                                </tbody>
                                <tfoot className="bg-slate-50 font-semibold text-slate-900"><tr><td colSpan={3} className="px-3 py-2 text-right">Total</td><td className="px-3 py-2 text-right">Q {formatMoney(preSale.prepared_total)}</td></tr></tfoot>
                            </table>
                        </div>

                        <div className="mt-5 grid gap-4 sm:grid-cols-2">
                            <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm"><div className="text-xs font-semibold text-slate-600">Documento</div><div className="mt-1 font-semibold text-slate-900">Comprobante interno</div></div>
                            <label className="block"><span className="text-xs font-semibold text-slate-600">Condición de pago</span><select value={invoiceForm.data.payment_condition} onChange={(event) => invoiceForm.setData('payment_condition', event.target.value as 'paid' | 'credit')} className="mt-1 h-10 w-full rounded-lg border-slate-200 text-sm"><option value="paid">Contado</option>{invoiceOptions.credit_enabled && <option value="credit">Crédito</option>}</select>{invoiceForm.errors.payment_condition && <p className="mt-1 text-xs font-semibold text-red-600">{invoiceForm.errors.payment_condition}</p>}</label>
                            {invoiceForm.data.payment_condition === 'paid' && <label className="block"><span className="text-xs font-semibold text-slate-600">Forma de pago</span><select value={invoiceForm.data.payment_method} onChange={(event) => invoiceForm.setData('payment_method', event.target.value as 'cash' | 'card' | 'transfer' | 'check')} className="mt-1 h-10 w-full rounded-lg border-slate-200 text-sm">{invoiceOptions.payment_methods.map((method) => <option key={method} value={method}>{paymentMethodLabel(method)}</option>)}</select>{invoiceForm.errors.payment_method && <p className="mt-1 text-xs font-semibold text-red-600">{invoiceForm.errors.payment_method}</p>}</label>}
                            {invoiceForm.data.payment_condition === 'credit' && <label className="block"><span className="text-xs font-semibold text-slate-600">Fecha de vencimiento</span><input type="date" value={invoiceForm.data.due_date} onChange={(event) => invoiceForm.setData('due_date', event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-200 text-sm" /></label>}
                        </div>
                        <label className="mt-4 block"><span className="text-xs font-semibold text-slate-600">Nota</span><textarea rows={3} value={invoiceForm.data.note} onChange={(event) => invoiceForm.setData('note', event.target.value)} className="mt-1 w-full rounded-lg border-slate-200 text-sm" /></label>

                        <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                            {stockDeductionTiming === 'picking'
                                ? 'Al confirmar, se creará el comprobante interno. El stock y las reservas ya fueron procesados durante la preparación.'
                                : 'Al confirmar, se creará el comprobante interno, se descontará stock físico y se cerrarán las reservas de esta preventa.'}
                            {' La certificación FEL se realiza después y no modifica estos efectos.'}
                        </div>
                        {invoiceForm.errors.pre_sale && <p className="mt-2 text-sm font-semibold text-red-600">{invoiceForm.errors.pre_sale}</p>}

                        <div className="mt-5 flex justify-end gap-2"><button type="button" onClick={() => setInvoiceOpen(false)} disabled={invoiceForm.processing} className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60">Cancelar</button><button disabled={invoiceForm.processing} className="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700 disabled:opacity-60">{invoiceForm.processing ? 'Generando...' : 'Generar comprobante'}</button></div>
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
        converted: 'Facturada',
        cancelled: 'Cancelada',
    };

    return labels[status] ?? status;
}

function felLabel(status: FelState['status']) {
    return {
        not_requested: 'Pendiente de solicitar',
        pending: 'En proceso',
        failed: 'Fallida; puede reintentarse manualmente',
        unknown: 'Estado incierto',
        certified: 'Certificada',
    }[status];
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

function preparedLineTotal(item: PreSaleItem) {
    const quantity = item.quantity || 1;
    const pickedQuantity = item.picked_quantity ?? 0;

    return Math.max(0, (pickedQuantity * item.unit_price) - ((item.discount / quantity) * pickedQuantity));
}

function paymentMethodLabel(method?: InvoiceOptions['payment_methods'][number] | null) {
    return ({ cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia', check: 'Cheque' } as Record<string, string>)[method ?? ''] ?? 'Sin definir';
}

function custodyLabel(status?: string | null) {
    return ({ held_by_collector: 'En custodia del cobrador', posted_to_branch_cash: 'Registrado en caja', not_applicable: 'No aplica' } as Record<string, string>)[status ?? ''] ?? 'No aplica';
}
