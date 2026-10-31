import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatCurrency } from '@/utils/currency';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ReactNode } from 'react';
import { FormEvent, useEffect, useMemo, useState } from 'react';

type Column = {
    key: string;
    label: string;
    type?: 'money' | 'number' | 'link' | 'reservations';
    link_label?: string;
    decimals?: number;
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

type SortOption = {
    value: string;
    label: string;
};

type Branch = {
    id: number;
    name: string;
    code?: string | null;
};

type ProductContext = {
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
    sellers?: Option[];
    suppliers?: Option[];
    sortOptions?: SortOption[];
    fixedFilters?: Record<string, string | number | null>;
    productContext?: ProductContext | null;
    branch?: Branch | null;
    maxRangeLabel?: string;
};

type ReservationRow = {
    reservation_id?: number;
    pre_sale_id?: number;
    credit_receipt_id?: number;
    visit_id?: number | null;
    customer_name?: string | null;
    customer_commercial_name?: string | null;
    route_zone_name?: string | null;
    seller_name?: string | null;
    pre_sale_status?: string | null;
    visit_status?: string | null;
    status?: string | null;
    quantity: number;
    reason?: string;
    created_at?: string;
};

type ReservationExplanation = {
    physical_stock: number;
    reserved_pre_sales: number;
    reserved_credit_reservations: number;
    reserved_other: number;
    reserved_total: number;
    available_stock: number;
    pre_sale_reservations: ReservationRow[];
    credit_reservations: ReservationRow[];
    orphan_or_ignored_reservations: ReservationRow[];
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
    sellers = [],
    suppliers = [],
    sortOptions = [],
    fixedFilters = {},
    productContext = null,
    branch = null,
    maxRangeLabel = 'Rango máximo: 3 meses',
}: Props) {
    const page = usePage();
    const business = page.props.business as { country?: string | null } | null;
    const errors = page.props.errors as Record<string, string | undefined>;
    const country = business?.country ?? 'GT';
    const fixedFilterValues = useMemo(() => stringifyFilters(fixedFilters), [fixedFilters]);
    const [form, setForm] = useState<Record<string, string>>(() => ({ ...stringifyFilters(filters), ...fixedFilterValues }));
    const [reservationExplanation, setReservationExplanation] = useState<ReservationExplanation | null>(null);
    const [reservationLoading, setReservationLoading] = useState(false);
    const [reservationError, setReservationError] = useState<string | null>(null);
    const visibleSummary = summary.filter((item) => !item.hidden);
    const data = rows?.data ?? [];

    const filterKeys = useMemo(() => Object.keys(filters), [filters]);

    function setField(key: string, value: string) {
        setForm((current) => ({ ...current, [key]: value }));
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        router.get(route(routeName), requestFilters(form, fixedFilterValues), {
            preserveScroll: true,
            preserveState: true,
        });
    }

    function clear() {
        setForm(fixedFilterValues);
        router.get(route(routeName), fixedFilterValues, { preserveScroll: true });
    }

    async function openReservationExplanation(row: Record<string, unknown>) {
        const url = row.reservations_url;
        if (!url) {
            return;
        }

        setReservationExplanation(null);
        setReservationError(null);
        setReservationLoading(true);

        try {
            const response = await fetch(String(url), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error('No se pudo consultar el detalle de reservas.');
            }

            setReservationExplanation(await response.json());
        } catch (error) {
            setReservationError(error instanceof Error ? error.message : 'No se pudo consultar el detalle de reservas.');
        } finally {
            setReservationLoading(false);
        }
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-950">{title}</h2>}>
            <Head title={title} />

            <div className="py-5">
                <div className="mx-auto max-w-[1800px] space-y-5 px-5 sm:px-6">
                    {productContext && (
                        <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                            <h3 className="text-base font-semibold text-slate-950">{productContext.name}</h3>
                            {productContext.code && <p className="mt-1 text-sm text-slate-500">Código: {productContext.code}</p>}
                        </section>
                    )}
                    <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h3 className="text-sm font-semibold text-slate-900">Filtros</h3>
                                <p className="text-xs text-slate-500">
                                    {branch ? `Sucursal: ${branch.name}. ` : ''}
                                    {maxRangeLabel}
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <a
                                    href={exportUrl(routeName, 'excel', requestFilters(form, fixedFilterValues))}
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                                >
                                    Exportar Excel
                                </a>
                                <a
                                    href={exportUrl(routeName, 'pdf', requestFilters(form, fixedFilterValues))}
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                                >
                                    Exportar PDF
                                </a>
                            </div>
                        </div>

                        <form onSubmit={submit} className="grid gap-3 md:grid-cols-4">
                            {filterKeys.includes('date') && (
                                <DateField label="Fecha" value={form.date ?? ''} onChange={(value) => setField('date', value)} />
                            )}
                            {filterKeys.includes('date_from') && (
                                <DateField label="Desde" value={form.date_from ?? ''} onChange={(value) => setField('date_from', value)} error={errors.date_from} />
                            )}
                            {filterKeys.includes('date_to') && (
                                <DateField label="Hasta" value={form.date_to ?? ''} onChange={(value) => setField('date_to', value)} error={errors.date_to} />
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
                            {filterKeys.includes('document_type') && (
                                <SelectField label="Tipo documento" value={form.document_type ?? 'all'} onChange={(value) => setField('document_type', value)}>
                                    <option value="all">Todos</option>
                                    <option value="receipt">Comprobante</option>
                                    <option value="invoice">Factura</option>
                                    <option value="credit">Crédito</option>
                                </SelectField>
                            )}
                            {filterKeys.includes('status') && (
                                <SelectField label="Estado" value={form.status ?? 'completed'} onChange={(value) => setField('status', value)}>
                                    <option value="completed">Completadas</option>
                                    <option value="cancelled">Anuladas</option>
                                    <option value="all">Todas</option>
                                </SelectField>
                            )}
                            {filterKeys.includes('seller_id') && (
                                <SelectField label="Vendedor" value={form.seller_id ?? ''} onChange={(value) => setField('seller_id', value)}>
                                    <option value="">Todos</option>
                                    {sellers.map((seller) => (
                                        <option key={seller.id} value={seller.id}>
                                            {seller.name}
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
                            {filterKeys.includes('sale_number') && (
                                <TextField label="No. venta" value={form.sale_number ?? ''} onChange={(value) => setField('sale_number', value)} />
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
                            {filterKeys.includes('supplier_search') && (
                                <TextField label="Proveedor" value={form.supplier_search ?? ''} onChange={(value) => setField('supplier_search', value)} />
                            )}
                            {filterKeys.includes('supplier_id') && (
                                <SearchableSupplierField
                                    suppliers={suppliers}
                                    value={form.supplier_id ?? ''}
                                    onChange={(value) => setField('supplier_id', value)}
                                />
                            )}
                            {filterKeys.includes('only_below_minimum') && (
                                <SelectField label="Stock bajo" value={form.only_below_minimum ?? '1'} onChange={(value) => setField('only_below_minimum', value)}>
                                    <option value="1">Solo bajo mínimo</option>
                                    <option value="0">Todos</option>
                                </SelectField>
                            )}
                            {filterKeys.includes('sort_by') && sortOptions.length > 0 && (
                                <SelectField label="Ordenar por" value={form.sort_by ?? sortOptions[0].value} onChange={(value) => setField('sort_by', value)}>
                                    {sortOptions.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </SelectField>
                            )}
                            {filterKeys.includes('search') && (
                                <TextField label="Buscar" value={form.search ?? ''} onChange={(value) => setField('search', value)} />
                            )}

                            <div className="flex items-end gap-2">
                                <button
                                    type="submit"
                                    className="h-10 rounded-lg bg-orange-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-orange-700"
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
                                                        {renderCell(row, column, country, openReservationExplanation)}
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
                                            link.active ? 'bg-orange-600 text-white' : 'border border-slate-200 text-slate-600',
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

            {(reservationLoading || reservationExplanation || reservationError) && (
                <ReservationModal
                    loading={reservationLoading}
                    explanation={reservationExplanation}
                    error={reservationError}
                    onClose={() => {
                        setReservationExplanation(null);
                        setReservationError(null);
                        setReservationLoading(false);
                    }}
                />
            )}
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

function requestFilters(form: Record<string, string>, fixedFilters: Record<string, string>): Record<string, string> {
    return { ...cleanForm(form), ...fixedFilters };
}

function exportUrl(routeName: string, format: 'excel' | 'pdf', form: Record<string, string>) {
    return route('reports.export', {
        report: routeName.replace(/^reports\./, ''),
        format,
        ...cleanForm(form),
    });
}

function renderCell(
    row: Record<string, unknown>,
    column: Column,
    country: string,
    onExplainReservations: (row: Record<string, unknown>) => void,
): ReactNode {
    const value = row[column.key];

    if (column.type === 'link') {
        return value ? (
            <Link href={String(value)} className="font-semibold text-orange-600 hover:text-orange-800">
                {column.link_label ?? 'Ver'}
            </Link>
        ) : '-';
    }

    if (column.type === 'reservations') {
        const quantity = Number(value ?? 0);

        if (quantity <= 0 || !row.reservations_url) {
            return formatCell(value, { ...column, type: 'number' }, country);
        }

        return (
            <button
                type="button"
                onClick={() => onExplainReservations(row)}
                className="font-semibold text-orange-600 underline-offset-2 hover:text-orange-800 hover:underline"
            >
                {formatCell(value, { ...column, type: 'number' }, country)}
            </button>
        );
    }

    return formatCell(value, column, country);
}

function formatCell(value: unknown, column: Column, country: string): string {
    if (column.type === 'money') {
        if (value === null || value === undefined || value === '') {
            return '-';
        }

        return formatCurrency(value as number | string | null, country, column.decimals ?? 2);
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

function DateField({ label, value, onChange, error }: { label: string; value: string; onChange: (value: string) => void; error?: string }) {
    return (
        <label className="block">
            <span className="text-xs font-semibold text-slate-500">{label}</span>
            <input
                type="date"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                aria-invalid={Boolean(error)}
                className={`mt-1 h-10 w-full rounded-lg text-sm shadow-sm ${error
                    ? 'border-red-300 focus:border-red-500 focus:ring-red-100'
                    : 'border-slate-200 focus:border-orange-400 focus:ring-orange-100'}`}
            />
            {error && <p className="mt-1 text-sm text-red-600">{error}</p>}
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
                className="mt-1 h-10 w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-orange-400 focus:ring-orange-100"
            />
        </label>
    );
}

function SearchableSupplierField({
    suppliers,
    value,
    onChange,
}: {
    suppliers: Option[];
    value: string;
    onChange: (value: string) => void;
}) {
    const selectedSupplier = suppliers.find((supplier) => String(supplier.id) === value);
    const [query, setQuery] = useState(selectedSupplier?.name ?? '');
    const [open, setOpen] = useState(false);
    const matches = useMemo(
        () => suppliers.filter((supplier) => supplier.name.toLocaleLowerCase().includes(query.toLocaleLowerCase())),
        [query, suppliers],
    );

    useEffect(() => {
        setQuery(selectedSupplier?.name ?? '');
    }, [selectedSupplier?.name]);

    return (
        <label className="relative block">
            <span className="text-xs font-semibold text-slate-500">Proveedor</span>
            <input
                type="text"
                value={query}
                role="combobox"
                aria-expanded={open}
                aria-controls="supplier-filter-options"
                placeholder="Todos los proveedores"
                onFocus={() => setOpen(true)}
                onChange={(event) => {
                    setQuery(event.target.value);
                    onChange('');
                    setOpen(true);
                }}
                className="mt-1 h-10 w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-orange-400 focus:ring-orange-100"
            />
            {open && (
                <div id="supplier-filter-options" role="listbox" className="absolute z-20 mt-1 max-h-52 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
                    <button
                        type="button"
                        className="block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50"
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={() => {
                            setQuery('');
                            onChange('');
                            setOpen(false);
                        }}
                    >
                        Todos los proveedores
                    </button>
                    {matches.map((supplier) => (
                        <button
                            key={supplier.id}
                            type="button"
                            role="option"
                            aria-selected={String(supplier.id) === value}
                            className="block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50"
                            onMouseDown={(event) => event.preventDefault()}
                            onClick={() => {
                                setQuery(supplier.name);
                                onChange(String(supplier.id));
                                setOpen(false);
                            }}
                        >
                            {supplier.name}
                        </button>
                    ))}
                    {matches.length === 0 && <p className="px-3 py-2 text-sm text-slate-500">Sin proveedores coincidentes.</p>}
                </div>
            )}
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
                className="mt-1 h-10 w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-orange-400 focus:ring-orange-100"
            >
                {children}
            </select>
        </label>
    );
}

function ReservationModal({
    loading,
    explanation,
    error,
    onClose,
}: {
    loading: boolean;
    explanation: ReservationExplanation | null;
    error: string | null;
    onClose: () => void;
}) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 p-4">
            <div className="max-h-[90vh] w-full max-w-3xl overflow-hidden rounded-xl bg-white shadow-xl">
                <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                    <div>
                        <h3 className="text-base font-semibold text-slate-950">Detalle de reservas</h3>
                        <p className="text-xs text-slate-500">Reservado es cantidad de unidades, no cantidad de preventas.</p>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-lg border border-slate-200 px-3 py-1 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Cerrar
                    </button>
                </div>

                <div className="max-h-[calc(90vh-72px)] overflow-y-auto p-5">
                    {loading && <p className="text-sm text-slate-500">Consultando reservas...</p>}
                    {error && <p className="rounded-lg bg-red-50 p-3 text-sm font-semibold text-red-700">{error}</p>}
                    {explanation && (
                        <div className="space-y-5">
                            <div className="grid gap-3 sm:grid-cols-3">
                                <Metric label="Stock físico" value={explanation.physical_stock} />
                                <Metric label="Reservado total" value={explanation.reserved_total} />
                                <Metric label="Disponible" value={explanation.available_stock} />
                                <Metric label="Preventas" value={explanation.reserved_pre_sales} />
                                <Metric label="Crédito" value={explanation.reserved_credit_reservations} />
                                <Metric label="Otro" value={explanation.reserved_other} />
                            </div>

                            <ReservationSection title="Preventas" rows={explanation.pre_sale_reservations} />
                            <ReservationSection title="Crédito" rows={explanation.credit_reservations} />
                            {explanation.orphan_or_ignored_reservations.length > 0 && (
                                <ReservationSection title="Reservas ignoradas / diagnóstico" rows={explanation.orphan_or_ignored_reservations} showReason />
                            )}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

function Metric({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-lg border border-slate-200 p-3">
            <div className="text-xs font-semibold uppercase text-slate-500">{label}</div>
            <div className="mt-1 text-lg font-semibold text-slate-950">{Number(value ?? 0).toLocaleString('en-US', { maximumFractionDigits: 2 })}</div>
        </div>
    );
}

function ReservationSection({ title, rows, showReason = false }: { title: string; rows: ReservationRow[]; showReason?: boolean }) {
    return (
        <section>
            <h4 className="text-sm font-semibold text-slate-900">{title}</h4>
            {rows.length === 0 ? (
                <p className="mt-2 rounded-lg bg-slate-50 p-3 text-sm text-slate-500">Sin reservas.</p>
            ) : (
                <div className="mt-2 overflow-x-auto rounded-lg border border-slate-200">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                            <tr>
                                <th className="px-3 py-2">Cliente</th>
                                <th className="px-3 py-2">Ruta / documento</th>
                                <th className="px-3 py-2">Estado</th>
                                <th className="px-3 py-2">Cantidad</th>
                                {showReason && <th className="px-3 py-2">Motivo</th>}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.map((row, index) => (
                                <tr key={`${row.reservation_id ?? row.pre_sale_id ?? row.credit_receipt_id ?? index}-${index}`}>
                                    <td className="px-3 py-2 text-slate-700">
                                        {row.customer_commercial_name || row.customer_name || '-'}
                                        {row.customer_commercial_name && row.customer_name && (
                                            <div className="text-xs text-slate-500">{row.customer_name}</div>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-slate-700">
                                        {row.route_zone_name || (row.credit_receipt_id ? `Crédito #${row.credit_receipt_id}` : row.pre_sale_id ? `Preventa #${row.pre_sale_id}` : '-')}
                                    </td>
                                    <td className="px-3 py-2 text-slate-700">{row.pre_sale_status || row.visit_status || row.status || '-'}</td>
                                    <td className="px-3 py-2 text-slate-700">{Number(row.quantity ?? 0).toLocaleString('en-US', { maximumFractionDigits: 2 })}</td>
                                    {showReason && <td className="px-3 py-2 text-slate-700">{row.reason ?? '-'}</td>}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}
