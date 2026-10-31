import { Link } from '@inertiajs/react';
import { formatCurrency } from '@/utils/currency';

export type RouteSellerDashboardData = {
    has_open_work_day: boolean;
    message?: string;
    assigned_zones_count?: number;
    work_day?: { id: number; zone_name: string | null; branch_name: string | null };
    total_visits?: number;
    visited?: number;
    pending?: number;
    with_pre_sale?: number;
    without_sale?: number;
    pre_sales_count?: number;
    pre_sales_total?: string | number;
    next_visit?: {
        id: number;
        visit_order: number | null;
        customer_name: string | null;
        address: string | null;
        phone: string | null;
        href: string;
    } | null;
    primary_action: { label: string; href: string };
    route_cash?: { is_open: boolean; message: string } | null;
    pending_collections?: { count: number; total: string | number; href: string } | null;
};

export default function RouteSellerDashboard({ data, country }: { data: RouteSellerDashboardData; country: string }) {
    if (!data.has_open_work_day) {
        return <NoOpenWorkDay data={data} country={country} />;
    }

    const totalVisits = data.total_visits ?? 0;
    const visited = data.visited ?? 0;
    const progress = totalVisits > 0 ? Math.min(100, Math.round((visited / totalVisits) * 100)) : 0;
    const canOperateRoute = data.route_cash?.is_open === true;

    return (
        <section data-dashboard-widget="routes.my_work_day" className="sm:col-span-2 xl:col-span-3 rounded-2xl border border-slate-200/80 bg-white shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
            <header className="border-b border-slate-100 p-5 sm:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className="text-xs font-bold tracking-[0.14em] text-orange-700">MI JORNADA</p>
                        <h2 className="mt-1 text-2xl font-bold tracking-tight text-slate-950">{data.work_day?.zone_name ?? 'Ruta asignada'}</h2>
                        {data.work_day?.branch_name && <p className="mt-1 text-sm text-slate-500">Sucursal: {data.work_day.branch_name}</p>}
                    </div>
                    <span className="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-800">Jornada abierta</span>
                </div>

                <div className="mt-5">
                    <div className="flex items-baseline justify-between gap-3">
                        <p className="text-sm font-semibold text-slate-800">Progreso de visitas</p>
                        <p className="text-sm font-bold text-slate-900">{visited} de {totalVisits}</p>
                    </div>
                    <div
                        className="mt-2 h-3 overflow-hidden rounded-full bg-slate-100"
                        role="progressbar"
                        aria-label="Progreso de visitas"
                        aria-valuemin={0}
                        aria-valuemax={totalVisits}
                        aria-valuenow={visited}
                        aria-valuetext={`${progress}% completado`}
                    >
                        <div className="h-full rounded-full bg-orange-600 transition-all" style={{ width: `${progress}%` }} />
                    </div>
                    <p className="mt-2 text-xs font-medium text-slate-500">{progress}% completado</p>
                </div>
            </header>

            <div className="space-y-5 p-5 sm:p-6">
                <section aria-label="Indicadores operativos" className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <MetricTile label="Visitas realizadas" value={visited} tone="slate" />
                    <MetricTile label="Visitas pendientes" value={data.pending ?? 0} tone="amber" />
                    <MetricTile label="Con preventa" value={data.with_pre_sale ?? 0} tone="orange" />
                    <MetricTile label="Sin venta" value={data.without_sale ?? 0} tone="slate" />
                </section>

                <section aria-label="Actividad comercial" className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <p className="text-xs font-bold uppercase tracking-wide text-slate-500">Actividad comercial</p>
                    <div className="mt-2 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p className="text-2xl font-bold text-slate-950">{data.pre_sales_count ?? 0}</p>
                            <p className="text-sm text-slate-500">preventas registradas</p>
                        </div>
                        <div className="text-left sm:text-right">
                            <p className="text-lg font-bold text-slate-950">{formatCurrency(data.pre_sales_total ?? 0, country)}</p>
                            <p className="text-sm text-slate-500">total de preventas</p>
                        </div>
                    </div>
                </section>

                {data.next_visit ? (
                    <section aria-label="Próxima visita" className="rounded-xl border border-orange-200 bg-orange-50/60 p-4">
                        <p className="text-xs font-bold uppercase tracking-wide text-orange-800">Próxima visita{data.next_visit.visit_order ? ` · #${data.next_visit.visit_order}` : ''}</p>
                        <p className="mt-2 text-lg font-bold text-slate-950">{data.next_visit.customer_name ?? 'Cliente sin nombre'}</p>
                        {data.next_visit.address && <p className="mt-1 text-sm text-slate-600">{data.next_visit.address}</p>}
                        {canOperateRoute ? (
                            <Link data-route-next-visit-action="active" href={data.next_visit.href} className="mt-4 block rounded-xl bg-orange-600 px-4 py-3 text-center text-sm font-bold text-white transition hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2">
                                Abrir visita
                            </Link>
                        ) : (
                            <div data-route-next-visit-action="disabled" className="mt-4 rounded-xl bg-slate-200 px-4 py-3 text-center text-sm font-bold text-slate-500" aria-disabled="true">
                                <div>Abrir visita</div>
                                <div className="mt-1 text-xs font-medium">Disponible cuando la caja de rutas esté abierta.</div>
                            </div>
                        )}
                    </section>
                ) : (
                    <section aria-label="Próxima visita" className="rounded-xl bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
                        Todas las visitas están resueltas.
                    </section>
                )}

                <section aria-label="Caja y cobros" className="grid gap-3 lg:grid-cols-2">
                    <div className={`rounded-xl border p-4 ${canOperateRoute ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50'}`}>
                        <p className="text-xs font-bold uppercase tracking-wide text-slate-600">Caja de ruta</p>
                        <p className={`mt-2 text-sm font-semibold ${canOperateRoute ? 'text-emerald-800' : 'text-amber-900'}`}>
                            {data.route_cash?.message ?? 'No hay estado de caja disponible.'}
                        </p>
                        {canOperateRoute ? (
                            <Link data-route-continue-action="active" href={data.primary_action.href} className="mt-4 block rounded-xl bg-orange-600 px-4 py-3 text-center text-sm font-bold text-white transition hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2">
                                {data.primary_action.label}
                            </Link>
                        ) : (
                            <div data-route-continue-action="disabled" className="mt-4 rounded-xl bg-slate-200 px-4 py-3 text-center text-sm font-bold text-slate-500" aria-disabled="true">
                                <div>{data.primary_action.label}</div>
                                <div className="mt-1 text-xs font-medium">Disponible cuando la caja de rutas esté abierta.</div>
                            </div>
                        )}
                    </div>
                    <PendingCollections data={data.pending_collections} country={country} />
                </section>
            </div>
        </section>
    );
}

