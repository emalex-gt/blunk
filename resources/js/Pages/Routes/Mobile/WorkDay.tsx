import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import GuatemalaLocationSelects from '@/Components/GuatemalaLocationSelects';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Visit = {
    id: number;
    status: string;
    visit_order: number | null;
    customer: { name: string; commercial_name: string | null; contact_name: string | null; doc_number: string | null; address: string | null; department: string | null; municipality: string | null; phone: string | null };
    pre_sale?: { id: number; status: string; total: string } | null;
};

export default function WorkDay({ workDay, visits }: { workDay: { id: number; status: string; zone?: { name: string }; branch?: { name: string; department: string | null; municipality: string | null } }; visits: Visit[] }) {
    const mapHref = (visit: Visit) => `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(visit.customer.address || visit.customer.name)}`;
    const branchDepartment = workDay.branch?.department ?? '';
    const branchMunicipality = workDay.branch?.municipality ?? '';
    const [showCustomerForm, setShowCustomerForm] = useState(false);
    const customerForm = useForm({
        name: '',
        commercial_name: '',
        contact_name: '',
        doc_number: '',
        phone: '',
        address: '',
        department: branchDepartment,
        municipality: branchMunicipality,
        notes: '',
        work_day: '',
    });
    const [nitLookupLoading, setNitLookupLoading] = useState(false);
    const [nitLookupError, setNitLookupError] = useState('');

    const createCustomer = (event: FormEvent) => {
        event.preventDefault();
        if (nitLookupLoading) {
            return;
        }
        customerForm.post(route('routes.mobile.work-days.customers.store', workDay.id), {
            preserveScroll: true,
        });
    };

    async function resolveNitForCreate() {
        const nit = customerForm.data.doc_number.trim();

        if (!nit || nit.toUpperCase() === 'CF') {
            return;
        }

        setNitLookupLoading(true);
        setNitLookupError('');

        try {
            const response = await fetch(`${route('routes.resolve-nit')}?nit=${encodeURIComponent(nit)}`, {
                headers: { Accept: 'application/json' },
            });
            const payload = await response.json();

            if (!response.ok) {
                throw new Error(payload?.message ?? 'No se pudo validar el NIT.');
            }

            const customer = payload.customer ?? {};
            customerForm.setData({
                ...customerForm.data,
                name: customer.name ?? customerForm.data.name,
                commercial_name: customerForm.data.commercial_name || customer.commercial_name || '',
                contact_name: customerForm.data.contact_name || customer.contact_name || '',
                doc_number: customer.doc_number ?? customerForm.data.doc_number,
                phone: customerForm.data.phone || customer.phone || '',
                address: customerForm.data.address || customer.address || '',
                department: customerForm.data.department || customer.department || '',
                municipality: customerForm.data.municipality || customer.municipality || '',
            });
        } catch (error) {
            setNitLookupError(error instanceof Error ? error.message : 'No se pudo validar el NIT.');
        } finally {
            setNitLookupLoading(false);
        }
    }

    return (
        <AuthenticatedLayout>
            <Head title="Jornada de ruta" />
            <div className="mx-auto max-w-xl space-y-4 px-4 pb-28 pt-5">
                <div>
                    <h1 className="text-2xl font-semibold text-slate-950">{workDay.zone?.name}</h1>
                    <p className="text-sm text-slate-500">{workDay.branch?.name} · {workDay.status}</p>
                </div>
                {workDay.status === 'open' && (
                    <button
                        type="button"
                        onClick={() => {
                            setShowCustomerForm((value) => !value);
                            if (!showCustomerForm && !customerForm.data.department && !customerForm.data.municipality) {
                                customerForm.setData({
                                    ...customerForm.data,
                                    department: branchDepartment,
                                    municipality: branchMunicipality,
                                });
                            }
                        }}
                        className="w-full rounded-xl bg-indigo-600 px-4 py-3 text-base font-semibold text-white shadow-sm"
                    >
                        Nuevo cliente
                    </button>
                )}

                {showCustomerForm && (
                    <form onSubmit={createCustomer} className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-indigo-100">
                        <div className="mb-3">
                            <h2 className="text-lg font-semibold text-slate-950">Nuevo cliente</h2>
                            <p className="text-sm text-slate-500">Se agregará a esta zona y a la jornada actual.</p>
                        </div>
                        {customerForm.errors.work_day && (
                            <div className="mb-3 rounded-xl bg-red-50 px-3 py-2 text-sm font-semibold text-red-700">{customerForm.errors.work_day}</div>
                        )}
                        <div className="space-y-3">
                            <label className="block text-sm font-medium text-slate-700">
                                Nombre fiscal
                                <input
                                    value={customerForm.data.name}
                                    onChange={(event) => customerForm.setData('name', event.target.value)}
                                    className="mt-1 w-full rounded-xl border-slate-200 text-base"
                                />
                                {customerForm.errors.name && <span className="mt-1 block text-xs text-red-600">{customerForm.errors.name}</span>}
                            </label>
                            <label className="block text-sm font-medium text-slate-700">
                                Nombre del negocio
                                <input
                                    value={customerForm.data.commercial_name}
                                    onChange={(event) => customerForm.setData('commercial_name', event.target.value)}
                                    className="mt-1 w-full rounded-xl border-slate-200 text-base"
                                />
                            </label>
                            <label className="block text-sm font-medium text-slate-700">
                                Encargado / contacto
                                <input
                                    value={customerForm.data.contact_name}
                                    onChange={(event) => customerForm.setData('contact_name', event.target.value)}
                                    className="mt-1 w-full rounded-xl border-slate-200 text-base"
                                />
                            </label>
                            <label className="block text-sm font-medium text-slate-700">
                                NIT
                                <input
                                    value={customerForm.data.doc_number}
                                    onChange={(event) => customerForm.setData('doc_number', event.target.value)}
                                    onBlur={resolveNitForCreate}
                                    placeholder="Opcional"
                                    className="mt-1 w-full rounded-xl border-slate-200 text-base"
                                />
                                {nitLookupLoading && <span className="mt-1 block text-xs font-semibold text-indigo-600">Consultando NIT...</span>}
                                {nitLookupError && <span className="mt-1 block text-xs font-semibold text-red-600">{nitLookupError}</span>}
                            </label>
                            <label className="block text-sm font-medium text-slate-700">
                                Teléfono
                                <input
                                    value={customerForm.data.phone}
                                    onChange={(event) => customerForm.setData('phone', event.target.value)}
                                    className="mt-1 w-full rounded-xl border-slate-200 text-base"
                                />
                            </label>
                            <label className="block text-sm font-medium text-slate-700">
                                Dirección
                                <input
                                    value={customerForm.data.address}
                                    onChange={(event) => customerForm.setData('address', event.target.value)}
                                    className="mt-1 w-full rounded-xl border-slate-200 text-base"
                                />
                            </label>
                            <GuatemalaLocationSelects
                                department={customerForm.data.department}
                                municipality={customerForm.data.municipality}
                                onDepartmentChange={(value) => customerForm.setData('department', value)}
                                onMunicipalityChange={(value) => customerForm.setData('municipality', value)}
                                departmentError={customerForm.errors.department}
                                municipalityError={customerForm.errors.municipality}
                            />
                            <label className="block text-sm font-medium text-slate-700">
                                Referencia
                                <textarea
                                    value={customerForm.data.notes}
                                    onChange={(event) => customerForm.setData('notes', event.target.value)}
                                    className="mt-1 w-full rounded-xl border-slate-200 text-base"
                                    rows={2}
                                />
                            </label>
                        </div>
                        <div className="sticky bottom-20 mt-4 grid grid-cols-2 gap-2 bg-white py-2">
                            <button
                                type="button"
                                onClick={() => setShowCustomerForm(false)}
                                className="rounded-xl bg-white px-4 py-3 text-sm font-semibold text-slate-700 ring-1 ring-slate-200"
                            >
                                Cancelar
                            </button>
                            <button disabled={customerForm.processing || nitLookupLoading} className="rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white disabled:opacity-60">
                                Crear cliente
                            </button>
                        </div>
                    </form>
                )}

                {visits.map((visit) => (
                    <div key={visit.id} className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="text-xs font-semibold uppercase text-slate-400">#{visit.visit_order ?? '-'}</p>
                                <h2 className="text-lg font-semibold text-slate-950">{visit.customer.commercial_name || visit.customer.name}</h2>
                                <p className="text-sm text-slate-500">
                                    {visit.customer.name}{visit.customer.doc_number ? ` · NIT ${visit.customer.doc_number}` : ''}
                                    {visit.customer.contact_name ? ` · ${visit.customer.contact_name}` : ''}
                                </p>
                            </div>
                            <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">{visit.status}</span>
                        </div>
                        <p className="mt-2 text-sm text-slate-600">{visit.customer.address ?? 'Sin dirección'}</p>
                        {visit.pre_sale && (
                            <p className="mt-2 rounded-lg bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-700">
                                Preventa: Q {Number(visit.pre_sale.total).toFixed(2)} · {visit.pre_sale.status}
                            </p>
                        )}
                        <div className="mt-4 grid grid-cols-2 gap-2">
                            <a href={mapHref(visit)} target="_blank" className="rounded-xl bg-slate-100 px-3 py-3 text-center text-sm font-semibold text-slate-700">
                                Abrir Maps
                            </a>
                            <Link href={route('routes.mobile.visits.show', visit.id)} className="rounded-xl bg-indigo-600 px-3 py-3 text-center text-sm font-semibold text-white">
                                Crear/Editar preventa
                            </Link>
                            <button
                                onClick={() => router.post(route('routes.mobile.visits.without-sale', visit.id), {}, { preserveScroll: true })}
                                className="col-span-2 rounded-xl bg-white px-3 py-3 text-sm font-semibold text-slate-700 ring-1 ring-slate-200"
                            >
                                Sin compra
                            </button>
                        </div>
                    </div>
                ))}
                <div className="fixed inset-x-0 bottom-0 border-t border-slate-200 bg-white p-4">
                    <div className="mx-auto max-w-xl">
                        <button
                            onClick={() => router.post(route('routes.mobile.work-days.close', workDay.id))}
                            className="w-full rounded-xl bg-slate-950 px-4 py-3 text-base font-semibold text-white"
                        >
                            Cerrar jornada
                        </button>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
