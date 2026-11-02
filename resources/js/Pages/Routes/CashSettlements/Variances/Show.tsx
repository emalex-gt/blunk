import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useRef, useState } from 'react';

type Variance = { id: number; settlement_id: number; status: 'open' | 'resolved'; derived_type: 'shortage' | 'overage'; difference_amount: number; remaining_amount: number; reason_code: string; explanation: string; collector_user_id: number; collector?: { name: string } | null; assigned_to?: { id: number; name: string } | null; expected_amount: number; received_amount: number; original_movement?: { id: number; amount: number; type: string } | null; items: { id: number; amount: number; customer_name?: string | null }[]; events: { id: number; type: string; note: string; occurred_at?: string | null; recorded_by?: { name: string } | null }[]; resolutions: { id: number; type: string; amount: number; note: string; movement?: { id: number; amount: number } | null; counterparty?: { name: string } | null }[] };
type Props = { variance: Variance; can_manage: boolean; can_resolve: boolean; assignees: { id: number; name: string }[] };
type ResolutionIntent = { idempotency_key: string; type: 'shortage_cash_received' | 'overage_cash_returned'; amount: number; counterparty_user_id: number; note: string };
const money = (amount: number) => `Q ${Number(amount || 0).toFixed(2)}`;
const key = () => crypto.randomUUID();

