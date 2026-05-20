import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatCurrency } from '@/utils/currency';
import { Head, Link, usePage } from '@inertiajs/react';

type Stats = {
    sales_count: number;
    sales_total: number;
    average_ticket: number;
    low_stock_count: number;
    out_of_stock_count: number;
    top_product: string | null;
    estimated_margin: number;
    cancelled_sales_count: number;
    last_sale_time: string | null;
    cash_register_status: 'open' | 'closed';
    cash_register_expected: number | null;
    timezone: string;
};

export default function Dashboard({ stats }: { stats: Stats }) {
    const business = usePage().props.business as { country?: string | null } | null;
    const country = business?.country ?? 'GT';
    const lastSaleTime = stats.last_sale_time
        ? `${stats.last_sale_time}${country === 'AR' ? ' hs' : ''}`
        : 'Sin ventas';

    const metricRows = [
        ['Total vendido hoy', formatCurrency(stats.sales_total, country), 'text-indigo-700'],
        ['Ventas realizadas', String(stats.sales_count), 'text-slate-950'],
        ['Ticket promedio', formatCurrency(stats.average_ticket, country), 'text-slate-950'],
        ['Productos sin stock', String(stats.out_of_stock_count), 'text-red-600'],
        ['Stock bajo', String(stats.low_stock_count), 'text-amber-600'],
        ['Ventas anuladas hoy', String(stats.cancelled_sales_count), 'text-red-600'],
        [
            'Caja actual',
            stats.cash_register_status === 'open'
                ? `Abierta · ${formatCurrency(stats.cash_register_expected ?? 0, country)}`
                : 'Cerrada',
            stats.cash_register_status === 'open' ? 'text-emerald-700' : 'text-slate-950',
        ],
        ['Última venta', lastSaleTime, 'text-slate-950'],
    ];

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold text-slate-950">Dashboard</h2>}
        >
            <Head title="Dashboard" />

            <div className="py-5">
                <div className="mx-auto max-w-[1800px] space-y-5 px-5 sm:px-6">
                    <section className="rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h1 className="text-xl font-semibold text-slate-950">Resumen de hoy</h1>
                                <p className="mt-1 text-sm text-slate-500">
                                    Visión rápida de ventas, stock y margen estimado.
                                </p>
                            </div>
                            <div className="flex gap-2">
                                <Link
                                    href={route('reports.sales')}
                                    className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:bg-indigo-700 active:scale-[0.98]"
                                >
                                    Ver ventas
                                </Link>
                                <Link
                                    href={route('reports.low-stock')}
                                    className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:bg-slate-50 active:scale-[0.98]"
                                >
                                    Stock bajo
                                </Link>
                            </div>
                        </div>
                    </section>

                    <section className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_420px]">
                        <div className="rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                            <div className="mb-5 flex items-center justify-between gap-3">
                                <div>
                                    <h3 className="text-lg font-semibold text-slate-950">Métricas del día</h3>
                                    <p className="mt-1 text-sm text-slate-500">
                                        Cálculo según la zona horaria del negocio.
                                    </p>
                                </div>
                                <span className="rounded-full border border-indigo-100 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700">
                                    Hoy
                                </span>
                            </div>

                            <div className="divide-y divide-slate-100">
                                {metricRows.map(([label, value, valueClass]) => (
                                    <div key={label} className="flex items-center justify-between gap-4 py-3">
                                        <span className="text-sm font-medium text-slate-600">{label}</span>
                                        <span className={`whitespace-nowrap text-right text-base font-bold ${valueClass}`}>
                                            {value}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </div>

                        <div className="rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                            <div className="mb-4 h-2 w-2 rounded-full bg-emerald-500 shadow-md shadow-emerald-200" />
                            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                Margen estimado de hoy
                            </div>
                            <div className="mt-3 truncate whitespace-nowrap text-3xl font-bold text-slate-950">
                                {formatCurrency(stats.estimated_margin, country)}
                            </div>

                            <div className="mt-6 border-t border-slate-100 pt-5">
                                <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    Top producto del día
                                </div>
                                <div className="mt-2 line-clamp-2 text-lg font-semibold text-slate-950">
                                    {stats.top_product ?? 'Sin ventas'}
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
