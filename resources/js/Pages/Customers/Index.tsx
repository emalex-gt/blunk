import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Customer = {
    id: number;
    name: string;
    commercial_name: string | null;
    contact_name: string | null;
    doc_type: string | null;
    doc_number: string | null;
    phone: string | null;
    address: string | null;
    department: string | null;
    municipality: string | null;
    fiscal_status: string;
    is_cf: boolean;
    has_real_nit: boolean;
    name_locked: boolean;
    tax_lookup_verified_at: string | null;
};

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    from: number | null;
    to: number | null;
    total: number;
};

type Filters = {
    search?: string;
    fiscal_status?: string;
    per_page?: number;
};

const fiscalStatusClass = (customer: Customer) => {
    if (customer.is_cf) {
        return 'bg-slate-100 text-slate-700';
    }

    if (customer.tax_lookup_verified_at) {
        return 'bg-emerald-50 text-emerald-700';
    }

    if (customer.has_real_nit) {
        return 'bg-amber-50 text-amber-700';
    }

    return 'bg-slate-100 text-slate-600';
};

export default function Index({ customers, filters }: { customers: Paginated<Customer>; filters: Filters }) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [fiscalStatus, setFiscalStatus] = useState(filters.fiscal_status ?? '');
    const [perPage, setPerPage] = useState(String(filters.per_page ?? 25));

    const submit = (event: FormEvent) => {
        event.preventDefault();

        router.get(
            route('customers.index'),
            { search, fiscal_status: fiscalStatus, per_page: perPage },
            { preserveState: true, replace: true },
        );
    };

    const clearFilters = () => {
        setSearch('');
        setFiscalStatus('');
        setPerPage('25');
        router.get(route('customers.index'), {}, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-950">Clientes</h2>}>
            <Head title="Clientes" />

            <div className="mx-auto max-w-7xl space-y-5 px-4 py-5 sm:px-6">
                <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="mb-4">
                        <h1 className="text-2xl font-semibold text-slate-950">Clientes</h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Consulta y administra datos comerciales sin editar manualmente los datos fiscales bloqueados por NIT.
                        </p>
                    </div>

                    <form onSubmit={submit} className="grid gap-3 md:grid-cols-[minmax(0,1fr)_220px_140px_auto_auto]">
                        <label className="block text-sm font-medium text-slate-700">
                            Buscar
                            <input
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Nombre, NIT, contacto, teléfono o ubicación"
                                className="mt-1 block w-full rounded-xl border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100"
                            />
                        </label>
                        <label className="block text-sm font-medium text-slate-700">
                            Estado fiscal
                            <select
                                value={fiscalStatus}
                                onChange={(event) => setFiscalStatus(event.target.value)}
                                className="mt-1 block w-full rounded-xl border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100"
                            >
                                <option value="">Todos</option>
                                <option value="cf">CF</option>
                                <option value="validated">NIT validado</option>
                                <option value="pending">NIT sin validar</option>
                                <option value="locked">Datos fiscales bloqueados</option>
                            </select>
                        </label>
                        <label className="block text-sm font-medium text-slate-700">
                            Por página
                            <select
                                value={perPage}
                                onChange={(event) => setPerPage(event.target.value)}
                                className="mt-1 block w-full rounded-xl border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100"
                            >
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </label>
                        <div className="flex items-end">
                            <button className="w-full rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white">
                                Buscar
                            </button>
                        </div>
                        <div className="flex items-end">
                            <button
                                type="button"
                                onClick={clearFilters}
                                className="w-full rounded-xl bg-slate-100 px-4 py-2.5 text-sm font-semibold text-slate-700"
                            >
                                Limpiar
                            </button>
                        </div>
                    </form>
                </div>

                <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div className="border-b border-slate-100 px-5 py-4 text-sm text-slate-500">
                        {customers.total > 0 ? (
                            <>Mostrando {customers.from} - {customers.to} de {customers.total} clientes</>
                        ) : (
                            <>No se encontraron clientes.</>
                        )}
                    </div>

                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-100 text-sm">
                            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                                <tr>
                                    <th className="px-4 py-3">Cliente</th>
                                    <th className="px-4 py-3">NIT / CF</th>
                                    <th className="px-4 py-3">Nombre comercial</th>
                                    <th className="px-4 py-3">Contacto</th>
                                    <th className="px-4 py-3">Teléfono</th>
                                    <th className="px-4 py-3">Ubicación</th>
                                    <th className="px-4 py-3">Estado fiscal</th>
                                    <th className="w-[116px] min-w-[116px] px-4 py-3 text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {customers.data.map((customer) => (
                                    <tr key={customer.id}>
                                        <td className="px-4 py-3">
                                            <div className="font-semibold text-slate-950">{customer.name}</div>
                                            {customer.name_locked && (
                                                <div className="mt-0.5 text-xs font-medium text-slate-500">Nombre fiscal bloqueado</div>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-slate-700">{customer.doc_number || 'CF'}</td>
                                        <td className="px-4 py-3 text-slate-700">{customer.commercial_name || '-'}</td>
                                        <td className="px-4 py-3 text-slate-700">{customer.contact_name || '-'}</td>
                                        <td className="px-4 py-3 text-slate-700">{customer.phone || '-'}</td>
                                        <td className="px-4 py-3 text-slate-700">
                                            {[customer.department, customer.municipality].filter(Boolean).join(', ') || '-'}
                                            {customer.address && <div className="mt-0.5 max-w-64 text-xs text-slate-500">{customer.address}</div>}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className={`rounded-full px-2 py-1 text-xs font-semibold ${fiscalStatusClass(customer)}`}>
                                                {customer.fiscal_status}
                                            </span>
                                        </td>
                                        <td className="w-[116px] min-w-[116px] px-4 py-3 text-right">
                                            <Link
                                                href={route('customers.edit', customer.id)}
                                                className="inline-flex items-center justify-center whitespace-nowrap rounded-lg bg-indigo-50 px-3 py-2 text-sm font-semibold leading-none text-indigo-700 hover:bg-indigo-100"
                                            >
                                                Ver/Editar
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex flex-wrap gap-2 border-t border-slate-100 px-5 py-4">
                        {customers.links.map((link) => (
                            <Link
                                key={link.label}
                                href={link.url ?? '#'}
                                preserveScroll
                                className={`rounded-lg px-3 py-2 text-sm ${link.active ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700'} ${!link.url ? 'pointer-events-none opacity-50' : ''}`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
