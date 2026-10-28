import { formatCurrency } from '@/utils/currency';
import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

type PurchaseSummary = { total: string; count: number; average: string; items_quantity: number; supplier_count: number; previous_total: string; change_percent: number | null };
export type PurchasesDashboardData = {
    purchases_month: PurchaseSummary;
    purchase_trend: Array<{ month: string; label: string; total: number; count: number }>;
    top_suppliers: Array<{ supplier_id: number; name: string; purchases_count: number; total: number; percentage: number }>;
    top_products: Array<{ product_id: number; name: string; quantity: number; total: number }>;
    replenishment: Array<{ product_id: number; name: string; code: string | null; stock: number; min_stock: number; status: 'out_of_stock' | 'low_stock'; last_purchase_cost: number | null; last_purchase_at: string | null }>;
    stock: { low_stock_count: number; out_of_stock_count: number } | null;
    cost_changes: Array<{ product_id: number; name: string; latest_cost: number; previous_cost: number; change_amount: number; change_percent: number | null; latest_purchase_at: string }>;
    recent_purchases: Array<{ id: number; business_number: number | string | null; supplier_name: string; total: number; payment_method: string; created_at: string | null; href: string }>;
    payment_mix: Array<{ method: string; label: string; total: number; count: number; percentage: number }>;
    attention: Array<{ key: string; severity: 'critical' | 'warning' | 'info'; title: string; detail: string }>;
};

export default function PurchasesDashboard({ data, country }: { data: PurchasesDashboardData; country: string }) {
    const summary = data.purchases_month;
    const kpis = [
        { label: 'Compras del mes', value: formatCurrency(summary.total, country), detail: `${summary.items_quantity} unidades compradas`, change: summary.change_percent },
        { label: 'Número de compras', value: String(summary.count), detail: 'Compras válidas del período' },
        { label: 'Compra promedio', value: formatCurrency(summary.average, country), detail: 'Promedio por compra' },
        { label: 'Proveedores usados', value: String(summary.supplier_count), detail: 'Con compras en el período' },
        data.stock && { label: 'Productos sin stock', value: String(data.stock.out_of_stock_count), detail: 'Requieren reposición', tone: data.stock.out_of_stock_count > 0 ? 'red' : 'slate' },
        data.stock && { label: 'Stock bajo', value: String(data.stock.low_stock_count), detail: 'Por debajo del mínimo', tone: data.stock.low_stock_count > 0 ? 'amber' : 'slate' },
    ].filter(Boolean) as Array<{ label: string; value: string; detail: string; change?: number | null; tone?: string }>;

    return <section data-dashboard-widget="purchases.overview" className="space-y-5 sm:col-span-2 xl:col-span-3">
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">{kpis.map((kpi) => <Kpi key={kpi.label} {...kpi} />)}</div>
        <div className="grid gap-5 xl:grid-cols-12"><PurchaseTrend trend={data.purchase_trend} country={country} /><Attention rows={data.attention} /></div>
        <div className="grid gap-5 lg:grid-cols-2 2xl:grid-cols-3">
            {data.stock && <Replenishment rows={data.replenishment} country={country} />}
            <Rankings title="Top proveedores" empty="No hay proveedores con compras en el período." rows={data.top_suppliers} country={country} type="supplier" />
            <PaymentMix rows={data.payment_mix} country={country} />
        </div>
        <div className="grid gap-5 lg:grid-cols-2 2xl:grid-cols-3">
            <CostChanges rows={data.cost_changes} country={country} />
            <Rankings title="Top productos comprados" empty="No hay compras registradas en el período." rows={data.top_products} country={country} type="product" />
            <RecentPurchases rows={data.recent_purchases} country={country} />
        </div>
    </section>;
}

