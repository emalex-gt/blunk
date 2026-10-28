import { formatCurrency } from '@/utils/currency';
import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Bar, CartesianGrid, ComposedChart, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

type Summary = { total: string; count: number; average?: string; average_ticket?: string; previous_total?: string; change_percent?: number | null };
type AgingBucket = { amount: string; count: number };
export type FinanceDashboardData = {
    sales_month: Summary | null;
    profit_month: { sales_total: string; cost_total: string; profit_total: string; margin_percent: number | null; previous_profit_total: string; change_percent: number | null } | null;
    purchases_month: Summary | null;
    inventory_value: { amount: string; sku_count: number } | null;
    receivables: { total: string; invoice_count: number; customer_count: number; overdue_total: string; overdue_count: number; due_next_7_days: string } | null;
    aging: Record<'current' | 'overdue_1_30' | 'overdue_31_60' | 'overdue_61_90' | 'overdue_90_plus' | 'no_due_date', AgingBucket> | null;
    top_debtors: Array<{ customer_id: number; name: string; total_balance: string; overdue_balance: string; oldest_due_date: string | null; href: string | null }>;
    overdue_invoices: Array<{ sale_id: number; business_number: number | null; customer_id: number | null; customer_name: string; due_date: string; days_overdue: number; balance: string; href: string | null }>;
    collections_month: Summary | null;
    collections_mix: Array<{ method: string; label: string; total: number; count: number; percentage: number }>;
    recent_collections: Array<{ id: number; payment_number: number | null; customer_name: string; amount: number; payment_method: string; created_at: string | null }>;
    financial_trend: Array<{ month: string; label: string; sales: number | null; profit: number | null; purchases: number | null }>;
};

