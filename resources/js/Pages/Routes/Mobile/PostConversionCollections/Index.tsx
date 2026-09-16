import ConfirmDialog from '@/Components/ConfirmDialog';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { makeOperationKey } from '@/lib/idempotency';
import { Head, Link, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';

type Method = 'cash' | 'card' | 'transfer' | 'check';
type Pending = { entry_id: number; pre_sale_id: number; sale_id: number; reference: string; customer: { name: string } | null; total: number; agreed_method: Method | null; work_date: string | null };
type Props = { collections: Pending[]; payment_policy: { available: boolean; allowed_methods: Method[]; reason: string | null } };
const labels: Record<Method, string> = { cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia', check: 'Cheque' };

export default function Index({ collections, payment_policy }: Props) {
    const [selected, setSelected] = useState<Pending | null>(null);
    const [method, setMethod] = useState<Method | ''>(payment_policy.allowed_methods[0] ?? '');
    const [confirming, setConfirming] = useState(false);
    const submitLock = useRef(false);
    const form = useForm({ route_delivery_batch_pre_sale_id: 0, payment_method: '' as Method | '', idempotency_key: makeOperationKey('post-conversion-collection'), reference: '' });

    const open = (row: Pending) => {
        const firstMethod = payment_policy.allowed_methods[0] ?? '';
        setSelected(row); setMethod(firstMethod); form.clearErrors();
        form.setData({ route_delivery_batch_pre_sale_id: row.entry_id, payment_method: firstMethod, idempotency_key: makeOperationKey('post-conversion-collection'), reference: '' });
        setConfirming(false);
    };
    const submit = () => {
        if (!selected || !method || form.processing || submitLock.current) return;
        submitLock.current = true; form.setData('payment_method', method);
        form.post(route('routes.post-conversion-collections.store'), { preserveScroll: true, onSuccess: () => { setSelected(null); setConfirming(false); }, onFinish: () => { submitLock.current = false; } });
    };

    return <AuthenticatedLayout><Head title="Cobros pendientes" /><main className="mx-auto max-w-xl space-y-4 px-4 pb-24 pt-5">
        <Link href={route('routes.mobile.zones')} className="text-sm font-semibold text-indigo-600">← Mis rutas</Link>
        <header><h1 className="text-2xl font-semibold text-slate-950">Cobros pendientes</h1><p className="mt-1 text-sm text-slate-500">Ventas de ruta pendientes de cobro a tu cargo.</p></header>
        {!payment_policy.available && <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-900">{payment_policy.reason}</div>}
        {collections.length === 0 ? <div className="rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600">No tienes cobros pendientes.</div> : collections.map(row => <section key={row.entry_id} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p className="text-xs font-semibold uppercase text-slate-400">{row.reference}{row.work_date ? ` · Jornada ${row.work_date}` : ''}</p><h2 className="mt-1 text-lg font-semibold text-slate-950">{row.customer?.name ?? 'Cliente'}</h2><p className="mt-3 text-2xl font-bold text-slate-950">Q {Number(row.total).toFixed(2)}</p><p className="mt-2 text-sm text-slate-600">Método acordado: <span className="font-semibold text-slate-900">{row.agreed_method ? labels[row.agreed_method] : 'Sin definir'}</span></p>{payment_policy.available && <button type="button" onClick={() => open(row)} className="mt-4 w-full rounded-xl bg-emerald-700 px-4 py-3 text-base font-semibold text-white">Registrar cobro</button>}</section>)}
        {selected && <div className="fixed inset-0 z-50 flex items-end bg-slate-950/40 p-4 sm:items-center sm:justify-center"><div className="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl"><h2 className="text-lg font-semibold text-slate-950">Registrar cobro</h2><p className="mt-1 text-sm text-slate-500">{selected.customer?.name ?? 'Cliente'} · Q {Number(selected.total).toFixed(2)}</p><p className="mt-3 text-sm text-slate-600">Método acordado: <strong>{selected.agreed_method ? labels[selected.agreed_method] : 'Sin definir'}</strong></p>{payment_policy.allowed_methods.length === 1 ? <p className="mt-3 rounded-lg bg-slate-50 p-3 text-sm text-slate-700">Método real: <strong>{labels[payment_policy.allowed_methods[0]]}</strong></p> : <label className="mt-3 block text-sm font-medium text-slate-700">Método real<select value={method} onChange={event => setMethod(event.target.value as Method)} className="mt-1 w-full rounded-xl border-slate-300">{payment_policy.allowed_methods.map(value => <option key={value} value={value}>{labels[value]}</option>)}</select></label>}<label className="mt-3 block text-sm font-medium text-slate-700">Referencia opcional<input value={form.data.reference} onChange={event => form.setData('reference', event.target.value)} className="mt-1 w-full rounded-xl border-slate-300" /></label>{form.errors.payment_method && <p className="mt-2 text-sm font-semibold text-red-700">{form.errors.payment_method}</p>}<div className="mt-5 grid grid-cols-2 gap-2"><button type="button" disabled={form.processing} onClick={() => setSelected(null)} className="rounded-xl border border-slate-200 px-4 py-3 text-sm font-semibold">Cancelar</button><button type="button" disabled={form.processing || !method} onClick={() => setConfirming(true)} className="rounded-xl bg-emerald-700 px-4 py-3 text-sm font-semibold text-white disabled:opacity-60">Confirmar</button></div></div></div>}
        <ConfirmDialog open={confirming} title="¿Registrar cobro?" message="La venta quedará marcada como pagada por el importe total." confirmLabel="Sí, registrar cobro" processing={form.processing} onCancel={() => setConfirming(false)} onConfirm={submit} />
    </main></AuthenticatedLayout>;
}
