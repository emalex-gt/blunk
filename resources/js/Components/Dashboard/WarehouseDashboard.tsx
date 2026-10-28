import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

export type WarehouseDashboardData = {
    inventory: { out_of_stock_count: number; low_stock_count: number };
    stock_summary: { active_skus: number; physical_units: number; negative_stock_count: number };
    reservations: { count: number; detail: string };
    movements_today: { count: number; entries_count: number; exits_count: number; entries_quantity: number; exits_quantity: number; net_quantity: number };
    movement_trend: Array<{ date: string; label: string; entries: number; exits: number; movements: number }>;
    top_moved_products: Array<{ product_id: number; name: string; code: string | null; movements_count: number; entries_quantity: number; exits_quantity: number; total_moved: number }>;
    critical_stock: Array<{ product_id: number; name: string; code: string | null; location: string | null; stock: number; min_stock: number; status: 'negative' | 'out_of_stock' | 'low_stock' }>;
    transfers: { month_count: number; inbound_count: number; outbound_count: number; inbound_units: number; outbound_units: number } | null;
    recent_transfers: Array<{ id: number; direction: 'inbound' | 'outbound'; other_branch: string | null; quantity: number; lines_count: number; created_at: string | null; href: string }>;
    recent_movements: Array<{ id: number; product_id: number; product_name: string | null; product_code: string | null; type: string; quantity: number; previous_stock: number | null; new_stock: number | null; note: string | null; created_at: string | null; time: string | null }>;
    attention: Array<{ key: string; severity: 'critical' | 'warning' | 'info'; title: string; detail: string; href: string | null }>;
};

export default function WarehouseDashboard({ data }: { data: WarehouseDashboardData }) {
    const movement = data.movements_today;
    const kpis = [
        { label: 'SKUs activos', value: data.stock_summary.active_skus, detail: `${data.stock_summary.physical_units} unidades físicas` },
        { label: 'Sin stock', value: data.inventory.out_of_stock_count, detail: data.stock_summary.negative_stock_count > 0 ? `${data.stock_summary.negative_stock_count} negativos` : 'Requieren atención', tone: 'red' },
        { label: 'Stock bajo', value: data.inventory.low_stock_count, detail: 'Por debajo del mínimo', tone: 'amber' },
        { label: 'Reservas activas', value: data.reservations.count, detail: `${data.reservations.detail} unidades reservadas` },
        { label: 'Entradas hoy', value: signed(movement.entries_quantity), detail: `${movement.entries_count} movimiento(s)`, tone: 'emerald' },
        { label: 'Salidas hoy', value: signed(movement.exits_quantity), detail: `${movement.exits_count} movimiento(s)`, tone: 'orange' },
    ];

    return <section data-dashboard-widget="warehouse.overview" className="space-y-5 sm:col-span-2 xl:col-span-3">
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">{kpis.map((kpi) => <Kpi key={kpi.label} {...kpi} />)}</div>
        <div className="grid gap-5 xl:grid-cols-12"><MovementTrend rows={data.movement_trend} /><Attention rows={data.attention} /></div>
        <div className="grid gap-5 lg:grid-cols-2 2xl:grid-cols-3"><CriticalStock rows={data.critical_stock} /><TopMoved rows={data.top_moved_products} />{data.transfers && <Transfers data={data.transfers} rows={data.recent_transfers} />}</div>
        <RecentMovements rows={data.recent_movements} />
    </section>;
}

function Kpi({ label, value, detail, tone = 'slate' }: { label: string; value: number | string; detail: string; tone?: 'red' | 'amber' | 'emerald' | 'orange' | 'slate' }) { const color = { red: 'text-red-700', amber: 'text-amber-700', emerald: 'text-emerald-700', orange: 'text-orange-700', slate: 'text-slate-950' }[tone]; return <article className="min-w-0 rounded-xl border border-slate-200/80 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,0.05)]"><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p><p className={`mt-2 truncate text-2xl font-bold tracking-tight ${color}`}>{value}</p><p className="mt-2 truncate text-xs text-slate-500">{detail}</p></article>; }

