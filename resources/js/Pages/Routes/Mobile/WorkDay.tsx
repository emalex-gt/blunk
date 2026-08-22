import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import GuatemalaLocationSelects from '@/Components/GuatemalaLocationSelects';
import ConfirmDialog from '@/Components/ConfirmDialog';
import { makeOperationKey } from '@/lib/idempotency';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent, useRef, useState } from 'react';

type Visit = {
    id: number;
    status: string;
    no_sale_reason: string | null;
    no_sale_note: string | null;
    visit_order: number | null;
    customer: { name: string; commercial_name: string | null; contact_name: string | null; doc_number: string | null; address: string | null; department: string | null; municipality: string | null; phone: string | null };
    pre_sale?: { id: number; status: string; total: string; items_count?: number } | null;
};

const noSaleReasons = [
    'Tienda cerrada',
    'Cliente surtido',
    'No quiso comprar',
    'Sin presupuesto',
    'Encargado ausente',
    'Pedido para otro día',
    'No encontrado',
    'Otro',
];

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
    const [showCloseConfirm, setShowCloseConfirm] = useState(false);
    const [closeKey, setCloseKey] = useState(() => makeOperationKey('route-work-day-close'));
    const closeSubmitLockedRef = useRef(false);
    const closeForm = useForm({ idempotency_key: closeKey });
    const [noSaleVisit, setNoSaleVisit] = useState<Visit | null>(null);
    const [noSaleKey, setNoSaleKey] = useState(() => makeOperationKey('route-no-sale'));
    const noSaleSubmitLockedRef = useRef(false);
    const noSaleForm = useForm({
        idempotency_key: noSaleKey,
        no_sale_reason: '',
        no_sale_note: '',
        pre_sale: '',
    });

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

    const closeWorkDay = () => {
        if (closeSubmitLockedRef.current) {
            return;
        }

        closeSubmitLockedRef.current = true;
        closeForm.setData('idempotency_key', closeKey);
        closeForm.post(route('routes.mobile.work-days.close', workDay.id), {
            preserveScroll: true,
            onSuccess: () => {
                const nextKey = makeOperationKey('route-work-day-close');
                setCloseKey(nextKey);
                closeForm.setData('idempotency_key', nextKey);
            },
            onFinish: () => {
                closeSubmitLockedRef.current = false;
                setShowCloseConfirm(false);
            },
        });
    };

    const submittedPreSaleMessage = 'La preventa ya fue enviada y no se puede cambiar a sin venta.';
    const visitHasDraftPreSaleItems = (visit: Visit | null) => Boolean(visit?.pre_sale?.status === 'draft' && (visit.pre_sale.items_count ?? 0) > 0);
    const visitHasSubmittedPreSale = (visit: Visit) => Boolean(visit.pre_sale && visit.pre_sale.status !== 'draft');

    const openNoSaleModal = (visit: Visit) => {
        if (visitHasSubmittedPreSale(visit)) {
            return;
        }

        noSaleForm.clearErrors();
        const nextKey = makeOperationKey('route-no-sale');
        setNoSaleKey(nextKey);
        noSaleForm.setData({
            idempotency_key: nextKey,
            no_sale_reason: visit.no_sale_reason ?? '',
            no_sale_note: visit.no_sale_note ?? '',
            pre_sale: '',
        });
        setNoSaleVisit(visit);
    };

    const confirmNoSale = (event: FormEvent) => {
        event.preventDefault();

        if (!noSaleVisit || noSaleSubmitLockedRef.current) {
            return;
        }

        noSaleSubmitLockedRef.current = true;
        noSaleForm.setData('idempotency_key', noSaleKey);
        noSaleForm.post(route('routes.mobile.visits.without-sale', noSaleVisit.id), {
            preserveScroll: true,
            onSuccess: () => {
                setNoSaleVisit(null);
                const nextKey = makeOperationKey('route-no-sale');
                setNoSaleKey(nextKey);
                noSaleForm.setData('idempotency_key', nextKey);
            },
            onFinish: () => {
                noSaleSubmitLockedRef.current = false;
            },
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Jornada de ruta" />
            <div className="mx-auto max-w-5xl space-y-4 px-4 pb-32 pt-5">
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

                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    {visits.map((visit) => (
                        <div key={visit.id} className="flex h-full flex-col rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="text-xs font-semibold uppercase text-slate-400">#{visit.visit_order ?? '-'}</p>
                                    <h2 className="line-clamp-2 text-lg font-semibold leading-6 text-slate-950" title={visit.customer.commercial_name || visit.customer.name}>
                                        {visit.customer.commercial_name || visit.customer.name}
                                    </h2>
                                    <p className="line-clamp-2 text-sm text-slate-500" title={`${visit.customer.name}${visit.customer.doc_number ? ` · NIT ${visit.customer.doc_number}` : ''}${visit.customer.contact_name ? ` · ${visit.customer.contact_name}` : ''}`}>
                                        {visit.customer.name}{visit.customer.doc_number ? ` · NIT ${visit.customer.doc_number}` : ''}
                                        {visit.customer.contact_name ? ` · ${visit.customer.contact_name}` : ''}
                                    </p>
                                </div>
                                <span className="shrink-0 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">{visit.status}</span>
                            </div>
                            <p className="mt-2 line-clamp-2 text-sm text-slate-600" title={visit.customer.address ?? 'Sin dirección'}>{visit.customer.address ?? 'Sin dirección'}</p>
                            {visit.pre_sale && (
                                <p className="mt-2 rounded-lg bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-700">
                                    Preventa: Q {Number(visit.pre_sale.total).toFixed(2)} · {visit.pre_sale.status}
                                </p>
                            )}
                            {visit.status === 'without_sale' && (visit.no_sale_reason || visit.no_sale_note) && (
                                <p className="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800">
                                    Sin venta: {visit.no_sale_reason}
                                    {visit.no_sale_note ? ` · ${visit.no_sale_note}` : ''}
                                </p>
                            )}
                            {visit.pre_sale && visitHasSubmittedPreSale(visit) && (
                                <p className="mt-2 rounded-lg bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-600">
                                    {submittedPreSaleMessage}
                                </p>
                            )}
                            <div className="mt-auto grid grid-cols-2 gap-2 pt-4">
                                <a href={mapHref(visit)} target="_blank" className="rounded-xl bg-slate-100 px-3 py-3 text-center text-sm font-semibold text-slate-700">
                                    Abrir Maps
                                </a>
                                <Link href={route('routes.mobile.visits.show', visit.id)} className="rounded-xl bg-indigo-600 px-3 py-3 text-center text-sm font-semibold text-white">
                                    Crear/Editar preventa
                                </Link>
                                <button
                                    type="button"
                                    disabled={visit.status === 'without_sale' || visitHasSubmittedPreSale(visit)}
                                    onClick={() => openNoSaleModal(visit)}
                                    className="col-span-2 rounded-xl bg-white px-3 py-3 text-sm font-semibold text-slate-700 ring-1 ring-slate-200 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    Sin venta
                                </button>
                            </div>
                        </div>
                    ))}
                </div>
                <div className="fixed inset-x-0 bottom-0 border-t border-slate-200 bg-white p-4">
                    <div className="mx-auto max-w-5xl">
                        <button
                            type="button"
                            disabled={closeForm.processing}
                            onClick={() => setShowCloseConfirm(true)}
                            className="w-full rounded-xl bg-slate-950 px-4 py-3 text-base font-semibold text-white disabled:opacity-60"
                        >
                            {closeForm.processing ? 'Cerrando...' : 'Cerrar jornada'}
                        </button>
                    </div>
                </div>
            </div>
            <ConfirmDialog
                open={showCloseConfirm}
                title="¿Finalizar la ruta?"
                message="Al finalizar la ruta, las preventas quedarán enviadas y ya no se podrán editar. ¿Deseas continuar?"
                confirmLabel="Sí, finalizar ruta"
                processing={closeForm.processing}
                onCancel={() => setShowCloseConfirm(false)}
                onConfirm={closeWorkDay}
            />
            {noSaleVisit && (
                <div className="fixed inset-0 z-50 flex items-end bg-slate-950/40 p-4 sm:items-center sm:justify-center">
                    <form onSubmit={confirmNoSale} className="w-full max-w-lg rounded-2xl bg-white p-4 shadow-xl">
                        <div>
                            <h2 className="text-lg font-semibold text-slate-950">Sin venta</h2>
                            <p className="mt-1 text-sm text-slate-500">{noSaleVisit.customer.commercial_name || noSaleVisit.customer.name}</p>
                        </div>

                        {visitHasDraftPreSaleItems(noSaleVisit) && (
                            <div className="mt-4 rounded-xl bg-amber-50 px-3 py-3 text-sm font-semibold text-amber-800">
                                Este cliente ya tiene productos agregados. Si marcas la visita como sin venta, se eliminará la preventa actual y se liberará el stock reservado. ¿Deseas continuar?
                            </div>
                        )}

                        {noSaleForm.errors.pre_sale && (
                            <div className="mt-4 rounded-xl bg-red-50 px-3 py-3 text-sm font-semibold text-red-700">{noSaleForm.errors.pre_sale}</div>
                        )}

                        <div className="mt-4 space-y-3">
                            <label className="block text-sm font-medium text-slate-700">
                                Motivo
                                <select
                                    value={noSaleForm.data.no_sale_reason}
                                    onChange={(event) => noSaleForm.setData('no_sale_reason', event.target.value)}
                                    className="mt-1 w-full rounded-xl border-slate-200 text-base"
                                >
                                    <option value="">Selecciona un motivo</option>
                                    {noSaleReasons.map((reason) => (
                                        <option key={reason} value={reason}>
                                            {reason}
                                        </option>
                                    ))}
                                </select>
                                {noSaleForm.errors.no_sale_reason && <span className="mt-1 block text-xs font-semibold text-red-600">{noSaleForm.errors.no_sale_reason}</span>}
                            </label>
                            <label className="block text-sm font-medium text-slate-700">
                                Observación
                                <textarea
                                    value={noSaleForm.data.no_sale_note}
                                    onChange={(event) => noSaleForm.setData('no_sale_note', event.target.value)}
                                    className="mt-1 w-full rounded-xl border-slate-200 text-base"
                                    rows={3}
                                />
                                {noSaleForm.errors.no_sale_note && <span className="mt-1 block text-xs font-semibold text-red-600">{noSaleForm.errors.no_sale_note}</span>}
                            </label>
                        </div>

                        <div className="mt-5 grid grid-cols-2 gap-2">
                            <button
                                type="button"
                                onClick={() => setNoSaleVisit(null)}
                                className="rounded-xl bg-white px-4 py-3 text-sm font-semibold text-slate-700 ring-1 ring-slate-200"
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                disabled={noSaleForm.processing}
                                className="rounded-xl bg-slate-950 px-4 py-3 text-sm font-semibold text-white disabled:opacity-60"
                            >
                                {noSaleForm.processing ? 'Guardando...' : 'Confirmar sin venta'}
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
