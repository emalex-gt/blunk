import GuatemalaLocationSelects from '@/Components/GuatemalaLocationSelects';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Customer = {
    id: number;
    name: string;
    commercial_name: string | null;
    contact_name: string | null;
    doc_type: string | null;
    doc_number: string | null;
    address: string | null;
    postal_code: string | null;
    department: string | null;
    municipality: string | null;
    phone: string | null;
    country: string | null;
    is_cf: boolean;
    has_real_nit: boolean;
    name_locked: boolean;
    fiscal_status: string;
    tax_lookup_verified_at: string | null;
    has_encoding_issue: boolean;
    encoding_issue_fields: string[];
};

export default function Edit({ customer }: { customer: Customer }) {
    const form = useForm({
        name: customer.name ?? '',
        commercial_name: customer.commercial_name ?? '',
        contact_name: customer.contact_name ?? '',
        phone: customer.phone ?? '',
        address: customer.address ?? '',
        postal_code: customer.postal_code ?? '',
        department: customer.department ?? '',
        municipality: customer.municipality ?? '',
    });
    const assignForm = useForm({ nit: '' });
    const refreshForm = useForm({ nit: '' });
    const canEditFiscalName = customer.is_cf;

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.put(route('customers.update', customer.id), {
            preserveScroll: true,
        });
    };

    const refreshTaxData = () => {
        refreshForm.post(route('customers.refresh-tax-data', customer.id), {
            preserveScroll: true,
            onSuccess: () => router.reload({ only: ['customer', 'flash', 'errors'] }),
        });
    };

    const assignNit = (event: FormEvent) => {
        event.preventDefault();

        assignForm.post(route('customers.assign-nit', customer.id), {
            preserveScroll: true,
            onSuccess: () => assignForm.reset(),
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-950">Editar cliente</h2>}>
            <Head title={`Cliente ${customer.name}`} />

            <div className="mx-auto max-w-5xl space-y-5 px-4 py-5 sm:px-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <Link href={route('customers.index')} className="text-sm font-semibold text-indigo-600 hover:text-indigo-700">
                            Volver a clientes
                        </Link>
                        <h1 className="mt-2 text-2xl font-semibold text-slate-950">{customer.commercial_name || customer.name}</h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Los datos fiscales de clientes con NIT se actualizan desde validación SAT/Digifact.
                        </p>
                    </div>
                    <span className="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-700">
                        {customer.fiscal_status}
                    </span>
                </div>

                <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 className="text-lg font-semibold text-slate-950">Datos fiscales</h2>
                            <p className="mt-1 text-sm text-slate-500">
                                El nombre fiscal se obtiene desde la validación del NIT y no puede editarse manualmente cuando el cliente tiene NIT real.
                            </p>
                        </div>
                        {customer.has_real_nit && (
                            <button
                                type="button"
                                onClick={refreshTaxData}
                                disabled={refreshForm.processing}
                                className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                            >
                                {refreshForm.processing ? 'Actualizando...' : 'Actualizar datos fiscales'}
                            </button>
                        )}
                    </div>

                    {customer.has_encoding_issue && (
                        <div className="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">
                            Este cliente tiene caracteres inválidos en sus datos fiscales. Pulsa Actualizar datos fiscales para intentar corregirlos.
                        </div>
                    )}

                    <div className="grid gap-4 md:grid-cols-2">
                        <label className="block text-sm font-medium text-slate-700">
                            NIT / CF
                            <input
                                value={customer.doc_number || 'CF'}
                                readOnly
                                className="mt-1 block w-full rounded-xl border-slate-200 bg-slate-100 text-sm text-slate-700 shadow-sm"
                            />
                            <span className="mt-1 block text-xs font-medium text-slate-500">
                                {customer.has_real_nit ? 'NIT bloqueado' : 'Cliente CF'}
                            </span>
                            {refreshForm.errors.nit && <span className="mt-1 block text-xs text-red-600">{refreshForm.errors.nit}</span>}
                        </label>
                        <label className="block text-sm font-medium text-slate-700">
                            Nombre fiscal
                            <input
                                value={form.data.name}
                                readOnly={!canEditFiscalName}
                                onChange={(event) => form.setData('name', event.target.value)}
                                className={`mt-1 block w-full rounded-xl border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100 ${canEditFiscalName ? 'bg-white' : 'bg-slate-100 text-slate-700'}`}
                            />
                            <span className="mt-1 block text-xs font-medium text-slate-500">
                                {canEditFiscalName ? 'Cliente CF: el nombre fiscal puede editarse.' : 'Nombre fiscal bloqueado'}
                            </span>
                            {form.errors.name && <span className="mt-1 block text-xs text-red-600">{form.errors.name}</span>}
                        </label>
                    </div>

                    {customer.has_real_nit && !customer.tax_lookup_verified_at && (
                        <div className="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">
                            NIT pendiente de validación fiscal.
                        </div>
                    )}
                </section>

                {customer.is_cf && (
                    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="text-lg font-semibold text-slate-950">Asignar NIT</h2>
                        <p className="mt-1 text-sm text-slate-500">
                            Convierte este cliente CF en cliente con NIT validado. Si el NIT ya existe en otro cliente, se bloqueará la asignación.
                        </p>
                        <form onSubmit={assignNit} className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start">
                            <label className="block min-w-0 flex-1 text-sm font-medium text-slate-700">
                                NIT
                                <input
                                    value={assignForm.data.nit}
                                    onChange={(event) => assignForm.setData('nit', event.target.value)}
                                    placeholder="Ingresar NIT"
                                    className="mt-1 block w-full rounded-xl border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100"
                                />
                                {assignForm.errors.nit && <span className="mt-1 block text-xs text-red-600">{assignForm.errors.nit}</span>}
                            </label>
                            <button
                                disabled={assignForm.processing}
                                className="mt-6 rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60"
                            >
                                Validar y asignar
                            </button>
                        </form>
                    </section>
                )}

                <form onSubmit={submit} className="space-y-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div>
                        <h2 className="text-lg font-semibold text-slate-950">Datos comerciales y contacto</h2>
                        <p className="mt-1 text-sm text-slate-500">
                            Estos campos no cambian la identidad fiscal del cliente.
                        </p>
                    </div>

                    <div className="grid gap-4 md:grid-cols-2">
                        <label className="block text-sm font-medium text-slate-700">
                            Nombre comercial
                            <input
                                value={form.data.commercial_name}
                                onChange={(event) => form.setData('commercial_name', event.target.value)}
                                className="mt-1 block w-full rounded-xl border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100"
                            />
                            {form.errors.commercial_name && <span className="mt-1 block text-xs text-red-600">{form.errors.commercial_name}</span>}
                        </label>
                        <label className="block text-sm font-medium text-slate-700">
                            Contacto / encargado
                            <input
                                value={form.data.contact_name}
                                onChange={(event) => form.setData('contact_name', event.target.value)}
                                className="mt-1 block w-full rounded-xl border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100"
                            />
                            {form.errors.contact_name && <span className="mt-1 block text-xs text-red-600">{form.errors.contact_name}</span>}
                        </label>
                        <label className="block text-sm font-medium text-slate-700">
                            Teléfono
                            <input
                                value={form.data.phone}
                                onChange={(event) => form.setData('phone', event.target.value)}
                                className="mt-1 block w-full rounded-xl border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100"
                            />
                            {form.errors.phone && <span className="mt-1 block text-xs text-red-600">{form.errors.phone}</span>}
                        </label>
                    </div>

                    <div className="border-t border-slate-100 pt-5">
                        <h2 className="text-lg font-semibold text-slate-950">Dirección / ubicación</h2>
                    </div>

                    <div className="grid gap-4 md:grid-cols-2">
                        <label className="block text-sm font-medium text-slate-700 md:col-span-2">
                            Dirección
                            <input
                                value={form.data.address}
                                onChange={(event) => form.setData('address', event.target.value)}
                                className="mt-1 block w-full rounded-xl border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100"
                            />
                            {form.errors.address && <span className="mt-1 block text-xs text-red-600">{form.errors.address}</span>}
                        </label>
                        <label className="block text-sm font-medium text-slate-700">
                            Código postal
                            <input
                                value={form.data.postal_code}
                                onChange={(event) => form.setData('postal_code', event.target.value)}
                                className="mt-1 block w-full rounded-xl border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100"
                            />
                            {form.errors.postal_code && <span className="mt-1 block text-xs text-red-600">{form.errors.postal_code}</span>}
                        </label>
                        <GuatemalaLocationSelects
                            department={form.data.department}
                            municipality={form.data.municipality}
                            onDepartmentChange={(value) => form.setData('department', value)}
                            onMunicipalityChange={(value) => form.setData('municipality', value)}
                            departmentError={form.errors.department}
                            municipalityError={form.errors.municipality}
                            compact
                        />
                    </div>

                    <div className="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-5">
                        <Link href={route('customers.index')} className="rounded-xl bg-slate-100 px-4 py-2.5 text-sm font-semibold text-slate-700">
                            Cancelar
                        </Link>
                        <button disabled={form.processing} className="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60">
                            Guardar cambios
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
