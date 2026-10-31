import { formatCurrency } from '@/utils/currency';

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
            <section className="rounded-xl border border-slate-200/80 bg-white p-5 shadow-[0_8px_24px_rgba(15,23,42,0.05)]">
                <p className="text-xs font-bold tracking-[0.14em] text-orange-700">SUPERVISIÓN DE RUTAS</p>
                <h2 className="mt-2 text-xl font-bold text-slate-950">Operación actual</h2>
                <p className="mt-2 max-w-3xl text-sm text-slate-500">
                    Consulta el avance de jornadas, visitas, preventas, entregas y liquidaciones de la sucursal activa.
                </p>
            </section>
        </section>
    );
}

function Kpi({ label, period, value, detail, tone }: CountMetric & { value?: string | number; detail: string; tone: 'emerald' | 'amber' | 'orange' | 'slate' }) {
    const color = { emerald: 'text-emerald-700', amber: 'text-amber-700', orange: 'text-orange-700', slate: 'text-slate-950' }[tone];

    return (
        <article className="min-w-0 rounded-xl border border-slate-200/80 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,0.05)]">
            <div className="flex items-start justify-between gap-2">
                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p>
                <span className="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-500">{periodLabel(period)}</span>
            </div>
            <p className={`mt-3 truncate text-2xl font-bold tracking-tight ${color}`}>{value ?? 0}</p>
            <p className="mt-2 truncate text-xs text-slate-500">{detail}</p>
        </article>
    );
}

function periodLabel(period: MetricPeriod): string {
    return period === 'today' ? 'Hoy' : period === 'backlog' ? 'Pendiente' : 'Actual';
}
