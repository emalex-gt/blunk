import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ConfirmDialog from '@/Components/ConfirmDialog';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Collector = { id: number; name: string };
type Collection = { payment_method?: string | null; collected_by?: { name: string } | null; collected_at?: string | null };
type Method = 'cash' | 'card' | 'transfer' | 'check';
type Entry = { id: number; collection_responsibility: string; agreed_payment_method_snapshot?: Method | null; payment_policy: { allowed_methods: Method[]; primary_method: Method | null }; external_eligibility: { eligible: boolean; responsibility?: string | null }; collection?: Collection | null; financial_collection?: Collection | null; reconciliation?: { id: number; delivery_status: string; not_delivered_reason?: string | null; notes?: string | null; reconciled_at?: string | null } | null; operation_return?: { status: string; reason: string; goods_received_at?: string | null; completed_at?: string | null } | null; can_register_return?: boolean; pre_sale?: { customer?: { name: string; commercial_name?: string | null } }; sale?: { id: number; business_number?: number | null; total: number; payment_status?: string | null; payment_method?: string | null; certification_status?: string | null; items?: Array<{ product_name: string; quantity: number }> } };
type Batch = { id: number; total_amount: number; total_pre_sales: number; reconciliation_progress: { total: number; reconciled: number; pending: number }; pre_sales: Entry[] };
const notDeliveredReasons: Array<[string, string]> = [['customer_absent', 'Cliente ausente'], ['customer_rejected', 'Cliente rechazó la entrega'], ['address_issue', 'Problema con la dirección'], ['business_closed', 'Negocio cerrado'], ['damaged_goods', 'Mercancía dañada'], ['other', 'Otro']];

export default function Show({ batch, can_reconcile_external_delivery, can_override_external_delivery_collector, branch_collectors, current_user_id }: { batch: Batch; can_reconcile_external_delivery: boolean; can_override_external_delivery_collector: boolean; branch_collectors: Collector[]; current_user_id: number }) {
    return <AuthenticatedLayout><Head title={`Lote de ventas de ruta #${batch.id}`} /><main className="mx-auto max-w-6xl space-y-5 px-4 py-6"><Link href={route('routes.delivery-batches.index')} className="text-sm font-semibold text-indigo-600">Lotes de ventas de ruta</Link><section><h1 className="text-2xl font-semibold">Lote de ventas de ruta #{batch.id}</h1><p className="text-sm text-slate-500">{batch.total_pre_sales} preventas · Q {Number(batch.total_amount).toFixed(2)}</p></section>{can_reconcile_external_delivery && <section className="rounded-lg border border-indigo-200 bg-indigo-50 p-4"><h2 className="font-semibold">Conciliar entrega</h2><p className="text-sm">{batch.reconciliation_progress.reconciled} de {batch.reconciliation_progress.total} conciliados · {batch.reconciliation_progress.pending} pendientes</p></section>}<section className="space-y-3">{batch.pre_sales.map((entry) => <article className="rounded-lg border bg-white p-4" key={entry.id}><div className="flex flex-wrap justify-between gap-2"><div><strong>{entry.pre_sale?.customer?.commercial_name || entry.pre_sale?.customer?.name || '-'}</strong><div className="text-sm text-slate-600">Comprobante {entry.sale ? `#${entry.sale.business_number ?? entry.sale.id}` : '-'} · FEL: {felLabel(entry.sale?.certification_status)}</div><div className="text-sm">{financialLabel(entry)}</div>{entry.operation_return && <p className="mt-2 text-sm font-semibold text-rose-700">Entregada {entry.reconciliation?.reconciled_at ? new Date(entry.reconciliation.reconciled_at).toLocaleString() : ''} → Devuelta {entry.operation_return.completed_at ? new Date(entry.operation_return.completed_at).toLocaleString() : ''} → Venta anulada. Motivo: {entry.operation_return.reason}</p>}</div><div className="space-y-2">{can_reconcile_external_delivery && <ReconciliationForm batch={batch} entry={entry} actorId={current_user_id} canOverrideCollector={can_override_external_delivery_collector} collectors={branch_collectors} />}{entry.can_register_return && entry.reconciliation?.id && <ReturnForm entry={entry} />}</div></div></article>)}</section></main></AuthenticatedLayout>;
}