export default function FinanceDashboard({ data, country }: { data: FinanceDashboardData; country: string }) {
    const kpis = [
        data.sales_month && { label: 'Ventas del mes', value: formatCurrency(data.sales_month.total, country), detail: `${data.sales_month.count} ventas · Ticket ${formatCurrency(data.sales_month.average_ticket ?? 0, country)}`, change: data.sales_month.change_percent },
        data.profit_month && { label: 'Utilidad bruta estimada', value: formatCurrency(data.profit_month.profit_total, country), detail: 'Costo de ventas registrado', change: data.profit_month.change_percent, tone: 'emerald' },
        data.profit_month && { label: 'Margen bruto', value: data.profit_month.margin_percent === null ? '—' : `${data.profit_month.margin_percent}%`, detail: 'Sobre ventas del período', tone: 'emerald' },
        data.receivables && { label: 'Cartera pendiente', value: formatCurrency(data.receivables.total, country), detail: `${data.receivables.invoice_count} facturas · ${data.receivables.customer_count} clientes`, tone: 'amber' },
        data.receivables && { label: 'Cartera vencida', value: formatCurrency(data.receivables.overdue_total, country), detail: `${data.receivables.overdue_count} facturas vencidas`, tone: Number(data.receivables.overdue_total) > 0 ? 'red' : 'slate' },
        data.collections_month && { label: 'Abonos del mes', value: formatCurrency(data.collections_month.total, country), detail: `${data.collections_month.count} abonos · Promedio ${formatCurrency(data.collections_month.average ?? 0, country)}`, change: data.collections_month.change_percent, tone: 'emerald' },
        data.inventory_value && { label: 'Inventario a costo', value: formatCurrency(data.inventory_value.amount, country), detail: `${data.inventory_value.sku_count} SKU activos` },
        data.purchases_month && { label: 'Compras del mes', value: formatCurrency(data.purchases_month.total, country), detail: `${data.purchases_month.count} compras`, change: data.purchases_month.change_percent },
    ].filter(Boolean).slice(0, 6) as Array<{ label: string; value: string; detail: string; change?: number | null; tone?: string }>;

    return (
        <section data-dashboard-widget="finance.overview" className="space-y-5 sm:col-span-2 xl:col-span-3">
            {kpis.length > 0 && <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">{kpis.map((kpi) => <Kpi key={kpi.label} {...kpi} />)}</div>}

            <div className="grid gap-5 xl:grid-cols-12">
                <FinancialTrend trend={data.financial_trend} country={country} />
                {data.receivables && data.aging && <AgingPanel receivables={data.receivables} aging={data.aging} country={country} />}
            </div>

            <div className="grid gap-5 lg:grid-cols-2 2xl:grid-cols-3">
                {data.receivables && <DebtorsPanel rows={data.top_debtors} country={country} />}
                {data.collections_month && <CollectionMix rows={data.collections_mix} country={country} />}
                <FinancialSummary data={data} country={country} />
            </div>

            <div className="grid gap-5 xl:grid-cols-2">
                {data.receivables && <OverdueInvoices rows={data.overdue_invoices} country={country} />}
                {data.collections_month && <RecentCollections rows={data.recent_collections} country={country} />}
            </div>
        </section>
    );
}

function Kpi({ label, value, detail, change, tone = 'slate' }: { label: string; value: string; detail: string; change?: number | null; tone?: string }) {
    const valueTone = tone === 'emerald' ? 'text-emerald-700' : tone === 'amber' ? 'text-amber-700' : tone === 'red' ? 'text-red-700' : 'text-slate-950';
    const changeTone = change === undefined || change === null ? 'text-slate-500' : change >= 0 ? 'text-emerald-700' : 'text-red-700';
    return <article className="min-w-0 rounded-xl border border-slate-200/80 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,0.05)]"><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p><p className={`mt-2 truncate text-2xl font-bold tracking-tight ${valueTone}`}>{value}</p><div className="mt-2 flex min-h-5 items-center justify-between gap-2 text-xs"><span className="truncate text-slate-500">{detail}</span>{change !== undefined && <span className={`shrink-0 font-semibold ${changeTone}`}>{change === null ? '—' : `${change >= 0 ? '↑' : '↓'} ${Math.abs(change)}%`}</span>}</div></article>;
}

function FinancialTrend({ trend, country }: { trend: FinanceDashboardData['financial_trend']; country: string }) {
    const hasPurchases = trend.some((point) => point.purchases !== null);
    const hasProfit = trend.some((point) => point.profit !== null);
    return <Panel className="xl:col-span-8" title="Tendencia financiera" subtitle="Últimos 6 meses">
        <div className="h-[280px]"><ResponsiveContainer width="100%" height="100%"><ComposedChart data={trend} margin={{ top: 8, right: 8, left: -12, bottom: 0 }}><CartesianGrid stroke="#e2e8f0" strokeDasharray="3 3" vertical={false} /><XAxis dataKey="label" tickLine={false} axisLine={false} tick={{ fill: '#64748b', fontSize: 11 }} /><YAxis tickLine={false} axisLine={false} tick={{ fill: '#64748b', fontSize: 11 }} width={52} tickFormatter={(value) => compactMoney(value, country)} /><Tooltip content={<TrendTooltip country={country} />} /><Bar dataKey="sales" name="Ventas" fill="#ea580c" radius={[4, 4, 0, 0]} maxBarSize={34} />{hasPurchases && <Bar dataKey="purchases" name="Compras" fill="#64748b" radius={[4, 4, 0, 0]} maxBarSize={28} />}{hasProfit && <Line type="monotone" dataKey="profit" name="Utilidad" stroke="#059669" strokeWidth={2.5} dot={{ r: 3 }} />}</ComposedChart></ResponsiveContainer></div>
    </Panel>;
}

function TrendTooltip({ active, payload, label, country }: { active?: boolean; payload?: Array<{ name: string; value: number }>; label?: string; country: string }) {
    if (!active || !payload?.length) return null;
    return <div className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs shadow-lg"><p className="font-semibold text-slate-800">{label}</p>{payload.map((item) => <p key={item.name} className="mt-1 text-slate-600">{item.name}: {formatCurrency(item.value, country)}</p>)}</div>;
}

function AgingPanel({ receivables, aging, country }: { receivables: NonNullable<FinanceDashboardData['receivables']>; aging: NonNullable<FinanceDashboardData['aging']>; country: string }) {
    const rows: Array<{ key: keyof typeof aging; label: string; tone: string }> = [
        { key: 'current', label: 'Vigente', tone: 'bg-emerald-500' }, { key: 'overdue_1_30', label: '1–30 días', tone: 'bg-amber-300' }, { key: 'overdue_31_60', label: '31–60 días', tone: 'bg-amber-500' }, { key: 'overdue_61_90', label: '61–90 días', tone: 'bg-orange-500' }, { key: 'overdue_90_plus', label: '+90 días', tone: 'bg-red-600' }, { key: 'no_due_date', label: 'Sin fecha', tone: 'bg-slate-500' },
    ];
    const total = Number(receivables.total);
    const overduePercent = total > 0 ? Math.round((Number(receivables.overdue_total) / total) * 10000) / 100 : 0;
    return <Panel className="xl:col-span-4" title="Aging de cartera" subtitle={`Total ${formatCurrency(receivables.total, country)} · ${overduePercent}% vencida`}>
        <div className="space-y-3">{rows.map((row) => { const bucket = aging[row.key]; const percentage = total > 0 ? Math.min(100, (Number(bucket.amount) / total) * 100) : 0; return <div key={row.key}><div className="flex justify-between gap-3 text-xs"><span className="font-medium text-slate-700">{row.label}</span><span className="text-right font-semibold text-slate-900">{formatCurrency(bucket.amount, country)} · {bucket.count}</span></div><div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100"><div className={`h-full rounded-full ${row.tone}`} style={{ width: `${percentage}%` }} /></div></div>; })}</div>
    </Panel>;
}

function DebtorsPanel({ rows, country }: { rows: FinanceDashboardData['top_debtors']; country: string }) {
    return <Panel title="Top deudores">{rows.length === 0 ? <Empty> No hay cuentas por cobrar pendientes.</Empty> : <ol className="space-y-2">{rows.map((row, index) => <li key={row.customer_id} className="flex min-w-0 items-center gap-3 rounded-lg bg-slate-50 px-3 py-2"><span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white text-xs font-bold text-slate-500">{index + 1}</span><div className="min-w-0 flex-1">{row.href ? <Link href={row.href} className="block truncate text-sm font-semibold text-orange-700 hover:underline">{row.name}</Link> : <p className="truncate text-sm font-semibold text-slate-800">{row.name}</p>}<p className="text-xs text-slate-500">Vencido {formatCurrency(row.overdue_balance, country)}</p></div><span className="shrink-0 text-sm font-semibold text-slate-900">{formatCurrency(row.total_balance, country)}</span></li>)}</ol>}</Panel>;
}

function CollectionMix({ rows, country }: { rows: FinanceDashboardData['collections_mix']; country: string }) {
    return <Panel title="Formas de cobro">{rows.length === 0 ? <Empty>No hay abonos registrados en el período.</Empty> : <div className="space-y-3">{rows.map((row) => <div key={row.method}><div className="flex items-center justify-between gap-3 text-sm"><span className="font-medium text-slate-700">{row.label}</span><span className="text-right font-semibold text-slate-900">{formatCurrency(row.total, country)} · {row.percentage}%</span></div><div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100"><div className="h-full rounded-full bg-orange-500" style={{ width: `${Math.min(100, row.percentage)}%` }} /></div></div>)}</div>}</Panel>;
}

function FinancialSummary({ data, country }: { data: FinanceDashboardData; country: string }) {
    const rows = [
        data.inventory_value && ['Inventario a costo', formatCurrency(data.inventory_value.amount, country)],
        data.purchases_month && ['Compras mes', formatCurrency(data.purchases_month.total, country)],
        data.profit_month && ['Costo de ventas', formatCurrency(data.profit_month.cost_total, country)],
        data.sales_month && ['Ticket promedio', formatCurrency(data.sales_month.average_ticket ?? 0, country)],
        data.receivables && ['Facturas por cobrar', String(data.receivables.invoice_count)],
        data.receivables && ['Clientes deudores', String(data.receivables.customer_count)],
    ].filter(Boolean) as Array<[string, string]>;
    return <Panel title="Resumen financiero">{rows.length === 0 ? <Empty>No hay métricas financieras autorizadas.</Empty> : <dl className="divide-y divide-slate-100">{rows.map(([label, value]) => <div key={label} className="flex items-center justify-between gap-3 py-2.5 text-sm"><dt className="text-slate-500">{label}</dt><dd className="text-right font-semibold text-slate-900">{value}</dd></div>)}</dl>}</Panel>;
}

function OverdueInvoices({ rows, country }: { rows: FinanceDashboardData['overdue_invoices']; country: string }) {
    return <Panel title="Facturas vencidas">{rows.length === 0 ? <Empty>No hay facturas vencidas.</Empty> : <div className="divide-y divide-slate-100">{rows.map((row) => { const content = <><div className="min-w-0"><p className="truncate text-sm font-semibold text-slate-800">Venta #{row.business_number ?? row.sale_id} · {row.customer_name}</p><p className="text-xs text-slate-500">Vence {row.due_date} · {row.days_overdue} días vencida</p></div><span className="shrink-0 text-sm font-semibold text-red-700">{formatCurrency(row.balance, country)}</span></>; return row.href ? <Link key={row.sale_id} href={row.href} className="flex items-center justify-between gap-3 py-3 hover:text-orange-700">{content}</Link> : <div key={row.sale_id} className="flex items-center justify-between gap-3 py-3">{content}</div>; })}</div>}</Panel>;
}

function RecentCollections({ rows, country }: { rows: FinanceDashboardData['recent_collections']; country: string }) {
    return <Panel title="Abonos recientes">{rows.length === 0 ? <Empty>No hay abonos registrados en el período.</Empty> : <div className="divide-y divide-slate-100">{rows.map((row) => <div key={row.id} className="flex items-center justify-between gap-3 py-3"><div className="min-w-0"><p className="truncate text-sm font-semibold text-slate-800">AB-{row.payment_number ?? row.id} · {row.customer_name}</p><p className="text-xs text-slate-500">{row.payment_method} · {row.created_at}</p></div><span className="shrink-0 text-sm font-semibold text-emerald-700">{formatCurrency(row.amount, country)}</span></div>)}</div>}</Panel>;
}

function Panel({ title, subtitle, className = '', children }: { title: string; subtitle?: string; className?: string; children: ReactNode }) {
    return <section className={`rounded-xl border border-slate-200/80 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,0.05)] ${className}`}><div><h3 className="text-sm font-semibold text-slate-950">{title}</h3>{subtitle && <p className="mt-1 text-xs text-slate-500">{subtitle}</p>}</div><div className="mt-4">{children}</div></section>;
}

function Empty({ children }: { children: ReactNode }) { return <p className="text-sm text-slate-500">{children}</p>; }
function compactMoney(value: number, country: string) { return Math.abs(value) >= 1000 ? `${Math.round(value / 1000)}k` : formatCurrency(value, country); }
