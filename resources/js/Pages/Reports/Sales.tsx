import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatCurrency } from '@/utils/currency';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { FormEvent, ReactNode, useState } from 'react';

type SaleRow = {
    id: number;
    business_number: number | null;
    display_number: string;
    created_at: string;
    status: 'completed' | 'cancelled';
    cancelled_at: string | null;
    cancelled_by: string | null;
    cancellation_reason: string | null;
    payment_method: string;
    items_count: number;
    total: number;
    estimated_margin: number;
};

type Summary = {
    sales_total: number;
    sales_count: number;
    items_count: number;
    estimated_margin: number;
    cancelled_total: number;
    cancelled_count: number;
    cancelled_items_count: number;
};

type SummaryTone = 'default' | 'success' | 'danger';
type BranchOption = { id: number; name: string; code: string | null };

const paymentLabels: Record<string, string> = {
    cash: 'Efectivo',
    card: 'Tarjeta',
    bizum: 'Bizum',
    transfer: 'Transferencia',
    check: 'Cheque',
    mercadopago: 'MercadoPago',
    other: 'Otro',
};

const statusLabels: Record<string, string> = {
    completed: 'Completada',
    cancelled: 'Anulada',
};

export default function Sales({
    filters,
    summary,
    sales,
    branches_enabled = false,
    branches = [],
}: {
    filters: { start_date: string; end_date: string; status: string; branch_id?: number | null };
    summary: Summary;
    sales: SaleRow[];
    branches_enabled?: boolean;
    branches?: BranchOption[];
}) {
    const business = usePage().props.business as { country?: string | null } | null;
    const country = business?.country ?? 'GT';
    const [startDate, setStartDate] = useState(filters.start_date);
    const [endDate, setEndDate] = useState(filters.end_date);
    const [status, setStatus] = useState(filters.status ?? 'completed');
    const [branchId, setBranchId] = useState<string>(filters.branch_id ? String(filters.branch_id) : '');
    const summaryCards = getSummaryCards(summary, filters.status ?? 'completed', country);

    function submit(event: FormEvent) {
        event.preventDefault();
        router.get(
            route('reports.sales'),
            { start_date: startDate, end_date: endDate, status, branch_id: branchId || undefined },
            { preserveState: true, preserveScroll: true },
        );
    }

    function clear() {
        router.get(route('reports.sales'), {}, { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-950">Ventas</h2>}>
            <Head title="Reporte de ventas" />

            <div className="py-5">
                <div className="mx-auto max-w-[1800px] space-y-5 px-5 sm:px-6">
                    <FilterCard onSubmit={submit} onClear={clear}>
                        <DateField label="Desde" value={startDate} onChange={setStartDate} />
                        <DateField label="Hasta" value={endDate} onChange={setEndDate} />
                        <StatusField value={status} onChange={setStatus} />
                        {branches_enabled && <BranchField value={branchId} onChange={setBranchId} branches={branches} />}
                    </FilterCard>

                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {summaryCards.map((card) => (
                            <SummaryCard key={card.label} label={card.label} value={card.value} tone={card.tone} />
                        ))}
                    </div>

                    <section className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white/95 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="mb-4">
                            <h3 className="px-5 pt-5 text-xl font-semibold text-slate-950">Detalle de ventas</h3>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead>
                                    <tr className="bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        <th className="px-4 py-3">Fecha</th>
                                        <th className="px-4 py-3">Venta</th>
                                        <th className="px-4 py-3">Estado</th>
                                        <th className="px-4 py-3">Método de pago</th>
                                        <th className="px-4 py-3">Artículos</th>
                                        <th className="px-4 py-3 text-right">Total</th>
                                        <th className="px-4 py-3 text-right">Margen estimado</th>
                                        <th className="px-4 py-3 text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {sales.length === 0 ? (
                                        <tr>
                                            <td colSpan={8} className="px-4 py-12 text-center text-slate-500">
                                                Sin resultados
                                            </td>
                                        </tr>
                                    ) : (
                                        sales.map((sale) => (
                                            <tr
                                                key={sale.id}
                                                className={[
                                                    'transition-colors hover:bg-indigo-50/30',
                                                    sale.status === 'cancelled' ? 'bg-red-50/30' : '',
                                                ].join(' ')}
                                            >
                                                <td className="px-4 py-3 text-slate-600">{sale.created_at}</td>
                                                <td className="px-4 py-3 font-semibold text-slate-950">{sale.display_number}</td>
                                                <td className="px-4 py-3">
                                                    <StatusBadge status={sale.status} />
                                                    {sale.status === 'cancelled' && (
                                                        <div className="mt-2 max-w-xs space-y-1 text-xs text-slate-500">
                                                            {sale.cancellation_reason && (
                                                                <div>
                                                                    <span className="font-semibold text-slate-600">Motivo:</span>{' '}
                                                                    {sale.cancellation_reason}
                                                                </div>
                                                            )}
                                                            {sale.cancelled_at && (
                                                                <div>
                                                                    <span className="font-semibold text-slate-600">Fecha de anulación:</span>{' '}
                                                                    {sale.cancelled_at}
                                                                </div>
                                                            )}
                                                            {sale.cancelled_by && (
                                                                <div>
                                                                    <span className="font-semibold text-slate-600">Anulada por:</span>{' '}
                                                                    {sale.cancelled_by}
                                                                </div>
                                                            )}
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-slate-600">
                                                    {paymentLabels[sale.payment_method] ?? sale.payment_method}
                                                </td>
                                                <td className="px-4 py-3 text-slate-600">{sale.items_count}</td>
                                                <td className="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-950">
                                                    {formatCurrency(sale.total, country)}
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-3 text-right text-slate-700">
                                                    {formatCurrency(sale.estimated_margin, country)}
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <Link
                                                        href={route('sales.show', sale.id)}
                                                        className="inline-flex rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-indigo-50 hover:text-indigo-700"
                                                    >
                                                        Ver detalle
                                                    </Link>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function FilterCard({ children, onSubmit, onClear }: { children: ReactNode; onSubmit: (event: FormEvent) => void; onClear: () => void }) {
    return (
        <form onSubmit={onSubmit} className="rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
            <div className="grid gap-4 md:grid-cols-[1fr_1fr_1fr_auto]">
                {children}
                <div className="flex items-end gap-2">
                    <button type="submit" className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        Filtrar
                    </button>
                    <button type="button" onClick={onClear} className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition-all duration-200 hover:bg-slate-50">
                        Limpiar
                    </button>
                </div>
            </div>
        </form>
    );
}

function DateField({ label, value, onChange }: { label: string; value: string; onChange: (value: string) => void }) {
    return (
        <label className="block">
            <span className="text-sm font-medium text-slate-700">{label}</span>
            <input type="date" value={value} onChange={(e) => onChange(e.target.value)} className="mt-1 w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100" />
        </label>
    );
}

function StatusField({ value, onChange }: { value: string; onChange: (value: string) => void }) {
    return (
        <label className="block">
            <span className="text-sm font-medium text-slate-700">Estado</span>
            <select value={value} onChange={(e) => onChange(e.target.value)} className="mt-1 w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100">
                <option value="completed">Completadas</option>
                <option value="cancelled">Anuladas</option>
                <option value="all">Todas</option>
            </select>
        </label>
    );
}

function BranchField({ value, onChange, branches }: { value: string; onChange: (value: string) => void; branches: BranchOption[] }) {
    return (
        <label className="block">
            <span className="text-sm font-medium text-slate-700">Sucursal</span>
            <select value={value} onChange={(e) => onChange(e.target.value)} className="mt-1 w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100">
                <option value="">Todas</option>
                {branches.map((branch) => (
                    <option key={branch.id} value={branch.id}>
                        {branch.name}
                    </option>
                ))}
            </select>
        </label>
    );
}

function StatusBadge({ status }: { status: string }) {
    const cancelled = status === 'cancelled';

    return (
        <span className={[
            'inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold',
            cancelled
                ? 'border-red-100 bg-red-50 text-red-700'
                : 'border-emerald-100 bg-emerald-50 text-emerald-700',
        ].join(' ')}>
            {statusLabels[status] ?? status}
        </span>
    );
}

function getSummaryCards(summary: Summary, status: string, country: string): { label: string; value: string; tone: SummaryTone }[] {
    if (status === 'cancelled') {
        return [
            { label: 'Total anulado', value: formatCurrency(summary.cancelled_total, country), tone: 'danger' },
            { label: 'Ventas anuladas', value: String(summary.cancelled_count), tone: 'danger' },
            { label: 'Productos anulados', value: String(summary.cancelled_items_count), tone: 'danger' },
        ];
    }

    if (status === 'all') {
        return [
            { label: 'Total vendido', value: formatCurrency(summary.sales_total, country), tone: 'default' },
            { label: 'Ventas completadas', value: String(summary.sales_count), tone: 'default' },
            { label: 'Total anulado', value: formatCurrency(summary.cancelled_total, country), tone: 'danger' },
            { label: 'Ventas anuladas', value: String(summary.cancelled_count), tone: 'danger' },
            { label: 'Margen estimado', value: formatCurrency(summary.estimated_margin, country), tone: 'success' },
        ];
    }

    return [
        { label: 'Total vendido', value: formatCurrency(summary.sales_total, country), tone: 'default' },
        { label: 'Ventas', value: String(summary.sales_count), tone: 'default' },
        { label: 'Productos vendidos', value: String(summary.items_count), tone: 'default' },
        { label: 'Margen estimado', value: formatCurrency(summary.estimated_margin, country), tone: 'success' },
    ];
}

function SummaryCard({ label, value, tone = 'default' }: { label: string; value: string; tone?: SummaryTone }) {
    const dotClass = {
        default: 'bg-indigo-500 shadow-indigo-200',
        success: 'bg-emerald-500 shadow-emerald-200',
        danger: 'bg-red-500 shadow-red-200',
    }[tone] ?? 'bg-indigo-500 shadow-indigo-200';

    return (
        <div className="rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
            <div className={`mb-4 h-2 w-2 rounded-full shadow-md ${dotClass}`} />
            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-3 whitespace-nowrap text-2xl font-bold text-slate-950">{value}</div>
        </div>
    );
}