export default function Show({ variance, can_manage, can_resolve, assignees }: Props) {
    const [note, setNote] = useState('');
    const [eventType, setEventType] = useState('note');
    const [amount, setAmount] = useState('');
    const [assignedTo, setAssignedTo] = useState(String(variance.assigned_to?.id ?? ''));
    const [resolutionProcessing, setResolutionProcessing] = useState(false);
    const [resolutionIntentActive, setResolutionIntentActive] = useState(false);
    const [resolutionError, setResolutionError] = useState('');
    const resolutionIntentRef = useRef<ResolutionIntent | null>(null);
    const resolutionSubmitLock = useRef(false);
    const resolutionType = variance.derived_type === 'shortage' ? 'shortage_cash_received' : 'overage_cash_returned';
    const event = (submit: FormEvent) => { submit.preventDefault(); if (!note.trim()) return; router.post(route('routes.cash-settlement-variances.events.store', variance.id), { idempotency_key: key(), type: eventType, note }); };
    const resolve = (submit: FormEvent) => {
        submit.preventDefault();
        if (resolutionSubmitLock.current || resolutionProcessing) return;

        const intent = resolutionIntentRef.current ?? (() => {
            if (!note.trim() || !amount) return null;

            return {
                idempotency_key: key(),
                type: resolutionType,
                amount: Number(amount),
                counterparty_user_id: variance.collector_user_id,
                note: note.trim(),
            };
        })();
        if (!intent) return;

        resolutionIntentRef.current = intent;
        resolutionSubmitLock.current = true;
        setResolutionIntentActive(true);
        setResolutionError('');
        router.post(route('routes.cash-settlement-variances.resolutions.store', variance.id), intent, {
            preserveScroll: true,
            onStart: () => setResolutionProcessing(true),
            onSuccess: () => {
                resolutionIntentRef.current = null;
                setResolutionIntentActive(false);
                setAmount('');
                setNote('');
            },
            onError: errors => setResolutionError(String(errors.resolution || errors.amount || errors.variance || 'No se pudo confirmar el resultado de la resolución. Reintenta la misma operación.')),
            onFinish: () => {
                resolutionSubmitLock.current = false;
                setResolutionProcessing(false);
            },
        });
    };
    return <AuthenticatedLayout><Head title={`Diferencia #${variance.id}`} /><main className="mx-auto max-w-4xl space-y-5 p-4 sm:p-6">
        <Link href={route('routes.cash-settlement-variances.index')} className="text-sm font-semibold text-orange-600">← Diferencias</Link>
        <header><h1 className="text-2xl font-bold">{variance.derived_type === 'shortage' ? 'Faltante' : 'Sobrante'} de liquidación</h1><p className="mt-1 text-sm text-slate-600">Liquidación #{variance.settlement_id} · Cobrador: {variance.collector?.name || '—'} · {variance.status === 'open' ? 'Abierta' : 'Resuelta'}</p></header>
        <section className="grid gap-3 sm:grid-cols-3"><Metric label="Diferencia" value={money(variance.difference_amount)} /><Metric label="Pendiente" value={money(variance.remaining_amount)} /><Metric label="Original" value={`${money(variance.expected_amount)} → ${money(variance.received_amount)}`} /></section>
        <section className="rounded-xl border bg-white p-4"><h2 className="font-semibold">Evidencia de la liquidación</h2><p className="mt-1 text-sm text-slate-600">Motivo: {variance.reason_code}. {variance.explanation}</p>{variance.original_movement && <p className="mt-2 text-sm">Movimiento original #{variance.original_movement.id}: {money(variance.original_movement.amount)}.</p>}<div className="mt-3 divide-y rounded border">{variance.items.map(item => <div key={item.id} className="flex justify-between p-2 text-sm"><span>{item.customer_name || 'Collection de ruta'}</span><strong>{money(item.amount)}</strong></div>)}</div></section>
        {can_manage && <section className="rounded-xl border bg-white p-4"><h2 className="font-semibold">Responsable de seguimiento</h2><form onSubmit={event => { event.preventDefault(); router.patch(route('routes.cash-settlement-variances.assignment.update', variance.id), { idempotency_key: key(), assigned_to: assignedTo ? Number(assignedTo) : null }); }} className="mt-3 flex flex-wrap gap-2"><select value={assignedTo} onChange={input => setAssignedTo(input.target.value)} className="rounded border-slate-300"><option value="">Sin asignar</option>{assignees.map(user => <option key={user.id} value={user.id}>{user.name}</option>)}</select><button className="rounded border border-slate-700 px-3 py-2 text-sm font-semibold">Actualizar</button></form></section>}
        <section className="rounded-xl border bg-white p-4"><h2 className="font-semibold">Seguimiento append-only</h2>{variance.events.length === 0 ? <p className="mt-2 text-sm text-slate-500">Sin eventos todavía.</p> : <div className="mt-2 space-y-2">{variance.events.map(event => <article key={event.id} className="rounded border p-2 text-sm"><strong>{event.type}</strong> · {event.note}<span className="ml-2 text-slate-500">{event.recorded_by?.name || '—'}</span></article>)}</div>}{can_manage && variance.status === 'open' && <form onSubmit={event} className="mt-3 grid gap-2 sm:grid-cols-[160px_1fr_auto]"><select value={eventType} onChange={input => setEventType(input.target.value)} className="rounded border-slate-300"><option value="note">Nota</option><option value="investigation">Investigación</option><option value="collector_contact">Contacto con cobrador</option></select><input value={note} onChange={input => setNote(input.target.value)} required className="rounded border-slate-300" placeholder="Detalle del seguimiento" /><button className="rounded bg-slate-800 px-3 py-2 text-sm font-semibold text-white">Registrar</button></form>}</section>
        <section className="rounded-xl border bg-white p-4"><h2 className="font-semibold">Resoluciones físicas</h2>{variance.resolutions.length === 0 ? <p className="mt-2 text-sm text-slate-500">Aún no hay entradas o salidas físicas posteriores.</p> : <div className="mt-2 space-y-2">{variance.resolutions.map(resolution => <article key={resolution.id} className="rounded border p-2 text-sm"><strong>{resolution.type === 'shortage_cash_received' ? 'Efectivo recibido' : 'Efectivo devuelto'}: {money(resolution.amount)}</strong> · {resolution.note}</article>)}</div>}{can_resolve && variance.status === 'open' && <form onSubmit={resolve} className="mt-3 grid gap-2 sm:grid-cols-[160px_1fr_auto]"><input required disabled={resolutionIntentActive} min="0.01" max={variance.remaining_amount} step="0.01" type="number" value={amount} onChange={input => setAmount(input.target.value)} className="rounded border-slate-300 disabled:bg-slate-100" placeholder="Importe" /><input disabled={resolutionIntentActive} value={note} onChange={input => setNote(input.target.value)} required className="rounded border-slate-300 disabled:bg-slate-100" placeholder="Nota de evidencia física" /><button disabled={resolutionProcessing || (!resolutionIntentActive && (!note.trim() || !amount))} className="rounded bg-emerald-700 px-3 py-2 text-sm font-semibold text-white disabled:opacity-60">{resolutionProcessing ? 'Registrando…' : resolutionIntentActive ? 'Reintentar misma resolución' : variance.derived_type === 'shortage' ? 'Recibir efectivo' : 'Devolver efectivo'}</button>{resolutionIntentActive && <p className="sm:col-span-3 text-xs text-slate-600">Esta intención conserva su importe, nota y llave idempotente. No modifiques ni reenvíes datos distintos hasta conocer el resultado.</p>}{resolutionError && <p className="sm:col-span-3 rounded border border-rose-200 bg-rose-50 p-2 text-sm text-rose-800">{resolutionError}</p>}</form>}</section>
    </main></AuthenticatedLayout>;
}

function Metric({ label, value }: { label: string; value: string }) { return <div className="rounded-xl border bg-white p-4"><p className="text-xs text-slate-500">{label}</p><p className="mt-1 text-lg font-bold">{value}</p></div>; }
