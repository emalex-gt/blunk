import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import GuatemalaLocationSelects from '@/Components/GuatemalaLocationSelects';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Customer = { id: number; name: string; commercial_name: string | null; doc_number: string | null; address: string | null; department: string | null; municipality: string | null; phone: string | null };
type Assignment = { id: number; customer: Customer; visit_order: number | null; notes: string | null; is_active: boolean };
type Zone = { id: number; name: string; branch?: { name: string }; assigned_user?: { name: string } | null };

export default function Customers({ zone, assignments, availableCustomers, filters }: { zone: Zone; assignments: Assignment[]; availableCustomers: Customer[]; filters: { search?: string } }) {
    const form = useForm({ customer_id: '', visit_order: '', notes: '' });
    const [creatingCustomer, setCreatingCustomer] = useState(false);
    const [editingAssignmentId, setEditingAssignmentId] = useState<number | null>(null);
    const customerForm = useForm({
        name: '',
        commercial_name: '',
        doc_number: '',
        phone: '',
        address: '',
        department: '',
        municipality: '',
        notes: '',
    });
    const editCustomerForm = useForm({
        name: '',
        commercial_name: '',
        doc_number: '',
        phone: '',
        address: '',
        department: '',
        municipality: '',
        notes: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('routes.zones.customers.store', zone.id), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const createCustomer = (event: FormEvent) => {
        event.preventDefault();
        customerForm.post(route('routes.zones.customers.create', zone.id), {
            preserveScroll: true,
            onSuccess: () => {
                customerForm.reset();
                setCreatingCustomer(false);
            },
        });
    };

    const startEditCustomer = (assignment: Assignment) => {
        setEditingAssignmentId(assignment.id);
        editCustomerForm.setData({
            name: assignment.customer.name ?? '',
            commercial_name: assignment.customer.commercial_name ?? '',
            doc_number: assignment.customer.doc_number ?? '',
            phone: assignment.customer.phone ?? '',
            address: assignment.customer.address ?? '',
            department: assignment.customer.department ?? '',
            municipality: assignment.customer.municipality ?? '',
            notes: assignment.notes ?? '',
        });
    };

    const updateCustomer = (event: FormEvent, assignment: Assignment) => {
        event.preventDefault();
        editCustomerForm.put(route('routes.zones.customers.details.update', [zone.id, assignment.id]), {
            preserveScroll: true,
            onSuccess: () => setEditingAssignmentId(null),
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
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() => setCreatingCustomer((value) => !value)}
                            className="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm"
                        >
                            Nuevo cliente
                        </button>
                        <Link href={route('routes.zones.index')} className="rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-slate-200">
                            Volver
                        </Link>
                    </div>
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
                            <option key={customer.id} value={customer.id}>{customer.commercial_name || customer.name} {customer.doc_number ? `· ${customer.doc_number}` : ''}</option>
                        ))}
                    </select>
                    <input value={form.data.visit_order} onChange={(event) => form.setData('visit_order', event.target.value)} placeholder="Orden" className="rounded-lg border-slate-200 text-sm" />
                    <button disabled={form.processing} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Asignar</button>
                    {form.errors.customer_id && <div className="text-sm text-red-600 md:col-span-4">{form.errors.customer_id}</div>}
                </form>

                {creatingCustomer && (
                    <form onSubmit={createCustomer} className="rounded-lg border border-indigo-100 bg-white p-4 shadow-sm">
                        <div className="mb-4">
                            <h2 className="text-lg font-semibold text-slate-950">Nuevo cliente</h2>
                            <p className="text-sm text-slate-500">Se creará el cliente y quedará asignado al final de la zona.</p>
                        </div>
                        <div className="grid gap-3 md:grid-cols-2">
                            <label className="text-sm font-medium text-slate-700">
                                Nombre
                                <input
                                    value={customerForm.data.name}
                                    onChange={(event) => customerForm.setData('name', event.target.value)}
                                    className="mt-1 w-full rounded-lg border-slate-200 text-sm"
                                />
                                {customerForm.errors.name && <span className="mt-1 block text-xs text-red-600">{customerForm.errors.name}</span>}
                            </label>
                            <label className="text-sm font-medium text-slate-700">
                                Nombre del negocio
                                <input
                                    value={customerForm.data.commercial_name}
                                    onChange={(event) => customerForm.setData('commercial_name', event.target.value)}
                                    className="mt-1 w-full rounded-lg border-slate-200 text-sm"
                                />
                            </label>
                            <label className="text-sm font-medium text-slate-700">
                                NIT / documento
                                <input
                                    value={customerForm.data.doc_number}
                                    onChange={(event) => customerForm.setData('doc_number', event.target.value)}
                                    placeholder="CF si queda vacío"
                                    className="mt-1 w-full rounded-lg border-slate-200 text-sm"
                                />
                                {customerForm.errors.doc_number && <span className="mt-1 block text-xs text-red-600">{customerForm.errors.doc_number}</span>}
                            </label>
                            <label className="text-sm font-medium text-slate-700">
                                Teléfono
                                <input
                                    value={customerForm.data.phone}
                                    onChange={(event) => customerForm.setData('phone', event.target.value)}
                                    className="mt-1 w-full rounded-lg border-slate-200 text-sm"
                                />
                            </label>
                            <label className="text-sm font-medium text-slate-700">
                                Dirección
                                <input
                                    value={customerForm.data.address}
                                    onChange={(event) => customerForm.setData('address', event.target.value)}
                                    className="mt-1 w-full rounded-lg border-slate-200 text-sm"
                                />
                            </label>
                            <GuatemalaLocationSelects
                                department={customerForm.data.department}
                                municipality={customerForm.data.municipality}
                                onDepartmentChange={(value) => customerForm.setData('department', value)}
                                onMunicipalityChange={(value) => customerForm.setData('municipality', value)}
                                departmentError={customerForm.errors.department}
                                municipalityError={customerForm.errors.municipality}
                                compact
                            />
                            <label className="text-sm font-medium text-slate-700 md:col-span-2">
                                Referencia / notas
                                <textarea
                                    value={customerForm.data.notes}
                                    onChange={(event) => customerForm.setData('notes', event.target.value)}
                                    className="mt-1 w-full rounded-lg border-slate-200 text-sm"
                                    rows={2}
                                />
                            </label>
                        </div>
                        <div className="mt-4 flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => setCreatingCustomer(false)}
                                className="rounded-lg bg-white px-4 py-2 text-sm font-semibold text-slate-700 ring-1 ring-slate-200"
                            >
                                Cancelar
                            </button>
                            <button disabled={customerForm.processing} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">
                                Crear y asignar
                            </button>
                        </div>
                    </form>
                )}

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
                            {assignments.map((assignment) => [
                                <tr key={assignment.id}>
                                    <td className="px-4 py-3">
                                        <input
                                            defaultValue={assignment.visit_order ?? ''}
                                            onBlur={(event) => router.put(route('routes.zones.customers.update', [zone.id, assignment.id]), { visit_order: event.target.value || null, is_active: assignment.is_active }, { preserveScroll: true })}
                                            className="w-20 rounded-lg border-slate-200 text-sm"
                                        />
                                    </td>
                                    <td className="px-4 py-3 font-medium text-slate-900">
                                        {assignment.customer.commercial_name || assignment.customer.name}
                                        <div className="text-xs text-slate-500">{assignment.customer.name} {assignment.customer.doc_number ? `· ${assignment.customer.doc_number}` : ''}</div>
                                    </td>
                                    <td className="px-4 py-3">
                                        {assignment.customer.department || assignment.customer.municipality ? (
                                            <div className="text-xs font-semibold text-slate-500">{[assignment.customer.department, assignment.customer.municipality].filter(Boolean).join(', ')}</div>
                                        ) : null}
                                        {assignment.customer.address ?? '-'}
                                    </td>
                                    <td className="px-4 py-3">{assignment.customer.phone ?? '-'}</td>
                                    <td className="px-4 py-3">{assignment.is_active ? 'Activo' : 'Inactivo'}</td>
                                    <td className="space-x-2 px-4 py-3">
                                        <button type="button" onClick={() => startEditCustomer(assignment)} className="rounded-lg bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-700">
                                            Editar
                                        </button>
                                        <button onClick={() => router.delete(route('routes.zones.customers.destroy', [zone.id, assignment.id]), { preserveScroll: true })} className="rounded-lg bg-red-50 px-3 py-2 text-sm font-semibold text-red-700">
                                            Remover
                                        </button>
                                    </td>
                                </tr>,
                                editingAssignmentId === assignment.id ? (
                                    <tr key={`${assignment.id}-edit`}>
                                        <td colSpan={6} className="bg-slate-50 px-4 py-4">
                                            <form onSubmit={(event) => updateCustomer(event, assignment)} className="rounded-lg border border-slate-200 bg-white p-4">
                                                <div className="grid gap-3 md:grid-cols-2">
                                                    <label className="text-sm font-medium text-slate-700">
                                                        Nombre
                                                        <input value={editCustomerForm.data.name} onChange={(event) => editCustomerForm.setData('name', event.target.value)} className="mt-1 w-full rounded-lg border-slate-200 text-sm" />
                                                        {editCustomerForm.errors.name && <span className="mt-1 block text-xs text-red-600">{editCustomerForm.errors.name}</span>}
                                                    </label>
                                                    <label className="text-sm font-medium text-slate-700">
                                                        Nombre del negocio
                                                        <input value={editCustomerForm.data.commercial_name} onChange={(event) => editCustomerForm.setData('commercial_name', event.target.value)} className="mt-1 w-full rounded-lg border-slate-200 text-sm" />
                                                    </label>
                                                    <label className="text-sm font-medium text-slate-700">
                                                        NIT / documento
                                                        <input value={editCustomerForm.data.doc_number} onChange={(event) => editCustomerForm.setData('doc_number', event.target.value)} className="mt-1 w-full rounded-lg border-slate-200 text-sm" />
                                                        {editCustomerForm.errors.doc_number && <span className="mt-1 block text-xs text-red-600">{editCustomerForm.errors.doc_number}</span>}
                                                    </label>
                                                    <label className="text-sm font-medium text-slate-700">
                                                        Teléfono
                                                        <input value={editCustomerForm.data.phone} onChange={(event) => editCustomerForm.setData('phone', event.target.value)} className="mt-1 w-full rounded-lg border-slate-200 text-sm" />
                                                    </label>
                                                    <label className="text-sm font-medium text-slate-700">
                                                        Dirección
                                                        <input value={editCustomerForm.data.address} onChange={(event) => editCustomerForm.setData('address', event.target.value)} className="mt-1 w-full rounded-lg border-slate-200 text-sm" />
                                                    </label>
                                                    <GuatemalaLocationSelects
                                                        department={editCustomerForm.data.department}
                                                        municipality={editCustomerForm.data.municipality}
                                                        onDepartmentChange={(value) => editCustomerForm.setData('department', value)}
                                                        onMunicipalityChange={(value) => editCustomerForm.setData('municipality', value)}
                                                        departmentError={editCustomerForm.errors.department}
                                                        municipalityError={editCustomerForm.errors.municipality}
                                                        compact
                                                    />
                                                    <label className="text-sm font-medium text-slate-700 md:col-span-2">
                                                        Referencia / notas
                                                        <textarea value={editCustomerForm.data.notes} onChange={(event) => editCustomerForm.setData('notes', event.target.value)} className="mt-1 w-full rounded-lg border-slate-200 text-sm" rows={2} />
                                                    </label>
                                                </div>
                                                <div className="mt-4 flex justify-end gap-2">
                                                    <button type="button" onClick={() => setEditingAssignmentId(null)} className="rounded-lg bg-white px-4 py-2 text-sm font-semibold text-slate-700 ring-1 ring-slate-200">
                                                        Cancelar
                                                    </button>
                                                    <button disabled={editCustomerForm.processing} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">
                                                        Guardar cliente
                                                    </button>
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                ) : null,
                            ])}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
