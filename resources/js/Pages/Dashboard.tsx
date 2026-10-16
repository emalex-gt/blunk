import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatCurrency } from '@/utils/currency';
import { Head, Link, usePage } from '@inertiajs/react';

type Widget = {
    key: string;
    title: string;
    type: 'sales_summary' | 'cash_status' | 'stock_alerts' | 'top_product' | 'money' | 'count' | 'route_work_day' | 'route_delivery_run' | 'cashier_workspace';
    period: 'today' | 'this_month' | 'current';
    data: Record<string, unknown>;
};

type RouteWorkDayData = {
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
    next_visit?: { id: number; visit_order: number | null; customer_name: string | null; address: string | null; phone: string | null; href: string } | null;
    primary_action: { label: string; href: string };
    route_cash?: { is_open: boolean; message: string } | null;
    pending_collections?: { count: number; total: string | number; href: string } | null;
};

type RouteDeliveryRunData = {
    has_current_run: boolean;
    message?: string;
    run?: { id: number; status: 'open' | 'draft' };
    progress?: { assigned: number; pending: number; delivered: number; not_delivered: number; unpaid_delivered_count: number; unpaid_delivered_amount: string | number };
    next_stop?: { id: number; position: number | null; customer_name: string | null; customer_address: string | null; customer_phone: string | null; sale_number: string | null; sale_total: string | number | null; href: string } | null;
    primary_action: { label: string; href: string };
};

type CashierWorkspaceData = {
    cash_session: { status: 'open' | 'closed'; id?: number; opened_at?: string | null } | null;
    sales_period: 'current_session' | 'today'; sales_count: number; sales_total: string | number; average_ticket: string | number; last_sale_time: string | null;
    latest_sales: Array<{ id: number; business_number: number | null; total: string | number; created_at: string | null }>;
    active_pos_drafts_count: number; primary_action: { label: string; href: string }; cash_action: { label: string; href: string } | null;
};

type DashboardData = {
    profile: 'owner_simple' | 'executive' | 'branch_manager' | 'cashier' | 'route_seller' | 'route_delivery' | 'route_supervisor' | 'warehouse' | 'purchases' | 'finance' | 'generic';
    profile_meta: { title: string; description: string };
    context: {
        timezone: string;
        branch: { id: number; name: string; code: string | null } | null;
        branch_scoped: boolean;
    };
    widgets: Widget[];
    actions: Array<{
        key: string;
        label: string;
        href: string;
        emphasis: 'primary' | 'secondary';
    }>;
};

function periodLabel(period: Widget['period']): string {
    return period === 'this_month' ? 'Este mes' : period === 'current' ? 'Actual' : 'Hoy';
}