function ReturnForm({ entry }: { entry: Entry }) {
    const [reason, setReason] = useState('');
    const [note, setNote] = useState('');
    const [received, setReceived] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [open, setOpen] = useState(false);
    const [key] = useState(() => crypto.randomUUID());
    const confirm = () => {
        if (processing || !entry.reconciliation?.id || !received || !reason.trim()) return;
        router.post(route('routes.external-delivery-reconciliation-items.return', entry.reconciliation.id), {
            idempotency_key: key, reason: reason.trim(), note: note.trim() || null, goods_received: true,
        }, { preserveScroll: true, onStart: () => setProcessing(true), onFinish: () => { setProcessing(false); setConfirmOpen(false); } });
    };
    if (!open) return <button type="button" className="rounded border border-rose-300 px-3 py-2 text-sm font-semibold text-rose-700" onClick={() => setOpen(true)}>Registrar devolución</button>;
    return <div className="space-y-2 rounded border border-rose-200 bg-rose-50 p-3 text-sm">
        <div className="flex items-center justify-between"><h3 className="font-semibold">Registrar devolución</h3><button type="button" className="text-slate-600 underline" onClick={() => setOpen(false)}>Cancelar</button></div>
        <p>Cliente: {entry.pre_sale?.customer?.commercial_name || entry.pre_sale?.customer?.name || '-'} · Venta #{entry.sale?.business_number ?? entry.sale?.id} · Q {Number(entry.sale?.total ?? 0).toFixed(2)}</p>
        <p>Entrega original: {entry.reconciliation?.reconciled_at ? new Date(entry.reconciliation.reconciled_at).toLocaleString() : '-'}</p>
        <ul className="list-inside list-disc">{entry.sale?.items?.map((item, index) => <li key={index}>{item.product_name} · {item.quantity}</li>)}</ul>
        <textarea className="w-full rounded border p-2" placeholder="Motivo obligatorio" value={reason} onChange={event => setReason(event.target.value)} />
        <textarea className="w-full rounded border p-2" placeholder="Nota opcional" value={note} onChange={event => setNote(event.target.value)} />
        <label className="flex gap-2"><input type="checkbox" checked={received} onChange={event => setReceived(event.target.checked)} />Confirmo que todos los productos fueron recibidos físicamente de vuelta.</label>
        <button type="button" disabled={!received || !reason.trim() || processing} className="rounded bg-rose-700 px-3 py-2 font-semibold text-white disabled:opacity-50" onClick={() => setConfirmOpen(true)}>Registrar devolución</button>
        <ConfirmDialog open={confirmOpen} title="Confirmar devolución" message="Confirma que los productos fueron recibidos de vuelta. La venta se anulará y el inventario se restaurará." confirmLabel="Confirmar devolución" processing={processing} onCancel={() => setConfirmOpen(false)} onConfirm={confirm} />
    </div>;
}