function MovementTrend({ rows }: { rows: WarehouseDashboardData['movement_trend'] }) { return <Panel className="xl:col-span-8" title="Actividad de inventario" subtitle="Últimos 14 días"><div className="h-[280px]"><ResponsiveContainer width="100%" height="100%"><BarChart data={rows} margin={{ top: 8, right: 8, left: -12, bottom: 0 }}><CartesianGrid stroke="#e2e8f0" strokeDasharray="3 3" vertical={false} /><XAxis dataKey="label" tickLine={false} axisLine={false} tick={{ fill: '#64748b', fontSize: 11 }} /><YAxis tickLine={false} axisLine={false} tick={{ fill: '#64748b', fontSize: 11 }} width={44} /><Tooltip content={<MovementTooltip />} /><Bar dataKey="entries" name="Entradas" fill="#059669" radius={[4, 4, 0, 0]} maxBarSize={28} /><Bar dataKey="exits" name="Salidas" fill="#ea580c" radius={[4, 4, 0, 0]} maxBarSize={28} /></BarChart></ResponsiveContainer></div></Panel>; }
function MovementTooltip({ active, payload, label }: { active?: boolean; payload?: Array<{ payload: WarehouseDashboardData['movement_trend'][number] }>; label?: string }) { if (!active || !payload?.[0]) return null; const row = payload[0].payload; return <div className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs shadow-lg"><p className="font-semibold text-slate-800">{label}</p><p className="mt-1 text-emerald-700">Entradas: {row.entries}</p><p className="text-orange-700">Salidas: {row.exits}</p><p className="text-slate-500">{row.movements} movimiento(s)</p></div>; }

function Attention({ rows }: { rows: WarehouseDashboardData['attention'] }) { const tones = { critical: 'border-red-200 bg-red-50 text-red-800', warning: 'border-amber-200 bg-amber-50 text-amber-800', info: 'border-slate-200 bg-slate-50 text-slate-700' }; return <Panel className="xl:col-span-4" title="Atención operativa">{rows.length === 0 ? <Empty>No hay alertas de inventario que requieran atención.</Empty> : <div className="space-y-2">{rows.map((row) => <div key={row.key} className={`rounded-lg border p-3 ${tones[row.severity]}`}><p className="text-sm font-semibold">{row.title}</p><p className="mt-1 text-xs opacity-80">{row.detail}</p>{row.href && <Link href={row.href} className="mt-2 inline-flex text-xs font-semibold underline">Ver detalle</Link>}</div>)}</div>}</Panel>; }

function CriticalStock({ rows }: { rows: WarehouseDashboardData['critical_stock'] }) { const labels = { negative: 'Stock negativo', out_of_stock: 'Sin stock', low_stock: 'Stock bajo' }; return <Panel title="Stock crítico">{rows.length === 0 ? <Empty>No hay productos con stock crítico.</Empty> : <div className="divide-y divide-slate-100">{rows.map((row) => <div key={row.product_id} className="flex items-center justify-between gap-3 py-3"><div className="min-w-0"><p className="truncate text-sm font-semibold text-slate-800">{row.name}</p><p className="text-xs text-slate-500">{row.code ?? 'Sin código'}{row.location ? ` · ${row.location}` : ''} · {row.stock} / mín. {row.min_stock}</p></div><span className={`shrink-0 text-xs font-semibold ${row.status === 'negative' || row.status === 'out_of_stock' ? 'text-red-700' : 'text-amber-700'}`}>{labels[row.status]}</span></div>)}</div>}</Panel>; }

function TopMoved({ rows }: { rows: WarehouseDashboardData['top_moved_products'] }) { return <Panel title="Productos más movidos">{rows.length === 0 ? <Empty>No hay movimientos de inventario en los últimos 30 días.</Empty> : <div className="divide-y divide-slate-100">{rows.map((row) => <div key={row.product_id} className="flex items-center justify-between gap-3 py-3"><div className="min-w-0"><p className="truncate text-sm font-semibold text-slate-800">{row.name}</p><p className="text-xs text-slate-500">{row.code ?? 'Sin código'} · Entradas {row.entries_quantity} · Salidas {row.exits_quantity}</p></div><span className="shrink-0 text-sm font-semibold text-slate-900">{row.total_moved}</span></div>)}</div>}</Panel>; }

function Transfers({ data, rows }: { data: NonNullable<WarehouseDashboardData['transfers']>; rows: WarehouseDashboardData['recent_transfers'] }) { return <Panel title="Traslados del mes">{data.month_count === 0 ? <Empty>No hay traslados registrados en el período.</Empty> : <><div className="grid grid-cols-2 gap-3"><Stat label="Entradas" value={data.inbound_count} detail={`${data.inbound_units} unidades`} /><Stat label="Salidas" value={data.outbound_count} detail={`${data.outbound_units} unidades`} /></div><div className="mt-4 divide-y divide-slate-100">{rows.map((row) => <Link key={row.id} href={row.href} className="block py-2 text-sm hover:text-orange-700"><span className="font-semibold">{row.direction === 'inbound' ? 'Entrada' : 'Salida'}</span><span className="text-slate-500"> · {row.other_branch ?? 'Sucursal'} · {row.quantity} unidades</span></Link>)}</div></>}</Panel>; }

function RecentMovements({ rows }: { rows: WarehouseDashboardData['recent_movements'] }) { return <Panel title="Actividad reciente">{rows.length === 0 ? <Empty>No hay movimientos recientes en esta sucursal.</Empty> : <div className="grid gap-2 lg:grid-cols-2 xl:grid-cols-3">{rows.map((row) => <div key={row.id} className="rounded-lg bg-slate-50 p-3"><div className="flex items-start justify-between gap-2"><div className="min-w-0"><p className="truncate text-sm font-semibold text-slate-800">{row.product_name}</p><p className="text-xs text-slate-500">{row.product_code ?? 'Sin código'} · {row.previous_stock ?? '—'} → {row.new_stock ?? '—'}</p></div><span className={`shrink-0 font-semibold ${row.quantity > 0 ? 'text-emerald-700' : row.quantity < 0 ? 'text-orange-700' : 'text-slate-600'}`}>{signed(row.quantity)}</span></div><p className="mt-2 truncate text-xs text-slate-500">{row.time}{row.note ? ` · ${row.note}` : ''}</p></div>)}</div>}</Panel>; }

function Panel({ title, subtitle, className = '', children }: { title: string; subtitle?: string; className?: string; children: ReactNode }) { return <section className={`rounded-xl border border-slate-200/80 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,0.05)] ${className}`}><div><h3 className="text-sm font-semibold text-slate-950">{title}</h3>{subtitle && <p className="mt-1 text-xs text-slate-500">{subtitle}</p>}</div><div className="mt-4">{children}</div></section>; }
function Stat({ label, value, detail }: { label: string; value: number; detail: string }) { return <div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-500">{label}</p><p className="mt-1 text-xl font-bold text-slate-950">{value}</p><p className="text-xs text-slate-500">{detail}</p></div>; }
function Empty({ children }: { children: ReactNode }) { return <p className="text-sm text-slate-500">{children}</p>; }
function signed(value: number): string { return `${value > 0 ? '+' : ''}${value}`; }
