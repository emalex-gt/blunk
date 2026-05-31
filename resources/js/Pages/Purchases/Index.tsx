import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatCurrency } from '@/utils/currency';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { FormEvent, ReactNode, useState } from 'react';

type Purchase = {
    id: number;
    business_number: number | null;
    created_at: string;
    total: string;
    paid_from_cash: boolean;
    payment_method?: string | null;
    status?: string | null;
    supplier: { id: number; name: string } | null;
    created_by: { id: number; name: string } | null;
    branch?: { id: number; name: string } | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type Paginated<T> = {
    data: T[];
    links: PaginationLink[];
};

type Filters = Record<string, string | null>;

export default function Index({ purchases, filters = {} }: { purchases: Paginated<Purchase>; filters?: Filters }) {
    const business = usePage().props.business as { country?: string | null } | null;
    const country = business?.country ?? 'GT';
    const [form, setForm] = useState<Record<string, string>>(() => Object.fromEntries(Object.entries(filters).map(([key, value]) => [key, value ?? ''])));

    function setField(key: string, value: string) {
        setForm((current) => ({ ...current, [key]: value }));
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        router.get(route('purchases.index'), cleanForm(form), { preserveScroll: true, preserveState: true });
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-950">Compras</h2>}>
            <Head title="Historial de compras" />

            <div className="py-5">
                <div className="mx-auto max-w-[1800px] space-y-5 px-5 sm:px-6">
                    <section className="rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h1 className="text-xl font-semibold text-slate-950">Historial de compras</h1>
                                <p className="mt-1 text-sm text-slate-500">
                                    Revisa compras registradas y costos promedio.
                                </p>
                            </div>
                            <Link
                                href={route('purchases.create')}
                                className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
                            >
                                Registrar compra
                            </Link>
                        </div>
                    </section>

                    <section className="rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <h2 className="text-sm font-semibold text-slate-900">Filtros</h2>
                            <div className="flex gap-2">
                                <a href={route('purchases.export', { format: 'excel', ...cleanForm(form) })} className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Exportar Excel</a>
                                <a href={route('purchases.export', { format: 'pdf', ...cleanForm(form) })} className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Exportar PDF</a>
                            </div>
                        </div>
                        <form onSubmit={submit} className="grid gap-3 md:grid-cols-4">
                            <Field label="Desde" type="date" value={form.date_from ?? ''} onChange={(value) => setField('date_from', value)} />
                            <Field label="Hasta" type="date" value={form.date_to ?? ''} onChange={(value) => setField('date_to', value)} />
                            <Field label="Proveedor" value={form.supplier_search ?? ''} onChange={(value) => setField('supplier_search', value)} />
                            <Field label="No. compra" value={form.purchase_number ?? ''} onChange={(value) => setField('purchase_number', value)} />
                            <Field label="Producto" value={form.product_search ?? ''} onChange={(value) => setField('product_search', value)} />
                            <Select label="Forma de pago" value={form.payment_method ?? 'all'} onChange={(value) => setField('payment_method', value)}>
                                <option value="all">Todas</option>
                                <option value="cash">Efectivo</option>
                                <option value="card">Tarjeta</option>
                                <option value="bank_transfer">Transferencia</option>
                                <option value="check">Cheque</option>
                                <option value="credit">Crédito</option>
                                <option value="other">Otro</option>
                            </Select>
                            <Select label="Desde caja" value={form.paid_from_cash_register ?? 'all'} onChange={(value) => setField('paid_from_cash_register', value)}>
                                <option value="all">Todos</option>
                                <option value="yes">Sí</option>
                                <option value="no">No</option>
                            </Select>
                            <Select label="Estado" value={form.status ?? 'all'} onChange={(value) => setField('status', value)}>
                                <option value="all">Todos</option>
                                <option value="pending">Pendiente</option>
                                <option value="completed">Completado</option>
                                <option value="cancelled">Anulado</option>
                            </Select>
                            <div className="flex items-end gap-2">
                                <button type="submit" className="h-10 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Aplicar</button>
                                <Link href={route('purchases.index')} className="flex h-10 items-center rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">Limpiar</Link>
                            </div>
                        </form>
                    </section>

                    <section className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white/95 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead>
                                    <tr className="bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        <th className="px-4 py-3">Fecha</th>
                                        <th className="px-4 py-3">Compra</th>
                                        <th className="px-4 py-3">Proveedor</th>
                                        <th className="px-4 py-3">Usuario</th>
                                        <th className="px-4 py-3">Forma de pago</th>
                                        <th className="px-4 py-3">Caja</th>
                                        <th className="px-4 py-3 text-right">Total</th>
                                        <th className="px-4 py-3 text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {purchases.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={7} className="px-4 py-12 text-center text-slate-500">
                                                Sin compras registradas
                                            </td>
                                        </tr>
                                    ) : (
                                        purchases.data.map((purchase) => (
                                            <tr key={purchase.id} className="transition-colors hover:bg-indigo-50/30">
                                                <td className="px-4 py-3 text-slate-600">
                                                    {formatDate(purchase.created_at)}
                                                </td>
                                                <td className="px-4 py-3 font-semibold text-slate-950">
                                                    {formatPurchaseNumber(purchase)}
                                                </td>
                                                <td className="px-4 py-3 font-semibold text-slate-950">
                                                    {purchase.supplier?.name ?? 'Sin proveedor'}
                                                </td>
                                                <td className="px-4 py-3 text-slate-600">
                                                    {purchase.created_by?.name ?? '-'}
                                                </td>
                                                <td className="px-4 py-3 text-slate-600">
                                                    {paymentMethodLabel(purchase.payment_method)}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {purchase.paid_from_cash ? (
                                                        <span className="rounded-full border border-indigo-100 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
                                                            Desde caja
                                                        </span>
                                                    ) : (
                                                        <span className="text-slate-400">-</span>
                                                    )}
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-950">
                                                    {formatCurrency(purchase.total, country)}
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <Link
                                                        href={route('purchases.show', purchase.id)}
                                                        className="rounded-lg px-3 py-1.5 text-sm font-semibold text-indigo-600 hover:bg-indigo-50"
                                                    >
                                                        Ver
                                                    </Link>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <div className="flex flex-wrap justify-end gap-1 border-t border-slate-100 px-4 py-3">
                            {purchases.links?.map((link, index) => (
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
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function formatDate(value: string) {
    return new Intl.DateTimeFormat('es', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}

function formatPurchaseNumber(purchase: Purchase) {
    return `C-${purchase.business_number ?? purchase.id}`;
}

function cleanForm(form: Record<string, string>): Record<string, string> {
    return Object.fromEntries(Object.entries(form).filter(([, value]) => value !== '' && value !== 'all'));
}

function Field({ label, value, onChange, type = 'text' }: { label: string; value: string; onChange: (value: string) => void; type?: string }) {
    return (
        <label className="text-xs font-semibold text-slate-600">
            {label}
            <input type={type} value={value} onChange={(event) => onChange(event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
        </label>
    );
}

function Select({ label, value, onChange, children }: { label: string; value: string; onChange: (value: string) => void; children: ReactNode }) {
    return (
        <label className="text-xs font-semibold text-slate-600">
            {label}
            <select value={value} onChange={(event) => onChange(event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                {children}
            </select>
        </label>
    );
}

function paymentMethodLabel(method?: string | null) {
    return {
        cash: 'Efectivo',
        card: 'Tarjeta',
        bank_transfer: 'Transferencia',
        check: 'Cheque',
        credit: 'Crédito',
        other: 'Otro',
    }[method ?? ''] ?? '-';
}
