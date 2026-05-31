import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatCurrency } from '@/utils/currency';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ReactNode } from 'react';
import { FormEvent, useMemo, useState } from 'react';

type Column = {
    key: string;
    label: string;
    type?: 'money' | 'number' | 'link';
    link_label?: string;
};

type SummaryItem = {
    label: string;
    value: number | string | null;
    money?: boolean;
    hidden?: boolean;
};

type Option = {
    id: number;
    name: string;
    doc_number?: string | null;
};

type Branch = {
    id: number;
    name: string;
    code?: string | null;
};

type Paginator<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    from: number | null;
    to: number | null;
    total: number;
};

type Props = {
    title: string;
    routeName: string;
    columns: Column[];
    rows: Paginator<Record<string, unknown>>;
    summary?: SummaryItem[];
    filters?: Record<string, string | number | null>;
    categories?: Option[];
    customers?: Option[];
    branch?: Branch | null;
    maxRangeLabel?: string;
};

const paymentMethods = [
    { value: 'all', label: 'Todas' },
    { value: 'cash', label: 'Efectivo' },
    { value: 'card', label: 'Tarjeta' },
    { value: 'bank_transfer', label: 'Transferencia' },
    { value: 'check', label: 'Cheque' },
    { value: 'credit', label: 'Crédito' },
    { value: 'other', label: 'Otro' },
];

