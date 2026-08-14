import ConfirmDialog from '@/Components/ConfirmDialog';
import GuatemalaLocationSelects from '@/Components/GuatemalaLocationSelects';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useEffect, useMemo, useRef, useState } from 'react';

type Product = {
    id: number;
    name: string;
    code: string | null;
    barcode: string | null;
    sale_price: string | number;
    image_url: string | null;
    stock: number;
    reserved_stock: number;
    available_stock: number;
    price_type_id?: number | null;
};

type Item = {
    product_id: number;
    name: string;
    code: string | null;
    barcode: string | null;
    quantity: number;
    unit_price: number;
    manual_price?: boolean;
};

type ExistingItem = {
    product_id: number;
    quantity: string;
    unit_price: string;
    manual_price?: boolean;
    product?: { name: string; code: string | null; barcode: string | null };
};

type Confirmation = {
    kind: 'save' | 'edit';
    title: string;
    message: string;
    details?: string;
    confirmLabel: string;
} | null;

export default function Visit({
    visit,
    preSale,
    products,
    filters,
    allowNegativeStock,
    allowManualPrice,
}: {
    visit: {
        id: number;
        status: string;
        no_sale_reason: string | null;
        no_sale_note: string | null;
        customer: {
            name: string;
            commercial_name: string | null;
            contact_name: string | null;
            doc_number: string | null;
            address: string | null;
            department: string | null;
            municipality: string | null;
            phone: string | null;
        };
        work_day?: { status: string };
        zone?: { name: string };
        route_work_day_id: number;
    };
    preSale: { id: number; status: string; notes: string | null; items: ExistingItem[] } | null;
    products: Product[];
    filters: { search?: string };
    allowNegativeStock: boolean;
    allowManualPrice: boolean;
}) {
    const initialItems = (preSale?.items ?? []).map((item) => ({
        product_id: item.product_id,
        name: item.product?.name ?? 'Producto',
        code: item.product?.code ?? null,
        barcode: item.product?.barcode ?? null,
        quantity: Number(item.quantity),
        unit_price: Number(item.unit_price ?? 0),
        manual_price: Boolean(item.manual_price),
    }));
    const form = useForm<{ notes: string; items: Item[] }>({ notes: preSale?.notes ?? '', items: initialItems });
    const customerDisplayName = visit.customer.commercial_name || visit.customer.name || 'cliente';
    const workDayIsOpen = visit.work_day?.status === 'open';
    const visitIsWithoutSale = visit.status === 'without_sale';
    const existingDraftCanBeEdited = Boolean(preSale && preSale.status === 'draft' && visit.work_day?.status === 'open');
    const [editingPreSale, setEditingPreSale] = useState(!preSale);
    const canModifyPreSale = workDayIsOpen && (!preSale || (existingDraftCanBeEdited && editingPreSale));
    const preSaleIsFrozen = Boolean(preSale && (preSale.status !== 'draft' || visit.work_day?.status !== 'open'));
    const [confirmation, setConfirmation] = useState<Confirmation>(null);
    const [editingCustomer, setEditingCustomer] = useState(false);
    const [searchTerm, setSearchTerm] = useState(filters.search ?? '');
    const [productResults, setProductResults] = useState<Product[]>(products);
    const [searchLoading, setSearchLoading] = useState(false);
    const [searchTouched, setSearchTouched] = useState(Boolean(filters.search));
    const searchRequestRef = useRef(0);
    const customerForm = useForm({
        commercial_name: visit.customer.commercial_name ?? '',
        contact_name: visit.customer.contact_name ?? '',
        phone: visit.customer.phone ?? '',
        address: visit.customer.address ?? '',
        department: visit.customer.department ?? '',
        municipality: visit.customer.municipality ?? '',
        notes: '',
    });

    const total = useMemo(() => form.data.items.reduce((sum, item) => {
        return sum + (Number(item.unit_price ?? 0) * item.quantity);
    }, 0), [form.data.items]);

    useEffect(() => {
        const value = searchTerm.trim();
        const requestId = searchRequestRef.current + 1;
        searchRequestRef.current = requestId;

        if (value === '') {
            setProductResults([]);
            setSearchLoading(false);
            setSearchTouched(false);
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setSearchLoading(true);
            setSearchTouched(true);

            try {
                const response = await fetch(`${route('routes.mobile.visits.products.search', visit.id)}?q=${encodeURIComponent(value)}`, {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                const payload = await response.json();

                if (response.ok && searchRequestRef.current === requestId) {
                    setProductResults(payload.products ?? []);
                }
            } catch {
                if (!controller.signal.aborted && searchRequestRef.current === requestId) {
                    setProductResults([]);
                }
            } finally {
                if (!controller.signal.aborted && searchRequestRef.current === requestId) {
                    setSearchLoading(false);
                }
            }
        }, 250);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [searchTerm, visit.id]);

    const addProduct = (product: Product) => {
        if (!allowNegativeStock && product.available_stock <= 0) {
            return;
        }

        const existing = form.data.items.find((item) => item.product_id === product.id);
        if (existing) {
            form.setData('items', form.data.items.map((item) => (
                item.product_id === product.id ? { ...item, quantity: item.quantity + 1 } : item
            )));
            return;
        }

        form.setData('items', [...form.data.items, {
            product_id: product.id,
            name: product.name,
            code: product.code,
            barcode: product.barcode,
            quantity: 1,
            unit_price: Number(product.sale_price ?? 0),
            manual_price: false,
        }]);
    };

    const updateItem = (index: number, payload: Partial<Item>) => {
        form.setData('items', form.data.items.map((row, rowIndex) => (
            rowIndex === index ? { ...row, ...payload } : row
        )));
    };

    const submit = () => {
        if (!canModifyPreSale || form.processing || form.data.items.length === 0) {
            return;
        }

        form.post(route('routes.mobile.visits.pre-sale.store', visit.id), {
            preserveScroll: true,
            onSuccess: () => {
                setEditingPreSale(false);
                setConfirmation(null);
                router.visit(window.location.href, {
                    method: 'get',
                    only: ['visit', 'preSale'],
                    preserveScroll: true,
                    preserveState: false,
                });
            },
        });
    };

    const requestSavePreSale = () => {
        const saveMessage = visitIsWithoutSale
            ? 'Esta visita está marcada como sin venta. Al guardar la preventa, se quitará ese estado. ¿Deseas continuar?'
            : `¿Guardar la preventa de ${customerDisplayName}?`;

        setConfirmation({
            kind: 'save',
            title: 'Guardar preventa',
            message: saveMessage,
            details: `${form.data.items.length} producto${form.data.items.length === 1 ? '' : 's'} · Total Q ${total.toFixed(2)}`,
            confirmLabel: 'Sí, guardar',
        });
    };

    const requestEditPreSale = () => {
        if (!existingDraftCanBeEdited) {
            return;
        }

        setConfirmation({
            kind: 'edit',
            title: 'Editar orden',
            message: `¿Estás seguro de editar la orden de ${customerDisplayName}?`,
            confirmLabel: 'Sí, editar',
        });
    };

    const confirmAction = () => {
        if (confirmation?.kind === 'save') {
            submit();
            return;
        }

        if (confirmation?.kind === 'edit') {
            setEditingPreSale(true);
            setConfirmation(null);
        }
    };

    const updateCustomer = (event: FormEvent) => {
        event.preventDefault();
        customerForm.put(route('routes.mobile.visits.customer.update', visit.id), {
            preserveScroll: true,
            onSuccess: () => setEditingCustomer(false),
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title={`Visita ${visit.customer.commercial_name || visit.customer.name}`} />
            <div className="mx-auto max-w-xl space-y-4 px-4 pb-32 pt-5">
                <div>
                    <Link
                        href={route('routes.mobile.work-days.show', visit.route_work_day_id)}
                        preserveState={false}
                        preserveScroll={false}
                        className="text-sm font-semibold text-indigo-700"
                    >
                        Volver
                    </Link>
                    <h1 className="mt-2 text-2xl font-semibold text-slate-950">{visit.customer.commercial_name || visit.customer.name}</h1>
                    <p className="mt-1 text-sm text-slate-600">
                        {[visit.customer.department, visit.customer.municipality].filter(Boolean).join(', ')}
                        {visit.customer.address ? ` · ${visit.customer.address}` : ''}
                    </p>
                    <button type="button" onClick={() => setEditingCustomer((value) => !value)} className="mt-3 rounded-xl bg-white px-3 py-2 text-sm font-semibold text-slate-700 ring-1 ring-slate-200">
                        Editar cliente
                    </button>
                    {existingDraftCanBeEdited && !editingPreSale && (
                        <button type="button" onClick={requestEditPreSale} className="ml-2 mt-3 rounded-xl bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm">
                            Editar orden
                        </button>
                    )}
                    {preSaleIsFrozen && (
                        <p className="mt-3 rounded-xl bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-700">
                            Preventa enviada. Ya no se puede editar.
                        </p>
                    )}
                    {visitIsWithoutSale && workDayIsOpen && (
                        <p className="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800">
                            Esta visita está marcada como sin venta. Si guardas una preventa, se quitará ese estado.
                        </p>
                    )}
                    {!workDayIsOpen && !preSale && (
                        <p className="mt-3 rounded-xl bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-700">
                            Jornada cerrada. Ya no se puede editar la visita.
                        </p>
                    )}
                    <p className="text-sm text-slate-500">
                        {visit.customer.doc_number ?? '-'} · {visit.zone?.name}
                        {visit.customer.contact_name ? ` · ${visit.customer.contact_name}` : ''}
                    </p>
                </div>

                {editingCustomer && (
                    <form onSubmit={updateCustomer} className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                        <h2 className="font-semibold text-slate-950">Datos del cliente</h2>
                        <div className="mt-3 space-y-3">
                            <label className="block text-sm font-medium text-slate-700">
                                Nombre del negocio
                                <input value={customerForm.data.commercial_name} onChange={(event) => customerForm.setData('commercial_name', event.target.value)} className="mt-1 w-full rounded-xl border-slate-200 text-base" />
                            </label>
                            <label className="block text-sm font-medium text-slate-700">
                                Encargado / contacto
                                <input value={customerForm.data.contact_name} onChange={(event) => customerForm.setData('contact_name', event.target.value)} className="mt-1 w-full rounded-xl border-slate-200 text-base" />
                            </label>
                            <label className="block text-sm font-medium text-slate-700">
                                Teléfono
                                <input value={customerForm.data.phone} onChange={(event) => customerForm.setData('phone', event.target.value)} className="mt-1 w-full rounded-xl border-slate-200 text-base" />
                            </label>
                            <label className="block text-sm font-medium text-slate-700">
                                Dirección
                                <input value={customerForm.data.address} onChange={(event) => customerForm.setData('address', event.target.value)} className="mt-1 w-full rounded-xl border-slate-200 text-base" />
                            </label>
                            <GuatemalaLocationSelects
                                department={customerForm.data.department}
                                municipality={customerForm.data.municipality}
                                onDepartmentChange={(value) => customerForm.setData('department', value)}
                                onMunicipalityChange={(value) => customerForm.setData('municipality', value)}
                                departmentError={customerForm.errors.department}
                                municipalityError={customerForm.errors.municipality}
                            />
                        </div>
                        <div className="mt-4 grid grid-cols-2 gap-2">
                            <button type="button" onClick={() => setEditingCustomer(false)} className="rounded-xl bg-white px-4 py-3 text-sm font-semibold text-slate-700 ring-1 ring-slate-200">Cancelar</button>
                            <button disabled={customerForm.processing} className="rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white">Guardar</button>
                        </div>
                    </form>
                )}

                {canModifyPreSale && (
                    <>
                <div className="rounded-xl bg-white p-3 shadow-sm ring-1 ring-slate-200">
                    <input
                        value={searchTerm}
                        onChange={(event) => setSearchTerm(event.target.value)}
                        placeholder="Buscar producto, código, barra, categoría o marca"
                        className="w-full rounded-lg border-slate-200 text-sm"
                    />
                    {searchLoading && (
                        <p className="mt-2 text-sm font-semibold text-indigo-700">Buscando productos...</p>
                    )}
                    {searchTouched && !searchLoading && productResults.length === 0 && (
                        <p className="mt-2 text-sm text-slate-500">Sin resultados</p>
                    )}
                </div>

                <div className="space-y-2">
                    {productResults.map((product) => {
                        const disabled = !allowNegativeStock && product.available_stock <= 0;
                        return (
                            <button
                                type="button"
                                key={product.id}
                                disabled={disabled}
                                onClick={() => addProduct(product)}
                                className="w-full rounded-xl bg-white p-4 text-left shadow-sm ring-1 ring-slate-200 disabled:opacity-50"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <h2 className="line-clamp-2 font-semibold leading-5 text-slate-950" title={product.name}>{product.name}</h2>
                                        <p className="truncate text-xs text-slate-500" title={product.code ?? product.barcode ?? ''}>{product.code ?? product.barcode ?? '-'}</p>
                                        <p className="mt-1 text-xs text-slate-500">Existencia: {product.stock} · Reservado: {product.reserved_stock} · Disponible: {product.available_stock}</p>
                                    </div>
                                    <span className="shrink-0 text-sm font-semibold text-indigo-700">Q {Number(product.sale_price).toFixed(2)}</span>
                                </div>
                                {allowNegativeStock && product.available_stock <= 0 && (
                                    <p className="mt-2 text-xs font-semibold text-amber-700">Stock negativo permitido</p>
                                )}
                            </button>
                        );
                    })}
                </div>
                    </>
                )}

                <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <h2 className="font-semibold text-slate-950">Preventa</h2>
                    {form.data.items.length === 0 && <p className="mt-2 text-sm text-slate-500">Agrega productos para guardar la preventa.</p>}
                    <div className="mt-3 space-y-3">
                        {form.data.items.map((item, index) => (
                            <div key={item.product_id} className="rounded-xl bg-slate-50 p-3">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="truncate text-xs text-slate-500" title={item.code ?? item.barcode ?? ''}>{item.code ?? item.barcode ?? '-'}</p>
                                        <p className="line-clamp-2 font-medium leading-5 text-slate-900" title={item.name}>{item.name}</p>
                                    </div>
                                    {canModifyPreSale && (
                                        <button type="button" onClick={() => form.setData('items', form.data.items.filter((_, i) => i !== index))} className="text-sm font-semibold text-red-600">
                                            Eliminar
                                        </button>
                                    )}
                                </div>
                                <div className="mt-3 hidden grid-cols-3 gap-2 text-xs font-semibold text-slate-500 sm:grid">
                                    <span>Cant.</span>
                                    <span>Precio</span>
                                    <span>Subtotal</span>
                                </div>
                                <div className="mt-2 grid grid-cols-1 gap-2 sm:mt-1 sm:grid-cols-3">
                                    <div className="flex items-center rounded-lg border border-slate-200 bg-white">
                                        <button type="button" disabled={!canModifyPreSale} onClick={() => updateItem(index, { quantity: Math.max(1, item.quantity - 1) })} className="px-3 py-2 text-sm font-bold text-slate-700 disabled:opacity-40">-</button>
                                        <input
                                            type="number"
                                            min="0.0001"
                                            step="0.0001"
                                            value={item.quantity}
                                            disabled={!canModifyPreSale}
                                            onChange={(event) => updateItem(index, { quantity: Number(event.target.value) })}
                                            className="min-w-0 flex-1 border-0 p-2 text-center text-sm disabled:bg-slate-100"
                                        />
                                        <button type="button" disabled={!canModifyPreSale} onClick={() => updateItem(index, { quantity: item.quantity + 1 })} className="px-3 py-2 text-sm font-bold text-slate-700 disabled:opacity-40">+</button>
                                    </div>
                                    <input
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        value={item.unit_price}
                                        disabled={!allowManualPrice || !canModifyPreSale}
                                        onChange={(event) => updateItem(index, { unit_price: Number(event.target.value), manual_price: true })}
                                        className="rounded-lg border-slate-200 text-sm disabled:bg-slate-100"
                                    />
                                    <div className="rounded-lg bg-white px-3 py-2 text-right text-sm font-semibold text-slate-900">
                                        Q {(item.quantity * item.unit_price).toFixed(2)}
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                    {form.errors.items && <p className="mt-3 text-sm font-medium text-red-600">{form.errors.items}</p>}
                    {(form.errors as Record<string, string>).pre_sale && <p className="mt-3 text-sm font-medium text-red-600">{(form.errors as Record<string, string>).pre_sale}</p>}
                </div>

                <div className="fixed inset-x-0 bottom-0 border-t border-slate-200 bg-white p-4">
                    <div className="mx-auto max-w-xl">
                        <div className="mb-3 flex items-center justify-between">
                            <span className="text-sm font-medium text-slate-500">Total preventa</span>
                            <span className="text-xl font-bold text-slate-950">Q {total.toFixed(2)}</span>
                        </div>
                        <button
                            disabled={form.processing || form.data.items.length === 0 || !canModifyPreSale}
                            onClick={requestSavePreSale}
                            className="w-full rounded-xl bg-indigo-600 px-4 py-3 text-base font-semibold text-white disabled:opacity-50"
                        >
                            Guardar preventa
                        </button>
                    </div>
                </div>
            </div>
            <ConfirmDialog
                open={confirmation !== null}
                title={confirmation?.title ?? ''}
                message={confirmation?.message ?? ''}
                details={confirmation?.details}
                confirmLabel={confirmation?.confirmLabel ?? 'Confirmar'}
                processing={form.processing}
                onCancel={() => setConfirmation(null)}
                onConfirm={confirmAction}
            />
        </AuthenticatedLayout>
    );
}
