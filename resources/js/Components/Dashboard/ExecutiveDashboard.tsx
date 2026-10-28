import { formatCurrency } from '@/utils/currency';
import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

type ExecutiveDashboardProps = {
    data: any;
    country: string;
};

export default function ExecutiveDashboard({ data, country }: ExecutiveDashboardProps) {
    const kpis = [
        data.sales_today && { label: 'Ventas hoy', value: formatCurrency(data.sales_today.total, country), detail: `${data.sales_today.count} ventas · Ticket ${formatCurrency(data.sales_today.average_ticket, country)}`, change: data.sales_today.change_percent },
        data.sales_month && { label: 'Ventas del mes', value: formatCurrency(data.sales_month.total, country), detail: `${data.sales_month.count} ventas · Ticket ${formatCurrency(data.sales_month.average_ticket, country)}`, change: data.sales_month.change_percent },
        data.margin_today && { label: 'Margen hoy', value: formatCurrency(data.margin_today.amount, country), detail: data.margin_today.percent === null ? 'Sin ventas hoy' : `${data.margin_today.percent}% sobre ventas`, tone: 'emerald' },
        data.sales_today && { label: 'Ticket promedio', value: formatCurrency(data.sales_today.average_ticket, country), detail: `${data.sales_today.count} ventas hoy`, tone: 'slate' },
        data.receivable && { label: 'Por cobrar', value: formatCurrency(data.receivable.amount, country), detail: `${data.receivable.count} cuenta(s)`, tone: 'amber' },
        data.inventory_value
            ? { label: 'Valor inventario', value: formatCurrency(data.inventory_value.amount, country), detail: `${data.inventory_value.sku_count} SKU activos`, tone: 'slate' }
            : data.purchases_month && { label: 'Compras del mes', value: formatCurrency(data.purchases_month.total, country), detail: `${data.purchases_month.count} compras · Promedio ${formatCurrency(data.purchases_month.average, country)}`, tone: 'slate' },
    ].filter(Boolean) as Array<{ label: string; value: string; detail: string; change?: number | null; tone?: string }>;

    const operation = [
        data.cash && { label: 'Caja', value: data.cash.status === 'open' ? 'Abierta' : 'Cerrada', detail: data.cash.status === 'open' ? `Esperado ${formatCurrency(data.cash.expected_cash ?? 0, country)}` : 'Sin sesión abierta', tone: data.cash.status === 'open' ? 'emerald' : 'slate' },
        data.stock && { label: 'Sin existencias', value: String(data.stock.out_of_stock_count), detail: 'Productos agotados', tone: data.stock.out_of_stock_count > 0 ? 'red' : 'slate' },
        data.stock && { label: 'Stock bajo', value: String(data.stock.low_stock_count), detail: 'Productos por reabastecer', tone: data.stock.low_stock_count > 0 ? 'amber' : 'slate' },
        data.purchases_month && { label: 'Compras', value: formatCurrency(data.purchases_month.total, country), detail: 'Acumulado del mes', tone: 'slate' },
    ].filter(Boolean) as Array<{ label: string; value: string; detail: string; tone?: string }>;

    return (
        <section data-dashboard-widget="executive.overview" className="space-y-5 sm:col-span-2 xl:col-span-3">
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
                {kpis.map((kpi) => <ExecutiveKpi key={kpi.label} {...kpi} />)}
            </div>

            <div className="grid gap-5 xl:grid-cols-12">
                <SalesTrendChart trend={data.sales_trend ?? []} country={country} />
                <AttentionPanel attention={data.attention ?? []} />
            </div>

            <div className="grid gap-5 lg:grid-cols-2 2xl:grid-cols-3">
                {data.payment_mix?.length > 0 && <PaymentMix rows={data.payment_mix} country={country} />}
                {data.top_products?.length > 0 && <RankingList title="Top productos" rows={data.top_products} country={country} kind="products" />}
                {data.top_customers?.length > 0 && <RankingList title="Top clientes" rows={data.top_customers} country={country} kind="customers" />}
            </div>

            <div className="grid gap-5 xl:grid-cols-2">
                <RecentActivity rows={data.recent_activity ?? []} country={country} />
                <OperationPanel operation={operation} routes={data.routes} />
            </div>
        </section>
    );
}

