import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

type Variance = { id: number; settlement_id: number; status: string; derived_type: 'shortage' | 'overage'; difference_amount: number; remaining_amount: number; expected_amount: number; received_amount: number; opened_at?: string | null; aging_days: number; collector?: { name: string } | null; assigned_to?: { name: string } | null };
type Props = { summary: { open_shortages: { count: number; amount: number }; open_overages: { count: number; amount: number } }; variances: { data: Variance[] } };
const money = (amount: number) => `Q ${Number(amount || 0).toFixed(2)}`;

export default function Index({ summary, variances }: Props) {
    return <AuthenticatedLayout><Head title="Diferencias de liquidación" /><main className="mx-auto max-w-5xl space-y-5 p-4 sm:p-6">
        <Link href={route('routes.cash-settlements.index')} className="text-sm font-semibold text-indigo-600">← Liquidaciones</Link>
        <header><h1 className="text-2xl font-bold">Diferencias de liquidación</h1><p className="mt-1 text-sm text-slate-600">Incidencias internas de custodia; no son deuda de clientes.</p></header>
        <section className="grid gap-3 sm:grid-cols-2"><Metric label="Faltantes abiertos" value={`${summary.open_shortages.count} · ${money(summary.open_shortages.amount)}`} tone="amber" /><Metric label="Sobrantes abiertos" value={`${summary.open_overages.count} · ${money(summary.open_overages.amount)}`} tone="sky" /></section>
        <section className="overflow-hidden rounded-xl border border-slate-200 bg-white"><div className="border-b px-4 py-3"><h2 className="font-semibold">Rutas → Liquidaciones → Diferencias</h2></div>{variances.data.length === 0 ? <p className="p-5 text-sm text-slate-500">No hay diferencias de liquidación en esta sucursal.</p> : <div className="divide-y">{variances.data.map(variance => <Link key={variance.id} href={route('routes.cash-settlement-variances.show', variance.id)} className="block space-y-2 p-4 hover:bg-slate-50"><div className="flex flex-wrap items-start justify-between gap-2"><span className="font-semibold">Liquidación #{variance.settlement_id} · {variance.derived_type === 'shortage' ? 'Faltante' : 'Sobrante'}</span><span className={`rounded-full px-2 py-1 text-xs font-semibold ${variance.status === 'open' ? 'bg-amber-100 text-amber-900' : 'bg-emerald-100 text-emerald-900'}`}>{variance.status === 'open' ? 'Abierta' : 'Resuelta'}</span></div><div className="grid gap-1 text-sm text-slate-600 sm:grid-cols-3"><span>Esperado: {money(variance.expected_amount)}</span><span>Recibido: {money(variance.received_amount)}</span><span>Diferencia: {money(variance.difference_amount)}</span><span>Pendiente: {money(variance.remaining_amount)}</span><span>Cobrador: {variance.collector?.name || '—'}</span><span>Responsable: {variance.assigned_to?.name || 'Sin asignar'}</span></div><p className="text-xs text-slate-500">{variance.aging_days} día(s) desde la confirmación.</p></Link>)}</div>}</section>
    </main></AuthenticatedLayout>;
}

function Metric({ label, value, tone }: { label: string; value: string; tone: 'amber' | 'sky' }) {
    return <div className={`rounded-xl border p-4 ${tone === 'amber' ? 'border-amber-200 bg-amber-50' : 'border-sky-200 bg-sky-50'}`}><p className="text-sm text-slate-600">{label}</p><p className="mt-1 text-xl font-bold">{value}</p></div>;
}