export default function GenericReport({
    title,
    routeName,
    columns,
    rows,
    summary = [],
    filters = {},
    categories = [],
    customers = [],
    branch = null,
    maxRangeLabel = 'Rango máximo: 3 meses',
}: Props) {
    const business = usePage().props.business as { country?: string | null } | null;
    const country = business?.country ?? 'GT';
    const [form, setForm] = useState<Record<string, string>>(() => stringifyFilters(filters));
    const visibleSummary = summary.filter((item) => !item.hidden);
    const data = rows?.data ?? [];

    const filterKeys = useMemo(() => Object.keys(filters), [filters]);

    function setField(key: string, value: string) {
        setForm((current) => ({ ...current, [key]: value }));
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        router.get(route(routeName), cleanForm(form), {
            preserveScroll: true,
            preserveState: true,
        });
    }

    function clear() {
        router.get(route(routeName), {}, { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-950">{title}</h2>}>
            <Head title={title} />

            <div className="py-5">
                <div className="mx-auto max-w-[1800px] space-y-5 px-5 sm:px-6">
                    <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h3 className="text-sm font-semibold text-slate-900">Filtros</h3>
                                <p className="text-xs text-slate-500">
                                    {branch ? `Sucursal: ${branch.name}. ` : ''}
                                    {maxRangeLabel}
                                </p>
                            </div>
                        </div>

                        <form onSubmit={submit} className="grid gap-3 md:grid-cols-4">
                            {filterKeys.includes('date') && (
                                <DateField label="Fecha" value={form.date ?? ''} onChange={(value) => setField('date', value)} />
                            )}
                            {filterKeys.includes('date_from') && (
                                <DateField label="Desde" value={form.date_from ?? ''} onChange={(value) => setField('date_from', value)} />
                            )}
                            {filterKeys.includes('date_to') && (
                                <DateField label="Hasta" value={form.date_to ?? ''} onChange={(value) => setField('date_to', value)} />
                            )}
                            {filterKeys.includes('payment_method') && (
                                <SelectField label="Forma de pago" value={form.payment_method ?? 'all'} onChange={(value) => setField('payment_method', value)}>
                                    {paymentMethods.map((method) => (
                                        <option key={method.value} value={method.value}>
                                            {method.label}
                                        </option>
                                    ))}
                                </SelectField>
                            )}
                            {filterKeys.includes('category_id') && (
                                <SelectField label="Categoría" value={form.category_id ?? ''} onChange={(value) => setField('category_id', value)}>
                                    <option value="">Todas</option>
                                    {categories.map((category) => (
                                        <option key={category.id} value={category.id}>
                                            {category.name}
                                        </option>
                                    ))}
                                </SelectField>
                            )}
                            {filterKeys.includes('customer_id') && (
                                <SelectField label="Cliente" value={form.customer_id ?? ''} onChange={(value) => setField('customer_id', value)}>
                                    <option value="">Todos</option>
                                    {customers.map((customer) => (
                                        <option key={customer.id} value={customer.id}>
                                            {customer.name}{customer.doc_number ? ` - ${customer.doc_number}` : ''}
                                        </option>
                                    ))}
                                </SelectField>
                            )}
                            {filterKeys.includes('customer_search') && (
                                <TextField label="Buscar cliente / NIT" value={form.customer_search ?? ''} onChange={(value) => setField('customer_search', value)} />
                            )}
                            {filterKeys.includes('product_name') && (
                                <TextField label="Producto" value={form.product_name ?? ''} onChange={(value) => setField('product_name', value)} />
                            )}
                            {filterKeys.includes('product_code') && (
                                <TextField label="Código/SKU" value={form.product_code ?? ''} onChange={(value) => setField('product_code', value)} />
                            )}
                            {filterKeys.includes('product_search') && (
                                <TextField label="Producto" value={form.product_search ?? ''} onChange={(value) => setField('product_search', value)} />
                            )}
                            {filterKeys.includes('search') && (
                                <TextField label="Buscar" value={form.search ?? ''} onChange={(value) => setField('search', value)} />
                            )}

                            <div className="flex items-end gap-2">
                                <button
                                    type="submit"
                                    className="h-10 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700"
                                >
                                    Aplicar
                                </button>
                                <button
                                    type="button"
                                    onClick={clear}
                                    className="h-10 rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                                >
                                    Limpiar
                                </button>
                            </div>
                        </form>
                    </section>

                    {visibleSummary.length > 0 && (
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            {visibleSummary.map((item) => (
                                <div key={item.label} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                                    <div className="text-xs font-semibold uppercase text-slate-500">{item.label}</div>
                                    <div className="mt-2 text-2xl font-semibold text-slate-950">
                                        {item.money ? formatCurrency(item.value, country) : formatValue(item.value)}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}

                    <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead>
                                    <tr className="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                                        {columns.map((column) => (
                                            <th key={column.key} className="px-4 py-3">
                                                {column.label}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {data.length === 0 ? (
                                        <tr>
                                            <td colSpan={columns.length} className="px-4 py-10 text-center text-sm text-slate-500">
                                                No hay datos para los filtros seleccionados.
                                            </td>
                                        </tr>
                                    ) : (
                                        data.map((row, index) => (
                                            <tr key={index} className="hover:bg-slate-50/70">
                                                {columns.map((column) => (
                                                    <td key={column.key} className="whitespace-nowrap px-4 py-3 text-slate-700">
                                                        {renderCell(row[column.key], column, country)}
                                                    </td>
                                                ))}
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>

                        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 text-sm text-slate-500">
                            <span>
                                {rows.from && rows.to ? `${rows.from}-${rows.to} de ${rows.total}` : `${rows.total} resultados`}
                            </span>
                            <div className="flex flex-wrap gap-1">
                                {rows.links?.map((link, index) => (
                                    <Link
                                        key={`${link.label}-${index}`}
                                        href={link.url ?? '#'}
                                        preserveScroll
                                        preserveState
                                        className={[
                                            'rounded-md px-3 py-1 text-sm',
                                            link.active ? 'bg-indigo-600 text-white' : 'border border-slate-200 text-slate-600',
                                            !link.url ? 'pointer-events-none opacity-50' : 'hover:bg-slate-50',
                                        ].join(' ')}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function stringifyFilters(filters: Record<string, string | number | null>): Record<string, string> {
    return Object.fromEntries(
        Object.entries(filters).map(([key, value]) => [key, value === null || value === undefined ? '' : String(value)]),
    );
}

function cleanForm(form: Record<string, string>): Record<string, string> {
    return Object.fromEntries(Object.entries(form).filter(([, value]) => value !== ''));
}

function renderCell(value: unknown, column: Column, country: string): ReactNode {
    if (column.type === 'link') {
        return value ? (
            <Link href={String(value)} className="font-semibold text-indigo-600 hover:text-indigo-800">
                {column.link_label ?? 'Ver'}
            </Link>
        ) : '-';
    }

    return formatCell(value, column, country);
}

function formatCell(value: unknown, column: Column, country: string): string {
    if (column.type === 'money') {
        return formatCurrency(value as number | string | null, country);
    }

    if (column.type === 'number') {
        return Number(value ?? 0).toLocaleString('en-US', { maximumFractionDigits: 2 });
    }

    return formatValue(value);
}

function formatValue(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    return String(value);
}

function DateField({ label, value, onChange }: { label: string; value: string; onChange: (value: string) => void }) {
    return (
        <label className="block">
            <span className="text-xs font-semibold text-slate-500">{label}</span>
            <input
                type="date"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="mt-1 h-10 w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100"
            />
        </label>
    );
}

function TextField({ label, value, onChange }: { label: string; value: string; onChange: (value: string) => void }) {
    return (
        <label className="block">
            <span className="text-xs font-semibold text-slate-500">{label}</span>
            <input
                type="text"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="mt-1 h-10 w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100"
            />
        </label>
    );
}

function SelectField({
    label,
    value,
    onChange,
    children,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    children: ReactNode;
}) {
    return (
        <label className="block">
            <span className="text-xs font-semibold text-slate-500">{label}</span>
            <select
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="mt-1 h-10 w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100"
            >
                {children}
            </select>
        </label>
    );
}