export default function Dashboard({ dashboard }: { dashboard: DashboardData }) {
    const business = usePage().props.business as { country?: string | null } | null;
    const country = business?.country ?? 'GT';
    const branchLabel = dashboard.context.branch_scoped && dashboard.context.branch
        ? dashboard.context.branch.name
        : 'Todas las sucursales';

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-950">Dashboard</h2>}>
            <Head title="Dashboard" />

            <div className="py-5">
                <div className="mx-auto max-w-[1800px] space-y-5 px-5 sm:px-6">
                    <section className="rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <p className="text-sm font-semibold text-orange-700">Dashboard</p>
                        <div className="mt-1 flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <h1 className="text-2xl font-bold tracking-tight text-slate-950">{dashboard.profile_meta.title}</h1>
                                <p className="mt-1 text-sm text-slate-500">
                                    {dashboard.profile_meta.description} {branchLabel} · Cálculo según la zona horaria del negocio.
                                </p>
                            </div>
                            <span className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600">
                                {dashboard.context.timezone}
                            </span>
                        </div>
                    </section>

                    {dashboard.actions.length > 0 && (
                        <section aria-label="Accesos rápidos" className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            {dashboard.actions.map((action) => (
                                <Link
                                    key={action.key}
                                    href={action.href}
                                    data-dashboard-action={action.key}
                                    className={`rounded-2xl border px-5 py-4 text-sm font-semibold shadow-[0_8px_30px_rgba(15,23,42,0.06)] transition focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2 ${
                                        action.emphasis === 'primary'
                                            ? 'border-orange-600 bg-orange-600 text-white hover:bg-orange-700'
                                            : 'border-slate-200 bg-white text-slate-800 hover:border-orange-300 hover:text-orange-800'
                                    }`}
                                >
                                    {action.label}
                                </Link>
                            ))}
                        </section>
                    )}

                    {dashboard.widgets.length > 0 ? (
                        <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            {dashboard.widgets.map((widget) => (
                                <WidgetCard key={widget.key} widget={widget} country={country} />
                            ))}
                        </section>
                    ) : dashboard.actions.length === 0 ? (
                        <section className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center">
                            <h2 className="text-base font-semibold text-slate-800">No hay acciones disponibles</h2>
                            <p className="mt-2 text-sm text-slate-500">Tu inicio mostrará accesos cuando tengas permisos operativos disponibles.</p>
                        </section>
                    ) : null}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function WidgetCard({ widget, country }: { widget: Widget; country: string }) {
    if (widget.type === 'route_work_day') {
        return <RouteWorkDayCard data={widget.data as RouteWorkDayData} country={country} />;
    }

    if (widget.type === 'route_delivery_run') {
        return <RouteDeliveryRunCard data={widget.data as RouteDeliveryRunData} country={country} />;
    }

    if (widget.type === 'cashier_workspace') {
        return <CashierWorkspaceCard data={widget.data as CashierWorkspaceData} country={country} />;
    }

    const content = widgetContent(widget, country);

    return (
        <article
            data-dashboard-widget={widget.key}
            className="min-h-44 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]"
        >
            <div className="flex items-start justify-between gap-3">
                <h3 className="text-sm font-semibold text-slate-700">{widget.title}</h3>
                <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-500">
                    {periodLabel(widget.period)}
                </span>
            </div>
            {content}
        </article>
    );
}

function CashierWorkspaceCard({ data, country }: { data: CashierWorkspaceData; country: string }) {
    const open = data.cash_session?.status === 'open';
    return <section data-dashboard-widget="cashier.workspace" className="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
        <p className="text-xs font-bold tracking-[0.14em] text-orange-700">MI CAJA</p>
        {data.cash_session && <div className={`mt-2 rounded-xl p-3 text-sm font-medium ${open ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-50 text-slate-600'}`}><p>{open ? '● Caja abierta' : 'No hay una caja abierta.'}</p>{open && data.cash_session.opened_at && <p className="mt-1 text-xs">Desde {new Date(data.cash_session.opened_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</p>}</div>}
        <p className="mt-5 text-sm text-slate-500">{data.sales_period === 'current_session' ? 'Mis ventas del turno' : 'Mis ventas de hoy'}</p>
        <p className="text-3xl font-bold tracking-tight text-slate-950">{formatCurrency(data.sales_total, country)}</p>
        <div className="mt-4 grid grid-cols-3 gap-3"><Metric label="Operaciones" value={data.sales_count} tone="text-slate-900" /><Metric label="Ticket promedio" value={formatCurrency(data.average_ticket, country)} tone="text-orange-700" /><Metric label="Borradores" value={data.active_pos_drafts_count} tone="text-slate-700" /></div>
        {data.latest_sales.length > 0 && <div className="mt-5 rounded-xl bg-slate-50 p-4"><p className="text-xs font-bold tracking-wide text-slate-500">ÚLTIMAS VENTAS</p>{data.latest_sales.map((sale) => <div key={sale.id} className="mt-2 flex justify-between gap-3 text-sm text-slate-700"><span>#{sale.business_number ?? sale.id}</span><span className="font-semibold">{formatCurrency(sale.total, country)}</span></div>)}</div>}
        <Link href={data.primary_action.href} className="mt-5 block rounded-xl bg-orange-600 px-4 py-3 text-center text-sm font-bold text-white hover:bg-orange-700">{data.primary_action.label}</Link>
        {data.cash_action && <Link href={data.cash_action.href} className="mt-3 block rounded-xl border border-slate-200 px-4 py-3 text-center text-sm font-semibold text-slate-700 hover:border-orange-300">{data.cash_action.label}</Link>}
    </section>;
}

function RouteDeliveryRunCard({ data, country }: { data: RouteDeliveryRunData; country: string }) {
    if (!data.has_current_run) {
        return (
            <section data-dashboard-widget="routes.my_delivery_run" className="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                <p className="text-xs font-bold tracking-[0.14em] text-orange-700">MIS ENTREGAS</p>
                <h2 className="mt-2 text-xl font-bold text-slate-950">Mis entregas</h2>
                <p className="mt-2 text-sm text-slate-500">{data.message}</p>
                <Link href={data.primary_action.href} className="mt-5 block rounded-xl bg-orange-600 px-4 py-3 text-center text-sm font-bold text-white hover:bg-orange-700">{data.primary_action.label}</Link>
            </section>
        );
    }

    const progress = data.progress!;
    const resolved = progress.delivered + progress.not_delivered;
    const completion = progress.assigned > 0 ? Math.min(100, Math.round((resolved / progress.assigned) * 100)) : 0;
    const isOpen = data.run?.status === 'open';

    return (
        <section data-dashboard-widget="routes.my_delivery_run" className="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
            <p className="text-xs font-bold tracking-[0.14em] text-orange-700">MIS ENTREGAS</p>
            <div className="mt-2 flex items-baseline justify-between gap-2">
                <h2 className="text-xl font-bold text-slate-950">Jornada #{data.run?.id}</h2>
                {isOpen ? <span className="text-sm font-semibold text-slate-700">{resolved} de {progress.assigned} resueltas</span> : <span className="text-sm font-semibold text-amber-700">Lista para iniciar</span>}
            </div>
            {isOpen ? <>
                <div className="mt-4 h-2 overflow-hidden rounded-full bg-slate-100" aria-label={`Progreso ${completion}%`}><div className="h-full rounded-full bg-orange-600" style={{ width: `${completion}%` }} /></div>
                <div className="mt-4 grid grid-cols-3 gap-3"><Metric label="Entregadas" value={progress.delivered} tone="text-emerald-700" /><Metric label="Pendientes" value={progress.pending} tone="text-amber-700" /><Metric label="No entregadas" value={progress.not_delivered} tone="text-slate-700" /></div>
                {progress.unpaid_delivered_count > 0 && <p className="mt-4 rounded-xl bg-amber-50 p-3 text-sm font-medium text-amber-800">{progress.unpaid_delivered_count} entrega(s) realizada(s) siguen pendientes de cobro · {formatCurrency(progress.unpaid_delivered_amount, country)}</p>}
                {data.next_stop ? <div className="mt-5 rounded-xl bg-slate-50 p-4"><p className="text-xs font-bold tracking-wide text-slate-500">SIGUIENTE ENTREGA</p><p className="mt-1 font-semibold text-slate-900">{data.next_stop.position ?? '—'}. {data.next_stop.customer_name}</p>{data.next_stop.customer_address && <p className="mt-1 text-sm text-slate-500">{data.next_stop.customer_address}</p>}<p className="mt-1 text-sm text-slate-500">{data.next_stop.sale_number ? `Comprobante #${data.next_stop.sale_number}` : 'Comprobante'}{data.next_stop.sale_total !== null ? ` · ${formatCurrency(data.next_stop.sale_total, country)}` : ''}</p><Link href={data.next_stop.href} className="mt-3 inline-flex text-sm font-semibold text-orange-700 hover:text-orange-800">Abrir entrega</Link></div> : <p className="mt-5 rounded-xl bg-emerald-50 p-4 text-sm font-medium text-emerald-800">Todas las entregas están resueltas.</p>}
            </> : <p className="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">{progress.assigned} parada(s) asignada(s)</p>}
            <Link href={data.primary_action.href} className="mt-5 block rounded-xl bg-orange-600 px-4 py-3 text-center text-sm font-bold text-white hover:bg-orange-700">{data.primary_action.label}</Link>
        </section>
    );
}

function widgetContent(widget: Widget, country: string) {
    const data = widget.data as Record<string, string | number | null | undefined>;

    if (widget.type === 'sales_summary') {
        return (
            <>
                <p className="mt-6 text-3xl font-bold tracking-tight text-slate-950">{formatCurrency(data.total, country)}</p>
                <p className="mt-2 text-sm text-slate-500">
                    {data.count} ventas · Ticket promedio {formatCurrency(data.average_ticket, country)}
                </p>
                {widget.period === 'today' && (
                    <div className="mt-4 flex items-center justify-between gap-3 text-xs font-medium text-slate-500">
                        <span>Última venta: {data.last_sale_time ?? 'Sin ventas'}</span>
                        <span>Anuladas: {data.cancelled_count ?? 0}</span>
                    </div>
                )}
            </>
        );
    }

    if (widget.type === 'cash_status') {
        const isOpen = data.status === 'open';

        return (
            <>
                <p className={`mt-6 text-3xl font-bold ${isOpen ? 'text-emerald-700' : 'text-slate-950'}`}>
                    {isOpen ? 'Abierta' : 'Cerrada'}
                </p>
                <p className="mt-2 text-sm text-slate-500">
                    {isOpen ? `Esperado: ${formatCurrency(data.expected_cash, country)}` : 'No hay una caja abierta.'}
                </p>
            </>
        );
    }

    if (widget.type === 'stock_alerts') {
        return (
            <div className="mt-6 grid grid-cols-2 gap-3">
                <Metric label="Sin stock" value={data.out_of_stock_count ?? null} tone="text-red-600" />
                <Metric label="Stock bajo" value={data.low_stock_count ?? null} tone="text-amber-600" />
            </div>
        );
    }

    if (widget.type === 'top_product') {
        return (
            <>
                <p className="mt-6 line-clamp-2 text-2xl font-bold tracking-tight text-slate-950">
                    {data.name ?? 'Sin ventas'}
                </p>
                <p className="mt-2 text-sm text-slate-500">{data.quantity ?? 0} unidades vendidas hoy</p>
            </>
        );
    }

    if (widget.type === 'count') {
        return (
            <>
                <p className="mt-6 text-3xl font-bold tracking-tight text-slate-950">{data.count ?? 0}</p>
                {data.detail !== undefined && <p className="mt-2 text-sm text-slate-500">{data.detail} unidades reservadas</p>}
            </>
        );
    }

    return (
        <>
            <p className="mt-6 text-3xl font-bold tracking-tight text-slate-950">{formatCurrency(data.amount, country)}</p>
            {data.count !== undefined && <p className="mt-2 text-sm text-slate-500">{data.count} registros</p>}
        </>
    );
}

function RouteWorkDayCard({ data, country }: { data: RouteWorkDayData; country: string }) {
    if (!data.has_open_work_day) {
        return (
            <section data-dashboard-widget="routes.my_work_day" className="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                <p className="text-xs font-bold tracking-[0.14em] text-orange-700">MI JORNADA</p>
                <h2 className="mt-2 text-xl font-bold text-slate-950">No tienes una jornada abierta</h2>
                <p className="mt-2 text-sm text-slate-500">{data.message}</p>
                {data.assigned_zones_count !== undefined && data.assigned_zones_count > 0 && (
                    <p className="mt-2 text-sm text-slate-600">Tienes {data.assigned_zones_count} zona(s) asignada(s).</p>
                )}
                <Link href={data.primary_action.href} className="mt-5 block rounded-xl bg-orange-600 px-4 py-3 text-center text-sm font-bold text-white hover:bg-orange-700">
                    {data.primary_action.label}
                </Link>
                <PendingCollections data={data.pending_collections} country={country} />
            </section>
        );
    }

    const totalVisits = data.total_visits ?? 0;
    const visited = data.visited ?? 0;
    const progress = totalVisits > 0 ? Math.min(100, Math.round((visited / totalVisits) * 100)) : 0;
    const canOperateRoute = data.route_cash?.is_open === true;

    return (
        <section data-dashboard-widget="routes.my_work_day" className="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
            <p className="text-xs font-bold tracking-[0.14em] text-orange-700">MI JORNADA</p>
            <div className="mt-2 flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <h2 className="text-xl font-bold text-slate-950">{data.work_day?.zone_name ?? 'Ruta asignada'}</h2>
                    <p className="text-sm text-slate-500">{data.work_day?.branch_name}</p>
                </div>
                <span className="text-sm font-semibold text-slate-700">{visited} / {totalVisits} visitas</span>
            </div>
            <div className="mt-4 h-2 overflow-hidden rounded-full bg-slate-100" aria-label={`Progreso ${progress}%`}>
                <div className="h-full rounded-full bg-orange-600 transition-all" style={{ width: `${progress}%` }} />
            </div>
            <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <Metric label="Realizadas" value={visited} tone="text-slate-900" />
                <Metric label="Pendientes" value={data.pending ?? 0} tone="text-amber-700" />
                <Metric label="Preventas" value={data.pre_sales_count ?? 0} tone="text-orange-700" />
                <Metric label="Sin venta" value={data.without_sale ?? 0} tone="text-slate-700" />
            </div>
            <p className="mt-5 text-sm text-slate-500">Total de preventas</p>
            <p className="text-2xl font-bold text-slate-950">{formatCurrency(data.pre_sales_total ?? 0, country)}</p>
            {data.next_visit ? (
                <div className="mt-5 rounded-xl bg-slate-50 p-4">
                    <p className="text-xs font-bold tracking-wide text-slate-500">SIGUIENTE CLIENTE</p>
                    <p className="mt-1 font-semibold text-slate-900">{data.next_visit.customer_name}</p>
                    {data.next_visit.address && <p className="mt-1 text-sm text-slate-500">{data.next_visit.address}</p>}
                    {canOperateRoute ? (
                        <Link data-route-next-visit-action="active" href={data.next_visit.href} className="mt-3 inline-flex text-sm font-semibold text-orange-700 hover:text-orange-800">Abrir visita</Link>
                    ) : (
                        <div data-route-next-visit-action="disabled" className="mt-3 text-sm text-slate-400" aria-disabled="true">
                            <span className="font-semibold">Abrir visita</span>
                            <span className="ml-2">Disponible cuando la caja de rutas esté abierta.</span>
                        </div>
                    )}
                </div>
            ) : (
                <p className="mt-5 rounded-xl bg-emerald-50 p-4 text-sm font-medium text-emerald-800">Todas las visitas están resueltas.</p>
            )}
            {!data.route_cash?.is_open && data.route_cash && <p className="mt-4 rounded-xl bg-amber-50 p-3 text-sm font-medium text-amber-800">{data.route_cash.message}</p>}
            {canOperateRoute ? (
                <Link data-route-continue-action="active" href={data.primary_action.href} className="mt-5 block rounded-xl bg-orange-600 px-4 py-3 text-center text-sm font-bold text-white hover:bg-orange-700">
                    {data.primary_action.label}
                </Link>
            ) : (
                <div data-route-continue-action="disabled" className="mt-5 rounded-xl bg-slate-200 px-4 py-3 text-center text-sm font-bold text-slate-500" aria-disabled="true">
                    <div>{data.primary_action.label}</div>
                    <div className="mt-1 text-xs font-medium">Disponible cuando la caja de rutas esté abierta.</div>
                </div>
            )}
            <PendingCollections data={data.pending_collections} country={country} />
        </section>
    );
}

function PendingCollections({ data, country }: { data?: RouteWorkDayData['pending_collections']; country: string }) {
    if (!data) return null;

    return (
        <Link href={data.href} className="mt-4 block rounded-xl border border-slate-200 p-4 hover:border-orange-300">
            <p className="text-sm font-semibold text-slate-800">Cobros pendientes</p>
            <p className="mt-1 text-sm text-slate-500">{data.count} pendiente(s) · {formatCurrency(data.total, country)}</p>
        </Link>
    );
}

function Metric({ label, value, tone }: { label: string; value: string | number | null; tone: string }) {
    return (
        <div className="rounded-xl bg-slate-50 p-3">
            <p className="text-xs font-medium text-slate-500">{label}</p>
            <p className={`mt-1 text-2xl font-bold ${tone}`}>{value ?? 0}</p>
        </div>
    );
}