function ReconciliationForm({ batch, entry, actorId, canOverrideCollector, collectors }: { batch: Batch; entry: Entry; actorId: number; canOverrideCollector: boolean; collectors: Collector[] }) {
    const [status, setStatus] = useState<'delivered' | 'not_delivered'>('delivered');
    const [reason, setReason] = useState('customer_absent');
    const [notes, setNotes] = useState('');
    const [collected, setCollected] = useState(false);
    const [method, setMethod] = useState<Method | ''>(() => defaultMethod(entry));
    const [reference, setReference] = useState('');
    const [collectorId, setCollectorId] = useState(actorId);
    const [collectedAt, setCollectedAt] = useState(localDateTime());
    const [overrideReason, setOverrideReason] = useState('');
    const [receivingNow, setReceivingNow] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    if (entry.reconciliation) return <span className="text-sm font-semibold text-emerald-700">Conciliado: {entry.reconciliation.delivery_status === 'delivered' ? 'Entregado' : 'No entregado'}</span>;
    if (!entry.external_eligibility.eligible) return <span className="text-sm font-semibold text-amber-800">Revisión administrativa requerida</span>;

    const deliveryAgent = entry.external_eligibility.responsibility === 'delivery_agent';
    const selectStatus = (next: 'delivered' | 'not_delivered') => {
        setStatus(next);
        if (next === 'not_delivered') {
            setCollected(false);
            setMethod('');
            setReference('');
            setCollectorId(actorId);
            setCollectedAt('');
            setOverrideReason('');
            setReceivingNow(false);
        } else {
            setMethod(defaultMethod(entry));
            setCollectedAt(localDateTime());
        }
    };
    const post = () => {
        if (processing) return;
        const payment = status === 'delivered' && collected;
        router.post(route('routes.delivery-batches.external-reconciliation.store', [batch.id, entry.id]), {
            idempotency_key: crypto.randomUUID(),
            delivery_status: status,
            not_delivered_reason: status === 'not_delivered' ? reason : null,
            notes: notes || null,
            collected: payment,
            ...(payment ? {
                amount: entry.sale?.total,
                payment_method: method,
                collected_by: collectorId,
                collected_at: new Date(collectedAt).toISOString(),
                reference: reference || null,
                override_reason: collectorId !== actorId ? overrideReason : null,
                receive_cash_in_current_session: receivingNow,
            } : {}),
        }, { preserveScroll: true, onStart: () => setProcessing(true), onFinish: () => setProcessing(false) });
        setConfirmOpen(false);
    };
    const save = () => status === 'not_delivered' ? setConfirmOpen(true) : post();

    return <div className="min-w-72 space-y-2 text-xs">
        <div className="flex gap-2">
            <button type="button" className={status === 'delivered' ? 'rounded bg-emerald-600 px-2 py-1 text-white' : 'rounded border px-2 py-1'} onClick={() => selectStatus('delivered')}>Entregado</button>
            <button type="button" className={status === 'not_delivered' ? 'rounded bg-amber-600 px-2 py-1 text-white' : 'rounded border px-2 py-1'} onClick={() => selectStatus('not_delivered')}>No entregado</button>
        </div>
        {status === 'not_delivered' && <>
            <select className="w-full rounded border p-1" value={reason} onChange={(event) => setReason(event.target.value)}>{notDeliveredReasons.map(([value, label]) => <option value={value} key={value}>{label}</option>)}</select>
            <textarea className="w-full rounded border p-1" placeholder={reason === 'other' ? 'Nota obligatoria' : 'Notas'} value={notes} onChange={(event) => setNotes(event.target.value)} />
            <p className="rounded border border-red-200 bg-red-50 p-2 font-semibold text-red-800">Esta operación se anulará y los productos volverán al inventario.</p>
        </>}
        {status === 'delivered' && deliveryAgent && entry.sale?.payment_status === 'unpaid' && <>
            <label className="flex gap-2"><input type="checkbox" checked={collected} onChange={(event) => setCollected(event.target.checked)} />Cobrado</label>
            {collected && <>
                <div className="rounded border border-slate-200 p-2">
                    <p>Importe completo: <strong>Q {Number(entry.sale.total).toFixed(2)}</strong></p>
                    <label className="mt-1 block">Método<select className="mt-1 w-full rounded border p-1" value={method} onChange={(event) => setMethod(event.target.value as Method)}>{entry.payment_policy.allowed_methods.map((value) => <option value={value} key={value}>{methodLabel(value)}</option>)}</select></label>
                    <label className="mt-1 block">Cobrador{canOverrideCollector ? <select className="mt-1 w-full rounded border p-1" value={collectorId} onChange={(event) => setCollectorId(Number(event.target.value))}>{collectors.map((collector) => <option value={collector.id} key={collector.id}>{collector.name}</option>)}</select> : <span className="mt-1 block rounded bg-slate-50 p-1">Usuario actual</span>}</label>
                    {collectorId !== actorId && <textarea className="mt-1 w-full rounded border p-1" placeholder="Motivo obligatorio del override" value={overrideReason} onChange={(event) => setOverrideReason(event.target.value)} />}
                    <label className="mt-1 block">Fecha y hora del cobro<input className="mt-1 w-full rounded border p-1" type="datetime-local" value={collectedAt} onChange={(event) => setCollectedAt(event.target.value)} /></label>
                    <input className="mt-1 w-full rounded border p-1" placeholder="Referencia" value={reference} onChange={(event) => setReference(event.target.value)} />
                </div>
                {method === 'cash' && <label className="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"><input type="checkbox" checked={receivingNow} onChange={(event) => setReceivingNow(event.target.checked)} /><span>Confirmo que este efectivo está siendo recibido físicamente ahora en la caja abierta actual.</span></label>}
            </>}
        </>}
        <button type="button" disabled={processing} className="rounded bg-indigo-600 px-2 py-1 font-semibold text-white disabled:opacity-60" onClick={save}>{status === 'not_delivered' ? 'Anular operación' : 'Guardar'}</button>
        <ConfirmDialog open={confirmOpen} title="Anular operación" message="Esta operación se anulará y los productos volverán al inventario." confirmLabel="Anular operación" processing={processing} onCancel={() => setConfirmOpen(false)} onConfirm={post} />
    </div>;
}
function localDateTime() { const date = new Date(); date.setMinutes(date.getMinutes() - date.getTimezoneOffset()); return date.toISOString().slice(0, 16); }
function defaultMethod(entry: Entry): Method { const allowed = entry.payment_policy.allowed_methods; return allowed.includes(entry.agreed_payment_method_snapshot as Method) ? entry.agreed_payment_method_snapshot as Method : allowed.includes(entry.payment_policy.primary_method as Method) ? entry.payment_policy.primary_method as Method : allowed[0] ?? 'cash'; }
function financialLabel(entry: Entry) { if (entry.operation_return) return 'Operación devuelta · sin cobro pendiente'; if (entry.sale?.payment_status === 'paid') { const collection = entry.financial_collection ?? entry.collection; return `Cobrado · ${methodLabel(collection?.payment_method ?? entry.sale.payment_method)} · ${collection?.collected_by?.name ?? '-'}${collection?.collected_at ? ` · ${new Date(collection.collected_at).toLocaleString()}` : ''}`; } return entry.collection_responsibility === 'review_required' ? 'Revisión administrativa requerida' : `Pendiente de cobro · ${entry.collection_responsibility === 'delivery_agent' ? 'entregador' : 'preventista'}`; }
function methodLabel(method?: string | null) { return ({ cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia', check: 'Cheque' } as Record<string, string>)[method ?? ''] ?? 'Sin definir'; }
function felLabel(status?: string | null) { return ({ certified: 'Certificada', failed: 'Fallida', unknown: 'Requiere conciliación', pending: 'En proceso' } as Record<string, string>)[status ?? ''] ?? 'Pendiente manual'; }
