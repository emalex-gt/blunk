import { formatCurrency } from '@/utils/currency';
import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

type MetricPeriod = 'current' | 'today' | 'backlog';

type CountMetric = {
    label: string;
    period: MetricPeriod;
    count: number;
};

type MoneyMetric = CountMetric & {
    amount: string | number;
};

export type RouteSupervisorDashboardData = {
    open_work_days: CountMetric;
    pending_visits: CountMetric;
    operational_pre_sales: CountMetric;
    pending_delivery_stops: CountMetric;
    effective_settlements: MoneyMetric;
    seller_work_days: {
        items: Array<{
            id: number;
            status: string;
            seller_name: string | null;
            zone_name: string | null;
            branch_name: string | null;
            total_visits: number;
            completed_visits: number;
            pending_visits: number;
            pre_sales_count: number;
            pre_sales_total: string | number;
        }>;
        total_open_work_days: number;
        has_more: boolean;
        href: string;
    };
    pre_sale_flow: { submitted: number; processing: number; picked: number; converted_today: number };
    delivery_overview: {
        pending_backlog: number;
        delivered_today: number;
        not_delivered_today: number;
        unpaid_delivered: { count: number; amount: string | number };
        href: string;
    };
    settlement_overview: {
        confirmed_today: { count: number; amount: string | number };
        draft_count: number | null;
        open_variances: { count: number; shortages_amount: string | number; overages_amount: string | number } | null;
        href: string | null;
        variances_href: string | null;
    };
    operational_alerts: Array<{ key: string; severity: 'critical' | 'warning' | 'info'; title: string; detail: string; href: string | null }>;
};

export default function RouteSupervisorDashboard({ data, country }: { data: RouteSupervisorDashboardData; country: string }) {
    const operational = [
        { ...data.open_work_days, detail: 'Jornadas en curso', tone: 'emerald' as const },
        { ...data.pending_visits, detail: 'Requieren seguimiento', tone: 'amber' as const },
        { ...data.operational_pre_sales, detail: 'Pendientes de operación', tone: 'orange' as const },
        { ...data.pending_delivery_stops, detail: 'Pendientes de entrega', tone: 'slate' as const },
    ];
    const settlements = data.effective_settlements;

    return (
        <section data-dashboard-widget="route_supervisor.overview" className="space-y-5 sm:col-span-2 xl:col-span-3">
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                {operational.map((metric) => <Kpi key={metric.label} {...metric} />)}
                <Kpi
                    {...settlements}
                    value={formatCurrency(settlements.amount, country)}
                    detail={`${settlements.count} liquidación(es) confirmada(s)`}
                    tone="emerald"
                />
            </div>
            <section className="rounded-xl border border-slate-200/80 bg-white p-5 shadow-[0_8px_24px_rgba(15,23,42,0.05)]" aria-labelledby="route-supervisor-heading">
                <p className="text-xs font-bold tracking-[0.14em] text-orange-700">SUPERVISIÓN DE RUTAS</p>
                <h2 id="route-supervisor-heading" className="mt-2 text-xl font-bold text-slate-950">Operación actual</h2>
                <p className="mt-2 max-w-3xl text-sm text-slate-500">
                    Consulta el avance de jornadas, visitas, preventas, entregas y liquidaciones de la sucursal activa.
                </p>
            </section>
            <OperationalAlerts rows={data.operational_alerts} />
            <div className="grid gap-5 xl:grid-cols-3">
                <div className="xl:col-span-2"><SellerWorkDays data={data.seller_work_days} country={country} /></div>
                <PreSaleFlow data={data.pre_sale_flow} />
            </div>
            <div className="grid gap-5 xl:grid-cols-2">
                <DeliveryOverview data={data.delivery_overview} country={country} />
                <SettlementOverview data={data.settlement_overview} country={country} />
            </div>
        </section>
    );
}

