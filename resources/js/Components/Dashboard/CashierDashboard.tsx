import { formatCurrency } from '@/utils/currency';
import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

export type CashierDashboardData = {
    cash_session: { status: 'open' | 'closed'; id?: number; opened_at?: string | null; expected_cash?: string | number } | null;
    sales_period: 'current_session' | 'today';
    sales_today: { count: number; total: string | number; average_ticket: string | number };
    collections_today: { count: number; total: string | number };
    payment_mix: Array<{ method: string; label: string; count: number; total: number; percentage: number }>;
    pending: { active_pos_drafts_count: number };
    recent_activity: Array<{ key: string; type: 'sale' | 'payment' | 'cash'; title: string; detail: string; amount: number; created_at: string | null; href: string | null }>;
    attention: Array<{ key: string; severity: 'info' | 'warning'; title: string; detail: string; href: string }>;
};

export default function CashierDashboard({ data, country }: { data: CashierDashboardData; country: string }) {
    const open = data.cash_session?.status === 'open';
    const period = data.sales_period === 'current_session' ? 'del turno' : 'de hoy';

    return <section data-dashboard-widget="cashier.workspace" className="space-y-5 sm:col-span-2 xl:col-span-3">
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Kpi label="Mi caja" value={open ? 'Abierta' : 'Cerrada'} detail={open ? `Esperado ${formatCurrency(data.cash_session?.expected_cash ?? 0, country)}` : 'Sin sesión abierta'} tone={open ? 'emerald' : 'slate'} />
            <Kpi label={`Ventas ${period}`} value={formatCurrency(data.sales_today.total, country)} detail={`${data.sales_today.count} operación(es)`} tone="orange" />
            <Kpi label={`Cobros ${period}`} value={formatCurrency(data.collections_today.total, country)} detail={`${data.collections_today.count} pago(s) capturado(s)`} tone="emerald" />
            <Kpi label="Pendientes" value={data.pending.active_pos_drafts_count} detail="Venta(s) pausada(s)" tone={data.pending.active_pos_drafts_count > 0 ? 'amber' : 'slate'} />
        </div>
        <div className="grid gap-5 lg:grid-cols-2 xl:grid-cols-3"><CashState data={data} country={country} /><PaymentMix rows={data.payment_mix} country={country} /><Attention rows={data.attention} /></div>
        <RecentActivity rows={data.recent_activity} country={country} />
    </section>;
}

type Tone = 'emerald' | 'orange' | 'amber' | 'slate';
function Kpi({ label, value, detail, tone }: { label: string; value: string | number; detail: string; tone: Tone }) { const color = { emerald: 'text-emerald-700', orange: 'text-orange-700', amber: 'text-amber-700', slate: 'text-slate-950' }[tone]; return <article className="rounded-xl border border-slate-200/80 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,0.05)]"><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p><p className={`mt-2 text-2xl font-bold tracking-tight ${color}`}>{value}</p><p className="mt-2 text-xs text-slate-500">{detail}</p></article>; }
function CashState({ data, country }: { data: CashierDashboardData; country: string }) { const open = data.cash_session?.status === 'open'; return <Panel title="Mi caja"><p className={`text-lg font-bold ${open ? 'text-emerald-700' : 'text-slate-800'}`}>{open ? 'Caja abierta' : 'No hay una caja abierta'}</p>{open && <p className="mt-2 text-sm text-slate-500">Esperado {formatCurrency(data.cash_session?.expected_cash ?? 0, country)}</p>}<p className="mt-4 text-sm text-slate-500">Ticket promedio {formatCurrency(data.sales_today.average_ticket, country)}</p></Panel>; }
function PaymentMix({ rows, country }: { rows: CashierDashboardData['payment_mix']; country: string }) { return <Panel title="Formas de cobro">{rows.length === 0 ? <Empty>No hay cobros capturados en este periodo.</Empty> : <div className="space-y-3">{rows.map((row) => <div key={row.method}><div className="flex items-center justify-between gap-3 text-sm"><span className="font-medium text-slate-700">{row.label}</span><span className="font-semibold text-slate-900">{formatCurrency(row.total, country)}</span></div><div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100"><div className="h-full rounded-full bg-orange-500" style={{ width: `${Math.min(100, row.percentage)}%` }} /></div></div>)}</div>}</Panel>; }
function Attention({ rows }: { rows: CashierDashboardData['attention'] }) { return <Panel title="Atención operativa">{rows.length === 0 ? <Empty>No hay alertas que requieran acción.</Empty> : <div className="space-y-2">{rows.map((row) => <Link key={row.key} href={row.href} className={`block rounded-lg border p-3 ${row.severity === 'warning' ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-slate-200 bg-slate-50 text-slate-700'}`}><p className="text-sm font-semibold">{row.title}</p><p className="mt-1 text-xs opacity-80">{row.detail}</p></Link>)}</div>}</Panel>; }
function RecentActivity({ rows, country }: { rows: CashierDashboardData['recent_activity']; country: string }) { return <Panel title="Actividad reciente">{rows.length === 0 ? <Empty>No hay operaciones recientes en tu sucursal.</Empty> : <div className="grid gap-2 lg:grid-cols-2">{rows.map((row) => { const body = <div className="rounded-lg border border-slate-200 bg-slate-50 p-3"><div className="flex items-start justify-between gap-3"><div className="min-w-0"><p className="truncate text-sm font-semibold text-slate-800">{row.title}</p><p className="truncate text-xs text-slate-500">{row.detail} · {formatTime(row.created_at)}</p></div><span className="shrink-0 text-sm font-semibold text-slate-900">{formatCurrency(row.amount, country)}</span></div></div>; return row.href ? <Link key={row.key} href={row.href} className="block hover:opacity-80">{body}</Link> : <div key={row.key}>{body}</div>; })}</div>}</Panel>; }
function Panel({ title, children }: { title: string; children: ReactNode }) { return <section className="rounded-xl border border-slate-200/80 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,0.05)]"><h3 className="text-sm font-semibold text-slate-950">{title}</h3><div className="mt-4">{children}</div></section>; }
function Empty({ children }: { children: ReactNode }) { return <p className="text-sm text-slate-500">{children}</p>; }
function formatTime(value: string | null): string { return value ? value.slice(11, 16) : '—'; }