function ExecutiveKpi({ label, value, detail, change, tone = 'slate' }: { label: string; value: string; detail: string; change?: number | null; tone?: string }) {
    const changeTone = change === undefined || change === null ? 'text-slate-500' : change >= 0 ? 'text-emerald-700' : 'text-red-700';
    const valueTone = tone === 'emerald' ? 'text-emerald-700' : tone === 'amber' ? 'text-amber-700' : tone === 'red' ? 'text-red-700' : 'text-slate-950';

    return (
        <article className="min-w-0 rounded-xl border border-slate-200/80 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,0.05)]">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p>
            <p className={`mt-2 truncate text-2xl font-bold tracking-tight ${valueTone}`}>{value}</p>
            <div className="mt-2 flex min-h-5 items-center justify-between gap-2 text-xs">
                <span className="truncate text-slate-500">{detail}</span>
                {change !== undefined && <span className={`shrink-0 font-semibold ${changeTone}`}>{change === null ? '—' : `${change >= 0 ? '↑' : '↓'} ${Math.abs(change)}%`}</span>}
            </div>
        </article>
    );
}

function SalesTrendChart({ trend, country }: { trend: Array<{ date: string; label: string; total: number; count: number }>; country: string }) {
    return (
        <section className="rounded-xl border border-slate-200/80 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,0.05)] xl:col-span-8">
            <div className="flex items-baseline justify-between gap-3">
                <div>
                    <h3 className="text-sm font-semibold text-slate-950">Tendencia de ventas</h3>
                    <p className="mt-1 text-xs text-slate-500">Últimos 14 días</p>
                </div>
            </div>
            <div className="mt-4 h-[270px]">
                <ResponsiveContainer width="100%" height="100%">
                    <AreaChart data={trend} margin={{ top: 8, right: 8, left: -12, bottom: 0 }}>
                        <defs>
                            <linearGradient id="executive-sales-area" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="5%" stopColor="#ea580c" stopOpacity={0.28} />
                                <stop offset="95%" stopColor="#ea580c" stopOpacity={0.02} />
                            </linearGradient>
                        </defs>
                        <CartesianGrid stroke="#e2e8f0" strokeDasharray="3 3" vertical={false} />
                        <XAxis dataKey="label" tickLine={false} axisLine={false} tick={{ fill: '#64748b', fontSize: 11 }} minTickGap={18} />
                        <YAxis tickLine={false} axisLine={false} tick={{ fill: '#64748b', fontSize: 11 }} width={52} tickFormatter={(value) => formatCompactMoney(value, country)} />
                        <Tooltip content={<SalesTrendTooltip country={country} />} />
                        <Area type="monotone" dataKey="total" stroke="#ea580c" strokeWidth={2.5} fill="url(#executive-sales-area)" />
                    </AreaChart>
                </ResponsiveContainer>
            </div>
        </section>
    );
}

function SalesTrendTooltip({ active, payload, label, country }: any) {
    if (!active || !payload?.[0]) return null;
    const point = payload[0].payload;

    return (
        <div className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs shadow-lg">
            <p className="font-semibold text-slate-800">{label}</p>
            <p className="mt-1 text-slate-600">{formatCurrency(point.total, country)}</p>
            <p className="text-slate-500">{point.count} venta(s)</p>
        </div>
    );
}