function Kpi({ label, value, detail, change, tone = 'slate' }: { label: string; value: string; detail: string; change?: number | null; tone?: string }) {
    const valueTone = tone === 'red' ? 'text-red-700' : tone === 'amber' ? 'text-amber-700' : 'text-slate-950';
    const changeTone = change === undefined || change === null ? 'text-slate-500' : change >= 0 ? 'text-emerald-700' : 'text-red-700';
    return <article className="min-w-0 rounded-xl border border-slate-200/80 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,0.05)]"><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p><p className={`mt-2 truncate text-2xl font-bold tracking-tight ${valueTone}`}>{value}</p><div className="mt-2 flex min-h-5 items-center justify-between gap-2 text-xs"><span className="truncate text-slate-500">{detail}</span>{change !== undefined && <span className={`shrink-0 font-semibold ${changeTone}`}>{change === null ? '—' : `${change >= 0 ? '↑' : '↓'} ${Math.abs(change)}%`}</span>}</div></article>;
}

function PurchaseTrend({ trend, country }: { trend: PurchasesDashboardData['purchase_trend']; country: string }) {
    return <Panel className="xl:col-span-8" title="Tendencia de compras" subtitle="Últimos 6 meses"><div className="h-[280px]"><ResponsiveContainer width="100%" height="100%"><BarChart data={trend} margin={{ top: 8, right: 8, left: -12, bottom: 0 }}><CartesianGrid stroke="#e2e8f0" strokeDasharray="3 3" vertical={false} /><XAxis dataKey="label" tickLine={false} axisLine={false} tick={{ fill: '#64748b', fontSize: 11 }} /><YAxis tickLine={false} axisLine={false} tick={{ fill: '#64748b', fontSize: 11 }} width={52} tickFormatter={(value) => compactMoney(value, country)} /><Tooltip content={<TrendTooltip country={country} />} /><Bar dataKey="total" name="Compras" fill="#ea580c" radius={[4, 4, 0, 0]} maxBarSize={46} /></BarChart></ResponsiveContainer></div></Panel>;
}

