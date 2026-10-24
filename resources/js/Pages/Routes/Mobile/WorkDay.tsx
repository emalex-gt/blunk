import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import GuatemalaLocationSelects from '@/Components/GuatemalaLocationSelects';
import GuatemalaIdentityTypeSelector from '@/Components/GuatemalaIdentityTypeSelector';
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
    customer: { name: string; commercial_name: string | null; contact_name: string | null; doc_number: string | null; address: string | null; address_reference: string | null; department: string | null; municipality: string | null; phone: string | null };
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

function normalizeCustomerSearch(value: string | null | undefined): string {
    return (value ?? '').trim().toLocaleLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
}

export function filterWorkDayVisits(visits: Visit[], query: string): Visit[] {
    const normalizedQuery = normalizeCustomerSearch(query);
    if (!normalizedQuery) {
        return visits;
    }

    return visits.filter((visit) => [
        visit.customer.commercial_name ?? visit.customer.name,
        visit.customer.contact_name,
        visit.customer.name,
        visit.customer.doc_number,
    ].some((value) => normalizeCustomerSearch(value).includes(normalizedQuery)));
}

export default function WorkDay({ workDay, visits, routeCash, canPostConversionCollect }: { workDay: { id: number; status: string; zone?: { name: string }; branch?: { name: string; department: string | null; municipality: string | null } }; visits: Visit[]; routeCash: { is_open: boolean }; canPostConversionCollect: boolean }) {
    const mapHref = (visit: Visit) => `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(visit.customer.address || visit.customer.name)}`;
    const branchDepartment = workDay.branch?.department ?? '';
    const branchMunicipality = workDay.branch?.municipality ?? '';
    const [showCustomerForm, setShowCustomerForm] = useState(false);
    const [customerSearch, setCustomerSearch] = useState('');
    const filteredVisits = filterWorkDayVisits(visits, customerSearch);
    const customerForm = useForm({
        name: '',
        commercial_name: '',
        contact_name: '',
        doc_type: 'CF',
        doc_number: '',
        tax_lookup_verified_at: null as string | null,
        phone: '',
        address: '',
        address_reference: '',
        department: branchDepartment,
        municipality: branchMunicipality,
        notes: '',
        work_day: '',
    });
    const customerIdentityReady = customerForm.data.doc_type === 'CF' || Boolean(customerForm.data.tax_lookup_verified_at);
    const [nitLookupLoading, setNitLookupLoading] = useState(false);
    const [nitLookupError, setNitLookupError] = useState('');
    const [showCloseConfirm, setShowCloseConfirm] = useState(false);
    const [closeKey, setCloseKey] = useState(() => makeOperationKey('route-work-day-close'));
    const closeSubmitLockedRef = useRef(false);
    const closeForm = useForm({ idempotency_key: closeKey });
    const closeErrors = closeForm.errors as Record<string, string>;
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
        if (customerForm.data.doc_type !== 'CF' && !customerForm.data.tax_lookup_verified_at) {
            setNitLookupError(`Ingresa el ${customerForm.data.doc_type} y valídalo antes de continuar.`);
            return;
        }
        customerForm.post(route('routes.mobile.work-days.customers.store', workDay.id), {
            preserveScroll: true,
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
                address_reference: customerForm.data.address_reference || customer.address_reference || '',
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
                {!routeCash.is_open && <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">No hay caja abierta para operar rutas y registrar comprobantes.</div>}
                <div>
                    <h1 className="text-2xl font-semibold text-slate-950">{workDay.zone?.name}</h1>
                    <p className="text-sm text-slate-500">{workDay.branch?.name} · {workDay.status}</p>
                </div>
                {canPostConversionCollect && <Link href={route('routes.mobile.post-conversion-collections.index')} className="block rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-center text-sm font-semibold text-emerald-800">Cobros pendientes</Link>}
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
                        className="w-full rounded-xl bg-orange-600 px-4 py-3 text-base font-semibold text-white shadow-sm"
                    >
                        Nuevo cliente
                    </button>
                )}

                {showCustomerForm && (
                    <form onSubmit={createCustomer} className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-orange-100">
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
                                    disabled={!customerIdentityReady}
                                    readOnly={customerForm.data.doc_type !== 'CF' && customerIdentityReady}
                                    className="mt-1 w-full rounded-xl border-slate-200 text-base"
                                />
                                {customerForm.errors.name && <span className="mt-1 block text-xs text-red-600">{customerForm.errors.name}</span>}
                            </label>
                            <label className="block text-sm font-medium text-slate-700">
                                Nombre del negocio
                                <input
                                    value={customerForm.data.commercial_name}
                                    onChange={(event) => customerForm.setData('commercial_name', event.target.value)}
                                    disabled={!customerIdentityReady}
                                    className="mt-1 w-full rounded-xl border-slate-200 text-base"
                                />
                            </label>
                            <label className="block text-sm font-medium text-slate-700">
                                Encargado / contacto
                                <input
                                    value={customerForm.data.contact_name}
                                    onChange={(event) => customerForm.setData('contact_name', event.target.value)}
                                    disabled={!customerIdentityReady}
                                    className="mt-1 w-full rounded-xl border-slate-200 text-base"
                                />
                            </label>
                            <div className="space-y-2">
                                <span className="block text-sm font-medium text-slate-700">Tipo de documento</span>
                                <GuatemalaIdentityTypeSelector
                                    value={customerForm.data.doc_type as 'CF' | 'NIT' | 'CUI'}
                                    onChange={(docType) => {
                                        customerForm.setData({ ...customerForm.data, name: '', doc_type: docType, doc_number: docType === 'CF' ? 'CF' : '', tax_lookup_verified_at: null });
                                        setNitLookupError('');
                                    }}
                                />
                            </div>
                            {customerForm.data.doc_type !== 'CF' && <label className="block text-sm font-medium text-slate-700">
                                {customerForm.data.doc_type === 'CUI' ? 'CUI / DPI' : 'NIT'}
                                <div className="mt-1 flex gap-2">
                                    <input
                                        value={customerForm.data.doc_number}
                                        onChange={(event) => {
                                            customerForm.setData({ ...customerForm.data, name: '', doc_number: customerForm.data.doc_type === 'CUI' ? event.target.value.replace(/\D/g, '') : event.target.value, tax_lookup_verified_at: null });
                                            setNitLookupError('');
                                        }}
                                        placeholder={customerForm.data.doc_type === 'CUI' ? '13 dígitos' : 'Ingresar NIT'}
                                        className="min-w-0 flex-1 rounded-xl border-slate-200 text-base"
                                    />
                                    <button type="button" onClick={resolveIdentityForCreate} disabled={nitLookupLoading || !customerForm.data.doc_number} className="rounded-xl bg-orange-600 px-3 text-sm font-semibold text-white disabled:opacity-60">
                                        {nitLookupLoading ? 'Validando...' : `Validar ${customerForm.data.doc_type}`}
                                    </button>
                                </div>
                                {customerForm.data.tax_lookup_verified_at && <span className="mt-1 block text-xs font-semibold text-emerald-700">Identidad verificada</span>}
                                {nitLookupLoading && <span className="mt-1 block text-xs font-semibold text-orange-600">Consultando {customerForm.data.doc_type}...</span>}
                                {nitLookupError && <span className="mt-1 block text-xs font-semibold text-red-600">{nitLookupError}</span>}
                            </label>}
                            {customerForm.data.doc_type !== 'CF' && !customerIdentityReady && (
                                <p className="text-sm text-slate-500">Valida el {customerForm.data.doc_type === 'CUI' ? 'CUI/DPI' : 'NIT'} para completar los datos del cliente.</p>
                            )}
                            <label className="block text-sm font-medium text-slate-700">
                                Teléfono
                                <input
                                    value={customerForm.data.phone}
                                    onChange={(event) => customerForm.setData('phone', event.target.value)}
                                    disabled={!customerIdentityReady}
                                    className="mt-1 w-full rounded-xl border-slate-200 text-base"
                                />
                            </label>
                            <label className="block text-sm font-medium text-slate-700">
                                Dirección
                                <input
                                    value={customerForm.data.address}
                                    onChange={(event) => customerForm.setData('address', event.target.value)}
                                    disabled={!customerIdentityReady}
                                    className="mt-1 w-full rounded-xl border-slate-200 text-base"
                                />
                            </label>
                            <label className="block text-sm font-medium text-slate-700">
                                Referencia de ubicación
                                <textarea
                                    value={customerForm.data.address_reference}
                                    onChange={(event) => customerForm.setData('address_reference', event.target.value)}
                                    disabled={!customerIdentityReady}
                                    className="mt-1 w-full rounded-xl border-slate-200 text-base"
                                    rows={2}
                                />
                            </label>
                            <GuatemalaLocationSelects
                                department={customerForm.data.department}
                                municipality={customerForm.data.municipality}
                                onDepartmentChange={(value) => customerForm.setData('department', value)}
                                onMunicipalityChange={(value) => customerForm.setData('municipality', value)}
                                departmentError={customerForm.errors.department}
                                municipalityError={customerForm.errors.municipality}
                                disabled={!customerIdentityReady}
                            />
                            <label className="block text-sm font-medium text-slate-700">
                                Referencia
                                <textarea
                                    value={customerForm.data.notes}
                                    onChange={(event) => customerForm.setData('notes', event.target.value)}
                                    disabled={!customerIdentityReady}
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
                            <button disabled={customerForm.processing || nitLookupLoading || !customerIdentityReady} className="rounded-xl bg-orange-600 px-4 py-3 text-sm font-semibold text-white disabled:opacity-60">
                                Crear cliente
                            </button>
                        </div>
                    </form>
                )}

                <div className="space-y-3">
                    <label className="block">
                        <span className="sr-only">Buscar cliente</span>
                        <input
                            type="search"
                            value={customerSearch}
                            onChange={(event) => setCustomerSearch(event.target.value)}
                            placeholder="Buscar por negocio, contacto, nombre fiscal o NIT"
                            className="w-full rounded-xl border-slate-200 px-4 py-3 text-base"
                        />
                    </label>
                    {customerSearch.trim() !== '' && filteredVisits.length === 0 && (
                        <div className="rounded-xl bg-slate-100 px-4 py-4 text-center text-sm text-slate-600">
                            <p>No se encontraron clientes en esta jornada.</p>
                            <button type="button" className="mt-2 font-semibold text-orange-600" onClick={() => setCustomerSearch('')}>Limpiar búsqueda</button>
                        </div>
                    )}
                </div>
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    {filteredVisits.map((visit) => (
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
                                <p className="mt-2 rounded-lg bg-orange-50 px-3 py-2 text-sm font-semibold text-orange-700">
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
                                <Link href={route('routes.mobile.visits.show', visit.id)} className="rounded-xl bg-orange-600 px-3 py-3 text-center text-sm font-semibold text-white">
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
                        {closeErrors.work_day && <p className="mb-2 rounded-xl bg-red-50 px-3 py-2 text-sm font-semibold text-red-700">{closeErrors.work_day}</p>}
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
                title="¿Cerrar la jornada?"
                message="Antes de cerrar, registra una preventa o marca sin venta cada visita. Las preventas quedarán enviadas y ya no se podrán editar."
                confirmLabel="Sí, cerrar jornada"
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
                                Observación (opcional)
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
