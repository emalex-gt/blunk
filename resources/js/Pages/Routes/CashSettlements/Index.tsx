import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useMemo, useState } from 'react';

type Collector = { id: number; name: string };
type Eligible = { origin: 'pre_sale_collection' | 'delivery_collection'; collection_id: number; amount: number; collected_at?: string | null; customer_name?: string | null; reference?: string | null };
type Settlement = { id: number; status: 'draft' | 'confirmed' | 'cancelled'; collector?: Collector | null; expected_amount: number; received_amount?: number | null; difference_amount?: number | null; received_by_name?: string | null; cash_register_session_id?: number | null; created_at?: string | null };
type Props = { settlements: { data: Settlement[] }; collectors: Collector[]; selected_collector_id?: number | null; eligible_collections: Eligible[]; can_create: boolean; can_confirm: boolean; can_review: boolean; can_view_variances: boolean };

const money = (amount: number) => `Q ${Number(amount || 0).toFixed(2)}`;

export default function Index({ settlements, collectors, selected_collector_id, eligible_collections, can_create, can_view_variances }: Props) {
    const [collectorId, setCollectorId] = useState(String(selected_collector_id ?? ''));
    const [selected, setSelected] = useState<Record<string, Eligible>>({});
    const selectedValues = useMemo(() => Object.values(selected), [selected]);
    const selectedTotal = selectedValues.reduce((total, item) => total + Number(item.amount), 0);
    const key = () => crypto.randomUUID();

    function chooseCollector(value: string) {
        setCollectorId(value);
        setSelected({});
        router.get(route('routes.cash-settlements.index'), value ? { collector_id: value } : {}, { preserveState: false, preserveScroll: true });
    }

    function toggle(item: Eligible) {
        const itemKey = `${item.origin}:${item.collection_id}`;
        setSelected(current => {
            const next = { ...current };
            if (next[itemKey]) delete next[itemKey]; else next[itemKey] = item;
            return next;
        });
    }

    function create(event: FormEvent) {
        event.preventDefault();
        if (!collectorId || selectedValues.length === 0) return;
        router.post(route('routes.cash-settlements.store'), {
            idempotency_key: key(),
            collector_user_id: Number(collectorId),
            items: selectedValues.map(item => ({ origin: item.origin, collection_id: item.collection_id })),
        });
    }

    return <AuthenticatedLayout><Head title="Liquidaciones de efectivo" /><main className="mx-auto max-w-6xl space-y-6 px-4 py-6 sm:px-6">
        <header><Link href={route('routes.delivery-batches.index')} className="text-sm font-semibold text-indigo-600">← Lotes de ventas de ruta</Link><div className="mt-2 flex flex-wrap items-center justify-between gap-3"><div><h1 className="text-2xl font-bold text-slate-950">Liquidaciones de efectivo</h1><p className="mt-1 text-sm text-slate-600">Registra la recepción física actual del efectivo bajo custodia de rutas.</p></div>{can_view_variances && <Link href={route('routes.cash-settlement-variances.index')} className="rounded-lg border border-indigo-600 px-3 py-2 text-sm font-semibold text-indigo-700">Diferencias</Link>}</div></header>

        {can_create && <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5"><h2 className="text-lg font-semibold">Nueva liquidación</h2><p className="mt-1 text-sm text-slate-600">Selecciona un cobrador y las collections de efectivo que recibes físicamente ahora.</p><div className="mt-4 max-w-md"><label className="text-sm font-medium text-slate-700">Cobrador<select value={collectorId} onChange={event => chooseCollector(event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300"><option value="">Selecciona un cobrador</option>{collectors.map(collector => <option key={collector.id} value={collector.id}>{collector.name}</option>)}</select></label></div>{selected_collector_id && <form onSubmit={create} className="mt-4 space-y-3"><div className="rounded-lg border border-slate-200"><div className="flex items-center justify-between border-b bg-slate-50 px-4 py-3 text-sm"><span className="font-medium">Efectivo elegible</span><span>{selectedValues.length} seleccionado(s) · <strong>{money(selectedTotal)}</strong></span></div>{eligible_collections.length === 0 ? <p className="p-4 text-sm text-slate-500">Este cobrador no tiene efectivo elegible pendiente.</p> : <div className="divide-y">{eligible_collections.map(item => { const itemKey = `${item.origin}:${item.collection_id}`; return <label key={itemKey} className="flex cursor-pointer items-start gap-3 p-4 hover:bg-slate-50"><input type="checkbox" checked={Boolean(selected[itemKey])} onChange={() => toggle(item)} className="mt-1 rounded border-slate-300 text-indigo-600" /><span className="min-w-0 flex-1"><span className="block font-medium text-slate-900">{item.customer_name || 'Cliente sin nombre'}</span><span className="block text-xs text-slate-500">{item.origin === 'pre_sale_collection' ? 'Cobro de preventa' : 'Cobro de entrega'} · {item.collected_at ? new Date(item.collected_at).toLocaleString() : 'Sin fecha'}</span></span><strong className="whitespace-nowrap">{money(item.amount)}</strong></label>; })}</div>}</div><button type="submit" disabled={selectedValues.length === 0} className="rounded-lg bg-indigo-600 px-4 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-40">Crear borrador ({money(selectedTotal)})</button></form>}</section>}

        <section className="rounded-xl border border-slate-200 bg-white shadow-sm"><div className="border-b px-4 py-4 sm:px-5"><h2 className="font-semibold">Historial de liquidaciones</h2></div>{settlements.data.length === 0 ? <p className="p-5 text-sm text-slate-500">No hay liquidaciones en esta sucursal.</p> : <div className="divide-y">{settlements.data.map(settlement => <Link key={settlement.id} href={route('routes.cash-settlements.show', settlement.id)} className="flex items-center justify-between gap-4 p-4 hover:bg-slate-50 sm:px-5"><span><span className="block font-semibold">Liquidación #{settlement.id}</span><span className="text-sm text-slate-600">{settlement.collector?.name || 'Cobrador'} · {settlement.created_at ? new Date(settlement.created_at).toLocaleString() : ''}</span>{settlement.status === 'confirmed' && <span className="mt-1 block text-xs text-slate-500">Recibido: {money(settlement.received_amount ?? 0)} · {settlement.received_by_name || '—'} · Caja #{settlement.cash_register_session_id}</span>}</span><span className="text-right"><Status status={settlement.status} /><span className="mt-1 block font-semibold">{money(settlement.expected_amount)}</span>{settlement.difference_amount != null && <span className="text-xs text-slate-500">Diferencia: {money(settlement.difference_amount ?? 0)}</span>}</span></Link>)}</div>}</section>
    </main></AuthenticatedLayout>;
}

function Status({ status }: { status: Settlement['status'] }) { const label = { draft: 'Borrador', confirmed: 'Confirmada', cancelled: 'Cancelada' }[status]; const color = { draft: 'bg-amber-100 text-amber-800', confirmed: 'bg-emerald-100 text-emerald-800', cancelled: 'bg-slate-100 text-slate-700' }[status]; return <span className={`rounded-full px-2 py-1 text-xs font-semibold ${color}`}>{label}</span>; }