function NoOpenWorkDay({ data, country }: { data: RouteSellerDashboardData; country: string }) {
    return (
        <section data-dashboard-widget="routes.my_work_day" className="sm:col-span-2 xl:col-span-3 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)] sm:p-6">
            <p className="text-xs font-bold tracking-[0.14em] text-orange-700">MI JORNADA</p>
            <h2 className="mt-2 text-2xl font-bold tracking-tight text-slate-950">No tienes una jornada abierta</h2>
            <p className="mt-2 max-w-xl text-sm text-slate-500">{data.message}</p>
            {data.assigned_zones_count !== undefined && data.assigned_zones_count > 0 && (
                <p className="mt-2 text-sm font-medium text-slate-700">Tienes {data.assigned_zones_count} zona(s) asignada(s).</p>
            )}
            <Link href={data.primary_action.href} className="mt-5 block rounded-xl bg-orange-600 px-4 py-3 text-center text-sm font-bold text-white transition hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2">
                {data.primary_action.label}
            </Link>
            {data.pending_collections && (
                <div className="mt-4"><PendingCollections data={data.pending_collections} country={country} /></div>
            )}
        </section>
    );
}

function PendingCollections({ data, country }: { data?: RouteSellerDashboardData['pending_collections']; country: string }) {
    if (!data) {
        return null;
    }

    return (
        <Link href={data.href} className="block rounded-xl border border-slate-200 bg-white p-4 transition hover:border-orange-300 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2">
            <p className="text-xs font-bold uppercase tracking-wide text-slate-500">Cobros pendientes</p>
            <p className="mt-2 text-lg font-bold text-slate-950">{data.count} pendiente(s)</p>
            <p className="mt-1 text-sm text-slate-500">{formatCurrency(data.total, country)}</p>
        </Link>
    );
}

function MetricTile({ label, value, tone }: { label: string; value: number; tone: 'slate' | 'amber' | 'orange' }) {
    const color = tone === 'amber' ? 'text-amber-700' : tone === 'orange' ? 'text-orange-700' : 'text-slate-950';

    return (
        <div className="rounded-xl bg-slate-50 p-3">
            <p className="text-xs font-medium leading-4 text-slate-500">{label}</p>
            <p className={`mt-2 text-2xl font-bold ${color}`}>{value}</p>
        </div>
    );
}
