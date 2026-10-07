import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatCurrency } from '@/utils/currency';
import { Head, usePage } from '@inertiajs/react';

type Widget = {
    key: string;
    title: string;
    type: 'sales_summary' | 'cash_status' | 'stock_alerts' | 'top_product' | 'money';
    period: 'today' | 'this_month';
    data: Record<string, string | number | null>;
};

type DashboardData = {
    profile: 'owner_simple';
    context: {
        timezone: string;
        branch: { id: number; name: string; code: string | null } | null;
        branch_scoped: boolean;
    };
    widgets: Widget[];
};

function periodLabel(period: Widget['period']): string {
    return period === 'this_month' ? 'Este mes' : 'Hoy';
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
                        <p className="text-sm font-semibold text-indigo-700">Resumen del negocio</p>
                        <div className="mt-1 flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <h1 className="text-2xl font-bold tracking-tight text-slate-950">Información operativa</h1>
                                <p className="mt-1 text-sm text-slate-500">
                                    {branchLabel} · Cálculo según la zona horaria del negocio.
                                </p>
                            </div>
                            <span className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600">
                                {dashboard.context.timezone}
                            </span>
                        </div>
                    </section>

                    <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {dashboard.widgets.map((widget) => (
                            <WidgetCard key={widget.key} widget={widget} country={country} />
                        ))}
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function WidgetCard({ widget, country }: { widget: Widget; country: string }) {
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

function widgetContent(widget: Widget, country: string) {
    const data = widget.data;

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
                <Metric label="Sin stock" value={data.out_of_stock_count} tone="text-red-600" />
                <Metric label="Stock bajo" value={data.low_stock_count} tone="text-amber-600" />
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

    return <p className="mt-6 text-3xl font-bold tracking-tight text-slate-950">{formatCurrency(data.amount, country)}</p>;
}

function Metric({ label, value, tone }: { label: string; value: string | number | null; tone: string }) {
    return (
        <div className="rounded-xl bg-slate-50 p-3">
            <p className="text-xs font-medium text-slate-500">{label}</p>
            <p className={`mt-1 text-2xl font-bold ${tone}`}>{value ?? 0}</p>
        </div>
    );
}