function DeliveryOverview({ data, country }: { data: RouteSupervisorDashboardData['delivery_overview']; country: string }) {
    return <Panel title="Entregas y cobros" subtitle="Actividad diaria y pendientes acumulados">
        <div className="grid grid-cols-2 gap-3"><Stat label="Entregadas" value={data.delivered_today} detail="Hoy" /><Stat label="No realizadas" value={data.not_delivered_today} detail="Hoy" /><Stat label="Pendientes" value={data.pending_backlog} detail="Backlog" /><Stat label="Sin cobro" value={data.unpaid_delivered.count} detail={formatCurrency(data.unpaid_delivered.amount, country)} /></div>
        <Link href={data.href} className="mt-4 inline-flex rounded-md text-sm font-semibold text-orange-700 hover:text-orange-800 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2">Ver entregas</Link>
    </Panel>;
}

function SettlementOverview({ data, country }: { data: RouteSupervisorDashboardData['settlement_overview']; country: string }) {
    const variance = data.open_variances;

    return <Panel title="Liquidaciones" subtitle="Confirmaciones y diferencias de caja">
        <div className="grid grid-cols-2 gap-3"><Stat label="Confirmadas" value={data.confirmed_today.count} detail={`${formatCurrency(data.confirmed_today.amount, country)} · Hoy`} /><Stat label="Borradores" value={data.draft_count ?? '—'} detail={data.draft_count === null ? 'Sin permiso de consulta' : 'Pendientes'} />{variance && <><Stat label="Diferencias abiertas" value={variance.count} detail="Requieren revisión" /><Stat label="Faltantes / sobrantes" value={formatCurrency(variance.shortages_amount, country)} detail={`Sobrantes ${formatCurrency(variance.overages_amount, country)}`} /></>}</div>
        <div className="mt-4 flex flex-wrap gap-3">{data.href && <Link href={data.href} className="rounded-md text-sm font-semibold text-orange-700 hover:text-orange-800 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2">Ver liquidaciones</Link>}{data.variances_href && <Link href={data.variances_href} className="rounded-md text-sm font-semibold text-orange-700 hover:text-orange-800 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2">Ver diferencias</Link>}</div>
    </Panel>;
}

function OperationalAlerts({ rows }: { rows: RouteSupervisorDashboardData['operational_alerts'] }) {
    const tones = { critical: 'border-red-200 bg-red-50 text-red-800', warning: 'border-amber-200 bg-amber-50 text-amber-800', info: 'border-slate-200 bg-slate-50 text-slate-700' };

    return <Panel title="Requiere atención" subtitle="Incidencias operativas accionables">{rows.length === 0 ? <Empty>No hay incidencias operativas pendientes.</Empty> : <ul className="grid gap-2 lg:grid-cols-2">{rows.map((row) => <li key={row.key} className={`rounded-lg border p-3 ${tones[row.severity]}`}><p className="text-sm font-semibold">{row.title}</p><p className="mt-1 text-xs opacity-80">{row.detail}</p>{row.href && <Link href={row.href} className="mt-2 inline-flex rounded-md text-xs font-semibold underline focus:outline-none focus:ring-2 focus:ring-current focus:ring-offset-2">Ver detalle<span className="sr-only">: {row.title}</span></Link>}</li>)}</ul>}</Panel>;
}

