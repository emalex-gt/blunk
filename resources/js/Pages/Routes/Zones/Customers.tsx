import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Customer = { id: number; name: string; doc_number: string | null; address: string | null; phone: string | null };
type Assignment = { id: number; customer: Customer; visit_order: number | null; notes: string | null; is_active: boolean };
type Zone = { id: number; name: string; branch?: { name: string }; assigned_user?: { name: string } | null };

export default function Customers({ zone, assignments, availableCustomers, filters }: { zone: Zone; assignments: Assignment[]; availableCustomers: Customer[]; filters: { search?: string } }) {
    const form = useForm({ customer_id: '', visit_order: '', notes: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('routes.zones.customers.store', zone.id), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title={`Clientes ${zone.name}`} />
            <div className="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold text-slate-950">{zone.name}</h1>
                        <p className="text-sm text-slate-500">{zone.branch?.name} · {zone.assigned_user?.name ?? 'Sin vendedor'}</p>
                    </div>
                    <Link href={route('routes.zones.index')} className="rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-slate-200">
                        Volver
                    </Link>
                </div>

                <form className="flex flex-wrap gap-2 rounded-lg border border-slate-200 bg-white p-4" onSubmit={(event) => {
                    event.preventDefault();
                    router.get(route('routes.zones.customers', zone.id), { search: (event.currentTarget.elements.namedItem('search') as HTMLInputElement).value }, { preserveState: true });
                }}>
                    <input name="search" defaultValue={filters.search ?? ''} placeholder="Buscar cliente o NIT" className="min-w-64 flex-1 rounded-lg border-slate-200 text-sm" />
                    <button className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Buscar</button>
                </form>

                <form onSubmit={submit} className="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 md:grid-cols-4">
                    <select value={form.data.customer_id} onChange={(event) => form.setData('customer_id', event.target.value)} className="rounded-lg border-slate-200 text-sm md:col-span-2">
                        <option value="">Seleccionar cliente</option>
                        {availableCustomers.map((customer) => (
                            <option key={customer.id} value={customer.id}>{customer.name} {customer.doc_number ? `· ${customer.doc_number}` : ''}</option>
                        ))}
                    </select>
                    <input value={form.data.visit_order} onChange={(event) => form.setData('visit_order', event.target.value)} placeholder="Orden" className="rounded-lg border-slate-200 text-sm" />
                    <button disabled={form.processing} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Asignar</button>
                    {form.errors.customer_id && <div className="text-sm text-red-600 md:col-span-4">{form.errors.customer_id}</div>}
                </form>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
                            <tr>
                                <th className="px-4 py-3">Orden</th>
                                <th className="px-4 py-3">Cliente</th>
                                <th className="px-4 py-3">Dirección</th>
                                <th className="px-4 py-3">Teléfono</th>
                                <th className="px-4 py-3">Estado</th>
                                <th className="px-4 py-3">Acción</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {assignments.map((assignment) => (
                                <tr key={assignment.id}>
                                    <td className="px-4 py-3">
                                        <input
                                            defaultValue={assignment.visit_order ?? ''}
                                            onBlur={(event) => router.put(route('routes.zones.customers.update', [zone.id, assignment.id]), { visit_order: event.target.value || null, is_active: assignment.is_active }, { preserveScroll: true })}
                                            className="w-20 rounded-lg border-slate-200 text-sm"
                                        />
                                    </td>
                                    <td className="px-4 py-3 font-medium text-slate-900">{assignment.customer.name}<div className="text-xs text-slate-500">{assignment.customer.doc_number ?? '-'}</div></td>
                                    <td className="px-4 py-3">{assignment.customer.address ?? '-'}</td>
                                    <td className="px-4 py-3">{assignment.customer.phone ?? '-'}</td>
                                    <td className="px-4 py-3">{assignment.is_active ? 'Activo' : 'Inactivo'}</td>
                                    <td className="px-4 py-3">
                                        <button onClick={() => router.delete(route('routes.zones.customers.destroy', [zone.id, assignment.id]), { preserveScroll: true })} className="rounded-lg bg-red-50 px-3 py-2 text-sm font-semibold text-red-700">
                                            Remover
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
