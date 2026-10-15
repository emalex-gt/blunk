import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import GuatemalaLocationSelects from '@/Components/GuatemalaLocationSelects';
import GuatemalaIdentityTypeSelector from '@/Components/GuatemalaIdentityTypeSelector';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Customer = { id: number; name: string; commercial_name: string | null; contact_name: string | null; doc_type: string | null; doc_number: string | null; address: string | null; department: string | null; municipality: string | null; phone: string | null; name_locked: boolean; tax_lookup_verified_at: string | null };
type Assignment = { id: number; customer: Customer; visit_order: number | null; notes: string | null; is_active: boolean };
type Zone = { id: number; name: string; branch?: { name: string; department: string | null; municipality: string | null }; assigned_user?: { name: string } | null };

export default function Customers({ zone, assignments, availableCustomers, filters }: { zone: Zone; assignments: Assignment[]; availableCustomers: Customer[]; filters: { search?: string } }) {
    const form = useForm({ customer_id: '', visit_order: '', notes: '' });
    const [creatingCustomer, setCreatingCustomer] = useState(false);
    const [editingAssignmentId, setEditingAssignmentId] = useState<number | null>(null);
    const branchDepartment = zone.branch?.department ?? '';
    const branchMunicipality = zone.branch?.municipality ?? '';
    const customerForm = useForm({
        name: '',
        commercial_name: '',
        contact_name: '',
        doc_type: 'CF',
        doc_number: '',
        tax_lookup_verified_at: null as string | null,
        phone: '',
        address: '',
        department: branchDepartment,
        municipality: branchMunicipality,
        notes: '',
    });
    const editCustomerForm = useForm({
        name: '',
        commercial_name: '',
        contact_name: '',
        doc_type: 'CF',
        doc_number: '',
        tax_lookup_verified_at: null as string | null,
        phone: '',
        address: '',
        department: '',
        municipality: '',
        notes: '',
    });
    const [nitLookupLoading, setNitLookupLoading] = useState(false);
    const [nitLookupError, setNitLookupError] = useState('');
    const [editIdentityLookupLoading, setEditIdentityLookupLoading] = useState(false);
    const [editIdentityLookupError, setEditIdentityLookupError] = useState('');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('routes.zones.customers.store', zone.id), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const createCustomer = (event: FormEvent) => {
        event.preventDefault();
        if (nitLookupLoading) {
            return;
        }
        if (customerForm.data.doc_type !== 'CF' && !customerForm.data.tax_lookup_verified_at) {
            setNitLookupError(`Ingresa el ${customerForm.data.doc_type} y valídalo antes de continuar.`);
            return;
        }
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
            contact_name: assignment.customer.contact_name ?? '',
            doc_type: assignment.customer.doc_type ?? 'CF',
            doc_number: assignment.customer.doc_number ?? '',
            tax_lookup_verified_at: assignment.customer.name_locked && assignment.customer.tax_lookup_verified_at
                ? assignment.customer.tax_lookup_verified_at
                : null,
            phone: assignment.customer.phone ?? '',
            address: assignment.customer.address ?? '',
            department: assignment.customer.department ?? '',
            municipality: assignment.customer.municipality ?? '',
            notes: assignment.notes ?? '',
        });
    };

    const updateCustomer = (event: FormEvent, assignment: Assignment) => {
        event.preventDefault();
        if (editCustomerForm.data.doc_type !== 'CF' && !editCustomerForm.data.tax_lookup_verified_at) {
            setEditIdentityLookupError(`Ingresa el ${editCustomerForm.data.doc_type} y valídalo antes de continuar.`);
            return;
        }
        editCustomerForm.put(route('routes.zones.customers.details.update', [zone.id, assignment.id]), {
            preserveScroll: true,
            onSuccess: () => setEditingAssignmentId(null),
        });
    };

    async function resolveIdentityForCreate() {
        const docType = customerForm.data.doc_type;
        const docNumber = docType === 'CUI'
            ? customerForm.data.doc_number.replace(/\D/g, '')
            : customerForm.data.doc_number.trim();

        if (!docNumber || docType === 'CF') {
            return;
        }

        if (docType === 'CUI' && !/^\d{13}$/.test(docNumber)) {
            setNitLookupError('El CUI/DPI debe tener 13 dígitos.');
            return;
        }

        setNitLookupLoading(true);
        setNitLookupError('');

        try {
            const response = await fetch(`${route('customers.gt.identity-lookup')}?doc_type=${encodeURIComponent(docType)}&doc_number=${encodeURIComponent(docNumber)}`, {
                headers: { Accept: 'application/json' },
            });
            const payload = await response.json();

            if (!response.ok) {
                throw new Error(payload?.errors?.doc_number?.[0] ?? payload?.message ?? `No se pudo validar el ${docType}.`);
            }

            const customer = payload.customer ?? {};
            customerForm.setData({
                ...customerForm.data,
                name: customer.name ?? customerForm.data.name,
                commercial_name: customerForm.data.commercial_name || customer.commercial_name || '',
                contact_name: customerForm.data.contact_name || customer.contact_name || '',
                doc_type: payload.doc_type ?? docType,
                doc_number: customer.doc_number ?? payload.doc_number ?? docNumber,
                phone: customerForm.data.phone || customer.phone || '',
                address: customerForm.data.address || customer.address || '',
                department: customerForm.data.department || customer.department || '',
                municipality: customerForm.data.municipality || customer.municipality || '',
                tax_lookup_verified_at: customer.tax_lookup_verified_at ?? payload.tax_lookup_verified_at ?? null,
            });
        } catch (error) {
            setNitLookupError(error instanceof Error ? error.message : `No se pudo validar el ${docType}.`);
        } finally {
            setNitLookupLoading(false);
        }
    }

    async function resolveIdentityForEdit() {
        const docType = editCustomerForm.data.doc_type;
        const docNumber = docType === 'CUI'
            ? editCustomerForm.data.doc_number.replace(/\D/g, '')
            : editCustomerForm.data.doc_number.trim();

        if (!docNumber || docType === 'CF') {
            return;
        }

        if (docType === 'CUI' && !/^\d{13}$/.test(docNumber)) {
            setEditIdentityLookupError('El CUI/DPI debe tener 13 dígitos.');
            return;
        }

        setEditIdentityLookupLoading(true);
        setEditIdentityLookupError('');

        try {
            const response = await fetch(`${route('customers.gt.identity-lookup')}?doc_type=${encodeURIComponent(docType)}&doc_number=${encodeURIComponent(docNumber)}`, {
                headers: { Accept: 'application/json' },
            });
            const payload = await response.json();

            if (!response.ok) {
                throw new Error(payload?.errors?.doc_number?.[0] ?? payload?.message ?? `No se pudo validar el ${docType}.`);
            }

            const customer = payload.customer ?? {};
            editCustomerForm.setData({
                ...editCustomerForm.data,
                name: customer.name ?? editCustomerForm.data.name,
                commercial_name: editCustomerForm.data.commercial_name || customer.commercial_name || '',
                contact_name: editCustomerForm.data.contact_name || customer.contact_name || '',
                doc_type: payload.doc_type ?? docType,
                doc_number: customer.doc_number ?? payload.doc_number ?? docNumber,
                phone: editCustomerForm.data.phone || customer.phone || '',
                address: editCustomerForm.data.address || customer.address || '',
                department: editCustomerForm.data.department || customer.department || '',
                municipality: editCustomerForm.data.municipality || customer.municipality || '',
                tax_lookup_verified_at: customer.tax_lookup_verified_at ?? payload.tax_lookup_verified_at ?? null,
            });
        } catch (error) {
            setEditIdentityLookupError(error instanceof Error ? error.message : `No se pudo validar el ${docType}.`);
        } finally {
            setEditIdentityLookupLoading(false);
        }
    }

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
                            onClick={() => {
                                setCreatingCustomer((value) => !value);
                                if (!creatingCustomer && !customerForm.data.department && !customerForm.data.municipality) {
                                    customerForm.setData({
                                        ...customerForm.data,
                                        department: branchDepartment,
                                        municipality: branchMunicipality,
                                    });
                                }
                            }}
                            className="rounded-lg bg-orange-600 px-3 py-2 text-sm font-semibold text-white shadow-sm"
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
                    <button disabled={form.processing} className="rounded-lg bg-orange-600 px-4 py-2 text-sm font-semibold text-white">Asignar</button>
                    {form.errors.customer_id && <div className="text-sm text-red-600 md:col-span-4">{form.errors.customer_id}</div>}
                </form>

                {creatingCustomer && (
                    <form onSubmit={createCustomer} className="rounded-lg border border-orange-100 bg-white p-4 shadow-sm">
                        <div className="mb-4">
                            <h2 className="text-lg font-semibold text-slate-950">Nuevo cliente</h2>
                            <p className="text-sm text-slate-500">Se creará el cliente y quedará asignado al final de la zona.</p>
                        </div>
                        <div className="grid gap-3 md:grid-cols-2">
                            <label className="text-sm font-medium text-slate-700">
                                Nombre fiscal
                                <input
                                    value={customerForm.data.name}
                                    onChange={(event) => customerForm.setData('name', event.target.value)}
                                    readOnly={customerForm.data.doc_type !== 'CF' && Boolean(customerForm.data.tax_lookup_verified_at)}
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
                                Encargado / contacto
                                <input
                                    value={customerForm.data.contact_name}
                                    onChange={(event) => customerForm.setData('contact_name', event.target.value)}
                                    className="mt-1 w-full rounded-lg border-slate-200 text-sm"
                                />
                            </label>
                            <div className="space-y-2">
                                <span className="block text-sm font-medium text-slate-700">Tipo de documento</span>
                                <GuatemalaIdentityTypeSelector
                                    value={customerForm.data.doc_type as 'CF' | 'NIT' | 'CUI'}
                                    onChange={(docType) => {
                                        customerForm.setData({ ...customerForm.data, doc_type: docType, doc_number: docType === 'CF' ? 'CF' : '', tax_lookup_verified_at: null });
                                        setNitLookupError('');
                                    }}
                                />
                            </div>
                            {customerForm.data.doc_type !== 'CF' && <label className="text-sm font-medium text-slate-700">
                                {customerForm.data.doc_type === 'CUI' ? 'CUI / DPI' : 'NIT'}
                                <div className="mt-1 flex gap-2">
                                    <input value={customerForm.data.doc_number} onChange={(event) => {
                                        customerForm.setData({ ...customerForm.data, doc_number: customerForm.data.doc_type === 'CUI' ? event.target.value.replace(/\D/g, '') : event.target.value, tax_lookup_verified_at: null });
                                        setNitLookupError('');
                                    }} placeholder={customerForm.data.doc_type === 'CUI' ? '13 dígitos' : 'Ingresar NIT'} className="min-w-0 flex-1 rounded-lg border-slate-200 text-sm" />
                                    <button type="button" onClick={resolveIdentityForCreate} disabled={nitLookupLoading || !customerForm.data.doc_number} className="rounded-lg bg-orange-600 px-3 text-sm font-semibold text-white disabled:opacity-60">{nitLookupLoading ? 'Validando...' : `Validar ${customerForm.data.doc_type}`}</button>
                                </div>
                                {customerForm.data.tax_lookup_verified_at && <span className="mt-1 block text-xs font-semibold text-emerald-700">Identidad verificada</span>}
                                {nitLookupError && <span className="mt-1 block text-xs font-semibold text-red-600">{nitLookupError}</span>}
                                {customerForm.errors.doc_number && <span className="mt-1 block text-xs text-red-600">{customerForm.errors.doc_number}</span>}
                            </label>}
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
                            <button disabled={customerForm.processing || nitLookupLoading} className="rounded-lg bg-orange-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
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
                                        <div className="text-xs text-slate-500">
                                            {assignment.customer.name} {assignment.customer.doc_number ? `· ${assignment.customer.doc_number}` : ''}
                                            {assignment.customer.contact_name ? ` · ${assignment.customer.contact_name}` : ''}
                                        </div>
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
                                        <button type="button" onClick={() => startEditCustomer(assignment)} className="rounded-lg bg-orange-50 px-3 py-2 text-sm font-semibold text-orange-700">
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
                                                        Nombre fiscal
                                                        <input value={editCustomerForm.data.name} readOnly={editCustomerForm.data.doc_type !== 'CF' && Boolean(editCustomerForm.data.tax_lookup_verified_at)} onChange={(event) => editCustomerForm.setData('name', event.target.value)} className="mt-1 w-full rounded-lg border-slate-200 text-sm" />
                                                        {editCustomerForm.errors.name && <span className="mt-1 block text-xs text-red-600">{editCustomerForm.errors.name}</span>}
                                                    </label>
                                                    <label className="text-sm font-medium text-slate-700">
                                                        Nombre del negocio
                                                        <input value={editCustomerForm.data.commercial_name} onChange={(event) => editCustomerForm.setData('commercial_name', event.target.value)} className="mt-1 w-full rounded-lg border-slate-200 text-sm" />
                                                    </label>
                                                    <label className="text-sm font-medium text-slate-700">
                                                        Encargado / contacto
                                                        <input value={editCustomerForm.data.contact_name} onChange={(event) => editCustomerForm.setData('contact_name', event.target.value)} className="mt-1 w-full rounded-lg border-slate-200 text-sm" />
                                                    </label>
                                                    <label className="text-sm font-medium text-slate-700">
                                                        Tipo de documento
                                                        <GuatemalaIdentityTypeSelector
                                                            value={editCustomerForm.data.doc_type as 'CF' | 'NIT' | 'CUI'}
                                                            onChange={(docType) => {
                                                                editCustomerForm.setData({
                                                                ...editCustomerForm.data,
                                                                 doc_type: docType,
                                                                doc_number: docType === 'CF' ? 'CF' : '',
                                                                tax_lookup_verified_at: null,
                                                                });
                                                                setEditIdentityLookupError('');
                                                            }}
                                                        />
                                                    </label>
                                                    <label className="text-sm font-medium text-slate-700">
                                                        {editCustomerForm.data.doc_type}
                                                        <input
                                                            value={editCustomerForm.data.doc_number}
                                                            onChange={(event) => {
                                                                editCustomerForm.setData({ ...editCustomerForm.data, doc_number: editCustomerForm.data.doc_type === 'CUI' ? event.target.value.replace(/\D/g, '') : event.target.value, tax_lookup_verified_at: null });
                                                                setEditIdentityLookupError('');
                                                            }}
                                                            placeholder={editCustomerForm.data.doc_type === 'CF' ? 'CF' : 'Opcional'}
                                                            className="mt-1 w-full rounded-lg border-slate-200 text-sm"
                                                        />
                                                        {editCustomerForm.data.doc_type !== 'CF' && <button type="button" onClick={resolveIdentityForEdit} disabled={editIdentityLookupLoading || !editCustomerForm.data.doc_number} className="mt-2 rounded-lg bg-orange-600 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-60">{editIdentityLookupLoading ? 'Validando...' : `Validar ${editCustomerForm.data.doc_type}`}</button>}
                                                        {editCustomerForm.data.tax_lookup_verified_at && <span className="mt-1 block text-xs font-semibold text-emerald-700">Identidad verificada</span>}
                                                        {editIdentityLookupLoading && <span className="mt-1 block text-xs font-semibold text-orange-600">Consultando {editCustomerForm.data.doc_type}...</span>}
                                                        {editIdentityLookupError && <span className="mt-1 block text-xs font-semibold text-red-600">{editIdentityLookupError}</span>}
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
                                                    <button disabled={editCustomerForm.processing} className="rounded-lg bg-orange-600 px-4 py-2 text-sm font-semibold text-white">
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