function SellerWorkDays({ data, country }: { data: RouteSupervisorDashboardData['seller_work_days']; country: string }) {
    return <Panel title="Estado por preventista" subtitle="Jornadas abiertas de la sucursal activa">
        {data.items.length === 0 ? <Empty>No hay jornadas abiertas en esta sucursal.</Empty> : <ol className="divide-y divide-slate-100">
            {data.items.map((workDay) => {
                const progress = workDay.total_visits > 0 ? Math.round((workDay.completed_visits / workDay.total_visits) * 100) : 0;
                return <li key={workDay.id} className="py-4 first:pt-0 last:pb-0">
                    <div className="flex flex-wrap items-start justify-between gap-2"><div className="min-w-0"><h4 className="break-words font-semibold text-slate-900">{workDay.seller_name ?? 'Preventista no registrado'}</h4><p className="mt-1 break-words text-xs text-slate-500">{workDay.zone_name ?? 'Ruta sin zona'} · {workDay.branch_name ?? 'Sucursal'}</p></div><span className="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">{workDay.status === 'open' ? 'Jornada abierta' : workDay.status}</span></div>
                    <div className="mt-3 h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-label={`Progreso de visitas de ${workDay.seller_name ?? 'preventista no registrado'}`} aria-valuemin={0} aria-valuemax={100} aria-valuenow={progress}><div className="h-full rounded-full bg-orange-600" style={{ width: `${progress}%` }} /></div>
                    <div className="mt-2 flex flex-wrap justify-between gap-x-3 gap-y-1 text-xs text-slate-500"><span>{workDay.completed_visits} de {workDay.total_visits} visitas · {progress}%</span><span>{workDay.pending_visits} pendiente(s)</span></div>
                    <div className="mt-3 grid grid-cols-2 gap-3"><Stat label="Preventas" value={workDay.pre_sales_count} detail={formatCurrency(workDay.pre_sales_total, country)} /><Stat label="Estado" value={workDay.status === 'open' ? 'Abierta' : workDay.status} detail="Jornada actual" /></div>
                </li>;
            })}
        </ol>}
        {data.has_more && <Link href={data.href} className="mt-4 inline-flex rounded-md text-sm font-semibold text-orange-700 hover:text-orange-800 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2">Ver las {data.total_open_work_days} jornadas abiertas</Link>}
    </Panel>;
}

function PreSaleFlow({ data }: { data: RouteSupervisorDashboardData['pre_sale_flow'] }) {
    const stages = [
        { label: 'Pendientes de preparación', value: data.submitted, period: 'Pendiente', tone: 'text-amber-700' },
        { label: 'En preparación', value: data.processing, period: 'Actual', tone: 'text-orange-700' },
        { label: 'Listas para convertir', value: data.picked, period: 'Pendiente', tone: 'text-slate-900' },
        { label: 'Convertidas', value: data.converted_today, period: 'Hoy', tone: 'text-emerald-700' },
    ];

    return <Panel title="Flujo de preventas" subtitle="Actividad actual y conversiones acreditadas hoy"><div className="grid gap-3 sm:grid-cols-2">
        {stages.map((stage) => <div key={stage.label} className="rounded-lg bg-slate-50 p-4"><div className="flex items-start justify-between gap-2"><p className="text-xs font-semibold text-slate-600">{stage.label}</p><span className="rounded-full bg-white px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-500">{stage.period}</span></div><p className={`mt-3 text-2xl font-bold ${stage.tone}`}>{stage.value}</p></div>)}
    </div></Panel>;
}

function Kpi({ label, period, value, detail, tone }: CountMetric & { value?: string | number; detail: string; tone: 'emerald' | 'amber' | 'orange' | 'slate' }) {
    const color = { emerald: 'text-emerald-700', amber: 'text-amber-700', orange: 'text-orange-700', slate: 'text-slate-950' }[tone];

    return (
        <article className="min-w-0 rounded-xl border border-slate-200/80 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,0.05)]">
            <div className="flex items-start justify-between gap-2">
                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p>
                <span className="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-500">{periodLabel(period)}</span>
            </div>
            <p className={`mt-3 break-words text-2xl font-bold tracking-tight ${color}`}>{value ?? 0}</p>
            <p className="mt-2 break-words text-xs text-slate-500">{detail}</p>
        </article>
    );
}

function periodLabel(period: MetricPeriod): string {
    return period === 'today' ? 'Hoy' : period === 'backlog' ? 'Pendiente' : 'Actual';
}

function Panel({ title, subtitle, children }: { title: string; subtitle: string; children: ReactNode }) {
    return <section className="rounded-xl border border-slate-200/80 bg-white p-5 shadow-[0_8px_24px_rgba(15,23,42,0.05)]"><h3 className="text-sm font-semibold text-slate-950">{title}</h3><p className="mt-1 text-xs text-slate-500">{subtitle}</p><div className="mt-4">{children}</div></section>;
}

function Stat({ label, value, detail }: { label: string; value: string | number; detail: string }) {
    return <div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-500">{label}</p><p className="mt-1 text-lg font-bold text-slate-950">{value}</p><p className="mt-1 text-xs text-slate-500">{detail}</p></div>;
}

function Empty({ children }: { children: ReactNode }) {
    return <p className="text-sm text-slate-500">{children}</p>;
}