function AttentionPanel({ attention }: { attention: Array<{ key: string; severity: 'critical' | 'warning' | 'info'; title: string; detail: string; href?: string | null }> }) {
    const tones = {
        critical: 'border-red-200 bg-red-50 text-red-800',
        warning: 'border-amber-200 bg-amber-50 text-amber-800',
        info: 'border-slate-200 bg-slate-50 text-slate-700',
    };

    return (
        <section className="rounded-xl border border-slate-200/80 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,0.05)] xl:col-span-4">
            <h3 className="text-sm font-semibold text-slate-950">Requiere tu atención</h3>
            {attention.length === 0 ? (
                <div className="mt-4 rounded-lg border border-emerald-100 bg-emerald-50 p-4">
                    <p className="text-sm font-semibold text-emerald-800">Todo en orden</p>
                    <p className="mt-1 text-xs text-emerald-700">No hay alertas que requieran atención inmediata.</p>
                </div>
            ) : (
                <div className="mt-3 space-y-2">
                    {attention.map((item) => (
                        <div key={item.key} className={`rounded-lg border p-3 ${tones[item.severity]}`}>
                            <p className="text-sm font-semibold">{item.title}</p>
                            <p className="mt-1 text-xs opacity-80">{item.detail}</p>
                            {item.href && <Link href={item.href} className="mt-2 inline-block text-xs font-semibold underline underline-offset-2">Ver detalle</Link>}
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}

function PaymentMix({ rows, country }: { rows: Array<{ method: string; label: string; total: number; count: number; percentage: number }>; country: string }) {
    return (
        <Panel title="Formas de pago">
            <div className="space-y-3">
                {rows.map((row) => (
                    <div key={row.method}>
                        <div className="flex items-center justify-between gap-3 text-sm">
                            <span className="font-medium text-slate-700">{row.label}</span>
                            <span className="text-right font-semibold text-slate-900">{formatCurrency(row.total, country)} · {row.percentage}%</span>
                        </div>
                        <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100"><div className="h-full rounded-full bg-orange-500" style={{ width: `${Math.min(100, row.percentage)}%` }} /></div>
                    </div>
                ))}
            </div>
        </Panel>
    );
}

function RankingList({ title, rows, country, kind }: { title: string; rows: any[]; country: string; kind: 'products' | 'customers' }) {
    return (
        <Panel title={title}>
            <ol className="space-y-2">
                {rows.map((row, index) => (
                    <li key={row.product_id ?? row.customer_id} className="flex min-w-0 items-center gap-3 rounded-lg bg-slate-50 px-3 py-2">
                        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white text-xs font-bold text-slate-500">{index + 1}</span>
                        <div className="min-w-0 flex-1"><p className="truncate text-sm font-semibold text-slate-800">{row.name}</p><p className="text-xs text-slate-500">{kind === 'products' ? `${row.quantity} unidades` : `${row.sales_count} ventas`}</p></div>
                        <span className="shrink-0 text-sm font-semibold text-slate-900">{formatCurrency(row.total, country)}</span>
                    </li>
                ))}
            </ol>
        </Panel>
    );
}

function RecentActivity({ rows, country }: { rows: Array<{ key: string; type: string; title: string; detail: string; amount: number; at: string; href?: string | null }>; country: string }) {
    return (
        <Panel title="Actividad reciente">
            {rows.length === 0 ? <p className="text-sm text-slate-500">No hay actividad reciente disponible.</p> : <div className="divide-y divide-slate-100">{rows.map((row) => {
                const content = <><div className="min-w-0"><p className="truncate text-sm font-semibold text-slate-800">{row.title}</p><p className="truncate text-xs text-slate-500">{row.detail} · {row.at}</p></div><span className="shrink-0 text-sm font-semibold text-slate-900">{formatCurrency(row.amount, country)}</span></>;
                return row.href ? <Link key={row.key} href={row.href} className="flex items-center justify-between gap-3 py-3 hover:text-orange-700">{content}</Link> : <div key={row.key} className="flex items-center justify-between gap-3 py-3">{content}</div>;
            })}</div>}
        </Panel>
    );
}

function OperationPanel({ operation, routes }: { operation: Array<{ label: string; value: string; detail: string; tone?: string }>; routes?: Record<string, number> | null }) {
    return (
        <Panel title="Operación">
            <div className="grid gap-2 sm:grid-cols-2">
                {operation.map((item) => <CompactOperation key={item.label} {...item} />)}
                {routes && <>
                    <CompactOperation label="Jornadas abiertas" value={String(routes.open_work_days ?? 0)} detail="Operación de rutas" />
                    <CompactOperation label="Visitas pendientes" value={String(routes.pending_visits ?? 0)} detail="Por realizar" />
                    <CompactOperation label="Preventas" value={String(routes.operational_pre_sales ?? 0)} detail="Operativas" />
                    <CompactOperation label="Entregas pendientes" value={String(routes.pending_delivery_stops ?? 0)} detail="Por completar" />
                </>}
            </div>
        </Panel>
    );
}

function CompactOperation({ label, value, detail, tone = 'slate' }: { label: string; value: string; detail: string; tone?: string }) {
    const valueTone = tone === 'emerald' ? 'text-emerald-700' : tone === 'amber' ? 'text-amber-700' : tone === 'red' ? 'text-red-700' : 'text-slate-900';
    return <div className="rounded-lg bg-slate-50 p-3"><p className="text-xs font-semibold text-slate-500">{label}</p><p className={`mt-1 text-lg font-bold ${valueTone}`}>{value}</p><p className="mt-1 text-xs text-slate-500">{detail}</p></div>;
}

function Panel({ title, children }: { title: string; children: ReactNode }) {
    return <section className="rounded-xl border border-slate-200/80 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,0.05)]"><h3 className="text-sm font-semibold text-slate-950">{title}</h3><div className="mt-4">{children}</div></section>;
}

function formatCompactMoney(value: number, country: string): string {
    if (Math.abs(value) >= 1000) return `${Math.round(value / 1000)}k`;

    return formatCurrency(value, country);
}