function TrendTooltip({ active, payload, label, country }: any) { if (!active || !payload?.[0]) return null; const row = payload[0].payload; return <div className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs shadow-lg"><p className="font-semibold text-slate-800">{label}</p><p className="mt-1 text-slate-600">{formatCurrency(row.total, country)}</p><p className="text-slate-500">{row.count} compra(s)</p></div>; }

function Attention({ rows }: { rows: PurchasesDashboardData['attention'] }) {
    const tones = { critical: 'border-red-200 bg-red-50 text-red-800', warning: 'border-amber-200 bg-amber-50 text-amber-800', info: 'border-slate-200 bg-slate-50 text-slate-700' };
    return <Panel className="xl:col-span-4" title="Atención operativa">{rows.length === 0 ? <Empty>Todo en orden. No hay señales de abastecimiento inmediatas.</Empty> : <div className="space-y-2">{rows.map((row) => <div key={row.key} className={`rounded-lg border p-3 ${tones[row.severity]}`}><p className="text-sm font-semibold">{row.title}</p><p className="mt-1 text-xs opacity-80">{row.detail}</p></div>)}</div>}</Panel>;
}

function Replenishment({ rows, country }: { rows: PurchasesDashboardData['replenishment']; country: string }) {
    return <Panel title="Prioridad de reposición">{rows.length === 0 ? <Empty>No hay productos que requieran reposición.</Empty> : <div className="divide-y divide-slate-100">{rows.map((row) => <div key={row.product_id} className="flex items-center justify-between gap-3 py-3"><div className="min-w-0"><p className="truncate text-sm font-semibold text-slate-800">{row.name}</p><p className="text-xs text-slate-500">{row.code ?? 'Sin código'} · Stock {row.stock} / mín. {row.min_stock}</p></div><div className="shrink-0 text-right"><p className={`text-sm font-semibold ${row.status === 'out_of_stock' ? 'text-red-700' : 'text-amber-700'}`}>{row.status === 'out_of_stock' ? 'Sin stock' : 'Stock bajo'}</p><p className="text-xs text-slate-500">{row.last_purchase_cost === null ? 'Sin compra previa' : `Último ${formatCurrency(row.last_purchase_cost, country)}`}</p></div></div>)}</div>}</Panel>;
}

function Rankings({ title, empty, rows, country, type }: { title: string; empty: string; rows: PurchasesDashboardData['top_suppliers'] | PurchasesDashboardData['top_products']; country: string; type: 'supplier' | 'product' }) {
    return <Panel title={title}>{rows.length === 0 ? <Empty>{empty}</Empty> : <ol className="space-y-2">{rows.map((row, index) => <li key={type === 'supplier' ? (row as PurchasesDashboardData['top_suppliers'][number]).supplier_id : (row as PurchasesDashboardData['top_products'][number]).product_id} className="flex min-w-0 items-center gap-3 rounded-lg bg-slate-50 px-3 py-2"><span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white text-xs font-bold text-slate-500">{index + 1}</span><div className="min-w-0 flex-1"><p className="truncate text-sm font-semibold text-slate-800">{row.name}</p><p className="text-xs text-slate-500">{type === 'supplier' ? `${(row as PurchasesDashboardData['top_suppliers'][number]).purchases_count} compras · ${(row as PurchasesDashboardData['top_suppliers'][number]).percentage}%` : `${(row as PurchasesDashboardData['top_products'][number]).quantity} unidades`}</p></div><span className="shrink-0 text-sm font-semibold text-slate-900">{formatCurrency(row.total, country)}</span></li>)}</ol>}</Panel>;
}

function PaymentMix({ rows, country }: { rows: PurchasesDashboardData['payment_mix']; country: string }) { return <Panel title="Métodos de pago">{rows.length === 0 ? <Empty>No hay compras registradas en el período.</Empty> : <div className="space-y-3">{rows.map((row) => <div key={row.method}><div className="flex items-center justify-between gap-3 text-sm"><span className="font-medium text-slate-700">{row.label}</span><span className="text-right font-semibold text-slate-900">{formatCurrency(row.total, country)} · {row.percentage}%</span></div><div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100"><div className="h-full rounded-full bg-orange-500" style={{ width: `${Math.min(100, row.percentage)}%` }} /></div></div>)}</div>}</Panel>; }

function CostChanges({ rows, country }: { rows: PurchasesDashboardData['cost_changes']; country: string }) { return <Panel title="Cambios de costo">{rows.length === 0 ? <Empty>No hay cambios recientes de costo para comparar.</Empty> : <div className="divide-y divide-slate-100">{rows.map((row) => <div key={row.product_id} className="flex items-center justify-between gap-3 py-3"><div className="min-w-0"><p className="truncate text-sm font-semibold text-slate-800">{row.name}</p><p className="text-xs text-slate-500">{formatCurrency(row.previous_cost, country)} → {formatCurrency(row.latest_cost, country)}</p></div><span className={`shrink-0 text-sm font-semibold ${row.change_amount > 0 ? 'text-amber-700' : 'text-emerald-700'}`}>{row.change_percent === null ? formatCurrency(row.change_amount, country) : `${row.change_amount >= 0 ? '↑' : '↓'} ${Math.abs(row.change_percent)}%`}</span></div>)}</div>}</Panel>; }

function RecentPurchases({ rows, country }: { rows: PurchasesDashboardData['recent_purchases']; country: string }) { return <Panel title="Compras recientes">{rows.length === 0 ? <Empty>No hay compras registradas en el período.</Empty> : <div className="divide-y divide-slate-100">{rows.map((row) => <Link key={row.id} href={row.href} className="flex items-center justify-between gap-3 py-3 hover:text-orange-700"><div className="min-w-0"><p className="truncate text-sm font-semibold text-slate-800">Compra #{row.business_number ?? row.id} · {row.supplier_name}</p><p className="text-xs text-slate-500">{row.payment_method} · {row.created_at}</p></div><span className="shrink-0 text-sm font-semibold text-slate-900">{formatCurrency(row.total, country)}</span></Link>)}</div>}</Panel>; }

function Panel({ title, subtitle, className = '', children }: { title: string; subtitle?: string; className?: string; children: ReactNode }) { return <section className={`rounded-xl border border-slate-200/80 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,0.05)] ${className}`}><div><h3 className="text-sm font-semibold text-slate-950">{title}</h3>{subtitle && <p className="mt-1 text-xs text-slate-500">{subtitle}</p>}</div><div className="mt-4">{children}</div></section>; }
function Empty({ children }: { children: ReactNode }) { return <p className="text-sm text-slate-500">{children}</p>; }
function compactMoney(value: number, country: string) { return Math.abs(value) >= 1000 ? `${Math.round(value / 1000)}k` : formatCurrency(value, country); }
