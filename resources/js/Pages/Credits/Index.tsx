import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatCurrency } from '@/utils/currency';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type CreditCustomerRow = {
    customer_id: number;
    customer_name: string;
    customer_doc_number: string;
    pending_total: string | number;
    receipts_count: number;
    last_movement_at: string | null;
};

export default function Index({
    customers,
    search = '',
}: {
    customers: CreditCustomerRow[];
    search?: string;
}) {
    const country = (usePage().props.business as { country?: string } | null)?.country ?? 'GT';
    const [query, setQuery] = useState(search);

    function submit(event: FormEvent) {
        event.preventDefault();
        router.get(route('credits.index'), { search: query }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Créditos</h2>}
        >
            <Head title="Créditos" />

            <div className="py-6">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div className="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h1 className="text-lg font-semibold text-slate-950">Clientes con crédito pendiente</h1>
                                <p className="text-sm text-slate-500">Reservas pendientes de facturar.</p>
                            </div>
                            <form onSubmit={submit} className="flex gap-2">
                                <input
                                    value={query}
                                    onChange={(event) => setQuery(event.target.value)}
                                    placeholder="Buscar NIT o nombre"
                                    className="h-10 rounded-lg border-slate-300 text-sm"
                                />
                                <button className="h-10 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white">
                                    Buscar
                                </button>
                            </form>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-sm">
                                <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th className="px-4 py-3">NIT</th>
                                        <th className="px-4 py-3">Nombre</th>
                                        <th className="px-4 py-3 text-right">Total pendiente</th>
                                        <th className="px-4 py-3 text-right">Recibos</th>
                                        <th className="px-4 py-3">Último movimiento</th>
                                        <th className="px-4 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {customers.map((customer) => (
                                        <tr key={customer.customer_id}>
                                            <td className="px-4 py-3 font-semibold text-slate-900">{customer.customer_doc_number}</td>
                                            <td className="px-4 py-3 text-slate-700">{customer.customer_name}</td>
                                            <td className="px-4 py-3 text-right font-semibold text-slate-900">
                                                {formatCurrency(Number(customer.pending_total), country)}
                                            </td>
                                            <td className="px-4 py-3 text-right text-slate-700">{customer.receipts_count}</td>
                                            <td className="px-4 py-3 text-slate-500">
                                                {customer.last_movement_at ? new Date(customer.last_movement_at).toLocaleString() : '-'}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <Link
                                                    href={route('credits.customers.show', customer.customer_id)}
                                                    className="font-semibold text-indigo-600 hover:text-indigo-700"
                                                >
                                                    Ver
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                    {customers.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="px-4 py-10 text-center text-slate-500">
                                                No hay créditos pendientes.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
