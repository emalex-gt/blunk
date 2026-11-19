import ConfirmDialog from '@/Components/ConfirmDialog';
import GuatemalaLocationSelects from '@/Components/GuatemalaLocationSelects';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { makeOperationKey } from '@/lib/idempotency';
import { formatCurrency, formatMoneyValue, parseMoneyValue } from '@/utils/currency';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, ReactNode, useEffect, useMemo, useRef, useState } from 'react';

type Product = {
    id: number;
    name: string;
    code: string | null;
    barcode: string | null;
    sale_price: string | number;
    image_url: string | null;
    stock: string | number;
    reserved_stock: string | number;
    available_stock: string | number;
    allow_decimal_quantity?: boolean;
    price_type_id?: number | null;
};

type Item = {
    product_id: number;
    name: string;
    code: string | null;
    barcode: string | null;
    quantity: string;
    maximum_quantity: string | null;
    allow_decimal_quantity: boolean;
    unit_price: number;
    manual_price?: boolean;
    discount?: number;
    notes?: string | null;
};

type ExistingItem = {
    product_id: number;
    quantity: string;
    unit_price: string;
    maximum_quantity?: string | number | null;
    allow_decimal_quantity?: boolean;
    manual_price?: boolean;
    discount?: string | number;
    notes?: string | null;
    product?: { name: string; code: string | null; barcode: string | null; allow_decimal_quantity?: boolean };
};

type Confirmation = {
    kind: 'save' | 'edit';
    title: string;
    message: string;
    details?: ReactNode;
    confirmLabel: string;
} | null;

export default function Visit({
    visit,
    preSale,
    products,
    filters,
    allowNegativeStock,
    allowManualPrice,
    routeCash,
    payment_policy,
    canCollectNow,
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
            address_reference: string | null;
            department: string | null;
            municipality: string | null;
            phone: string | null;
        };
        work_day?: { status: string };
        zone?: { name: string };
        route_work_day_id: number;
    };
    preSale: { id: number; status: string; notes: string | null; payment_method?: 'cash' | 'card' | 'transfer' | 'check' | null; items: ExistingItem[] } | null;
    products: Product[];
    filters: { search?: string };
    allowNegativeStock: boolean;
    allowManualPrice: boolean;
    routeCash: { is_open: boolean };
    payment_policy: { active: boolean; allowed_methods: ('cash' | 'card' | 'transfer' | 'check')[]; primary_method: 'cash' | 'card' | 'transfer' | 'check' | null };
    canCollectNow: boolean;
}) {
    const initialItems = (preSale?.items ?? []).map((item) => ({
        product_id: item.product_id,
        name: item.product?.name ?? 'Producto',
        code: item.product?.code ?? null,
        barcode: item.product?.barcode ?? null,
        quantity: canonicalStoredQuantity(item.quantity),
        maximum_quantity: item.maximum_quantity === null || item.maximum_quantity === undefined ? null : String(item.maximum_quantity),
        allow_decimal_quantity: Boolean(item.product?.allow_decimal_quantity ?? item.allow_decimal_quantity),
        unit_price: Number(item.unit_price ?? 0),
        manual_price: Boolean(item.manual_price),
        discount: Number(item['discount'] ?? 0),
        notes: item['notes'] ?? null,
    }));
    const [preSaleKey, setPreSaleKey] = useState(() => makeOperationKey('route-pre-sale'));
    const submitLockedRef = useRef(false);
    const paymentOptions = [
        ['cash', 'Efectivo'],
        ['card', 'Tarjeta'],
        ['transfer', 'Transferencia'],
        ['check', 'Cheque'],
    ] as const;
    const existingPaymentMethod = preSale?.payment_method ?? null;
    const existingMethodIsInvalid = payment_policy.active
        && existingPaymentMethod !== null
        && !payment_policy.allowed_methods.includes(existingPaymentMethod);
    const initialPaymentMethod = payment_policy.active
        ? (existingMethodIsInvalid ? '' : existingPaymentMethod ?? '')
        : existingPaymentMethod ?? '';
    const allowedPaymentOptions = payment_policy.active
        ? paymentOptions.filter(([value]) => payment_policy.allowed_methods.includes(value))
        : paymentOptions;
    const form = useForm<{ idempotency_key: string; notes: string; payment_method: 'cash' | 'card' | 'transfer' | 'check' | ''; collect_now: boolean; collection_payment_method: 'cash' | 'card' | 'transfer' | 'check' | ''; items: Item[] }>({
        idempotency_key: preSaleKey,
        notes: preSale?.notes ?? '',
        payment_method: initialPaymentMethod,
        collect_now: false,
        collection_payment_method: '',
        items: initialItems,
    });
    const customerDisplayName = visit.customer.commercial_name || visit.customer.name || 'cliente';
    const workDayIsOpen = visit.work_day?.status === 'open';
    const visitIsWithoutSale = visit.status === 'without_sale';
    const preSaleCanBeEdited = Boolean(preSale && ['draft', 'submitted'].includes(preSale.status) && visit.work_day?.status === 'open');
    const preSaleIsDraft = preSale?.status === 'draft';
    const [editingPreSale, setEditingPreSale] = useState(!preSale || preSaleIsDraft);
    const canModifyPreSale = workDayIsOpen && routeCash.is_open && (!preSale || (preSaleCanBeEdited && editingPreSale));
    const preSaleIsFrozen = Boolean(preSale && !preSaleCanBeEdited);
    const [confirmation, setConfirmation] = useState<Confirmation>(null);
    const [editingCustomer, setEditingCustomer] = useState(false);
    const [searchTerm, setSearchTerm] = useState(filters.search ?? '');
    const [productResults, setProductResults] = useState<Product[]>(products);
    const [searchLoading, setSearchLoading] = useState(false);
    const [searchTouched, setSearchTouched] = useState(Boolean(filters.search));
    const [quantityMessages, setQuantityMessages] = useState<Record<number, string>>({});
    const [draftSyncing, setDraftSyncing] = useState(false);
    const [draftSyncError, setDraftSyncError] = useState<string | null>(null);
    const [draftSyncRequiresReauthentication, setDraftSyncRequiresReauthentication] = useState(false);
    const [draftSyncCanRetry, setDraftSyncCanRetry] = useState(false);
    const [priceTexts, setPriceTexts] = useState<Record<number, string>>(() => Object.fromEntries(
        initialItems.map((item) => [item.product_id, formatMoneyValue(item.unit_price)]),
    ));
    const searchRequestRef = useRef(0);
    const draftSyncRequestRef = useRef(0);
    const draftSyncTimerRef = useRef<number | null>(null);
    const draftSyncControllerRef = useRef<AbortController | null>(null);
    const draftSyncInFlightRef = useRef(false);
    const draftSyncRetryRequiredRef = useRef(false);
    const confirmedItemsRef = useRef<Item[]>(initialItems);
    const customerForm = useForm({
        commercial_name: visit.customer.commercial_name ?? '',
        contact_name: visit.customer.contact_name ?? '',
        phone: visit.customer.phone ?? '',
        address: visit.customer.address ?? '',
        address_reference: visit.customer.address_reference ?? '',
        department: visit.customer.department ?? '',
        municipality: visit.customer.municipality ?? '',
        notes: '',
    });

    const total = useMemo(() => form.data.items.reduce((sum, item) => {
        return sum + (Number(item.unit_price ?? 0) * Number(normalizeQuantity(item.quantity)));
    }, 0), [form.data.items]);

    const quantityLimit = (item: Item): string | null => {
        if (allowNegativeStock || item.maximum_quantity === null) {
            return null;
        }

        return item.maximum_quantity;
    };

    const setQuantityMessage = (productId: number, message?: string) => {
        setQuantityMessages((messages) => {
            const next = { ...messages };

            if (message) {
                next[productId] = message;
            } else {
                delete next[productId];
            }

            return next;
        });
    };

    const invalidatePendingDraftSync = () => {
        draftSyncRequestRef.current += 1;

        if (draftSyncTimerRef.current !== null) {
            window.clearTimeout(draftSyncTimerRef.current);
            draftSyncTimerRef.current = null;
        }

        draftSyncControllerRef.current?.abort();
        draftSyncControllerRef.current = null;
        draftSyncInFlightRef.current = false;
        setDraftSyncing(false);
    };

    const syncDraftCart = (items: Item[], immediate = false, silent = false) => {
        if (!canModifyPreSale || preSale?.status === 'submitted') {
            return;
        }

        if (silent && (draftSyncInFlightRef.current || draftSyncTimerRef.current !== null || draftSyncRetryRequiredRef.current)) {
            return;
        }

        const requestId = draftSyncRequestRef.current + 1;
        draftSyncRequestRef.current = requestId;

        if (!silent) {
            draftSyncControllerRef.current?.abort();

            if (draftSyncTimerRef.current !== null) {
                window.clearTimeout(draftSyncTimerRef.current);
                draftSyncTimerRef.current = null;
            }

            setDraftSyncing(true);
            setDraftSyncError(null);
            setDraftSyncRequiresReauthentication(false);
            setDraftSyncCanRetry(false);
            draftSyncRetryRequiredRef.current = false;
        }

        const synchronize = async () => {
            const controller = new AbortController();
            draftSyncControllerRef.current = controller;
            draftSyncInFlightRef.current = true;

            try {
                const response = await fetch(route('routes.mobile.visits.pre-sale.draft', visit.id), {
                    method: 'PATCH',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        ...csrfHeaders(),
                    },
                    body: JSON.stringify({
                        items: items.map((item) => ({
                            product_id: item.product_id,
                            quantity: item.quantity,
                            unit_price: item.unit_price,
                            manual_price: Boolean(item.manual_price),
                            discount: Number(item['discount'] ?? 0),
                            notes: item['notes'] ?? null,
                        })),
                    }),
                    signal: controller.signal,
                });
                let payload: { errors?: { items?: string[] }; message?: string; pre_sale?: { items?: { product_id: number; maximum_quantity: string | number }[] }; products?: Product[] } | null = null;

                try {
                    payload = await response.json();
                } catch {
                    // A non-JSON response cannot prove that the server rolled back.
                }

                if (draftSyncRequestRef.current !== requestId) {
                    return;
                }

                if (!response.ok) {
                    if (response.status === 419) {
                        draftSyncRetryRequiredRef.current = true;
                        setDraftSyncRequiresReauthentication(true);
                        setDraftSyncCanRetry(true);
                        setDraftSyncError('No pudimos confirmar la reserva porque la sesión de seguridad venció. El carrito se conserva localmente y no hay stock reservado. Inicia sesión en otra pestaña y luego reintenta la reserva.');
                    } else if (response.status === 422 || response.status === 403) {
                        draftSyncRetryRequiredRef.current = false;
                        setDraftSyncRequiresReauthentication(false);
                        setDraftSyncCanRetry(false);
                        const message = payload?.errors?.items?.[0] ?? payload?.message ?? 'No se pudo reservar el stock. Inténtalo nuevamente.';
                        setDraftSyncError(message);
                        form.setData('items', confirmedItemsRef.current);
                    } else {
                        draftSyncRetryRequiredRef.current = true;
                        setDraftSyncRequiresReauthentication(false);
                        setDraftSyncCanRetry(true);
                        setDraftSyncError('No pudimos confirmar la reserva. El carrito se conserva localmente; verifica la conexión y vuelve a intentarlo antes de guardar.');
                    }
                    return;
                }

                const maximumByProduct = new Map<number, string>(
                    (payload?.pre_sale?.items ?? []).map((item: { product_id: number; maximum_quantity: string | number }) => [item.product_id, String(item.maximum_quantity)]),
                );
                const confirmed = items.map((item) => ({
                    ...item,
                    maximum_quantity: allowNegativeStock ? null : maximumByProduct.get(item.product_id) ?? item.maximum_quantity,
                }));
                confirmedItemsRef.current = confirmed;
                draftSyncRetryRequiredRef.current = false;
                setDraftSyncCanRetry(false);
                form.setData('items', confirmed);
                const stockByProduct = new Map<number, Pick<Product, 'stock' | 'reserved_stock' | 'available_stock'>>(
                    (payload?.products ?? []).map((product: Product) => [product.id, product]),
                );
                setProductResults((results) => results.map((product) => ({
                    ...product,
                    ...(stockByProduct.get(product.id) ?? {}),
                })));
            } catch {
                if (!controller.signal.aborted && draftSyncRequestRef.current === requestId) {
                    draftSyncRetryRequiredRef.current = true;
                    setDraftSyncRequiresReauthentication(false);
                    setDraftSyncCanRetry(true);
                    setDraftSyncError('No pudimos confirmar la reserva. El carrito se conserva localmente; verifica la conexión y vuelve a intentarlo antes de guardar.');
                }
            } finally {
                if (draftSyncRequestRef.current === requestId) {
                    draftSyncInFlightRef.current = false;
                    if (!silent && !controller.signal.aborted) {
                        setDraftSyncing(false);
                    }
                }
            }
        };

        if (immediate) {
            void synchronize();
        } else {
            draftSyncTimerRef.current = window.setTimeout(() => {
                draftSyncTimerRef.current = null;
                void synchronize();
            }, 350);
        }
    };

    const setCartItems = (items: Item[], immediate = false) => {
        form.setData('items', items);
        syncDraftCart(items, immediate);
    };

    useEffect(() => () => {
        if (draftSyncTimerRef.current !== null) {
            window.clearTimeout(draftSyncTimerRef.current);
        }
        draftSyncControllerRef.current?.abort();
    }, []);

    useEffect(() => {
        if (!canModifyPreSale || form.data.items.length === 0) {
            return;
        }

        const heartbeat = () => {
            if (document.visibilityState === 'visible') {
                syncDraftCart(confirmedItemsRef.current, true, true);
            }
        };
        const interval = window.setInterval(heartbeat, 60_000);
        const onVisibilityChange = () => heartbeat();

        document.addEventListener('visibilitychange', onVisibilityChange);

        return () => {
            window.clearInterval(interval);
            document.removeEventListener('visibilitychange', onVisibilityChange);
        };
    }, [canModifyPreSale, form.data.items.length]);

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
        if (!allowNegativeStock && compareQuantities(product.available_stock, '0') <= 0) {
            return;
        }

        const existing = form.data.items.find((item) => item.product_id === product.id);
        if (existing) {
            const maximumQuantity = quantityLimit(existing);

            if (maximumQuantity !== null && compareQuantities(existing.quantity, maximumQuantity) >= 0) {
                setQuantityMessage(product.id, `La cantidad máxima disponible es ${maximumQuantity}.`);
                return;
            }

            setQuantityMessage(product.id);
            setCartItems(form.data.items.map((item) => (
                item.product_id === product.id ? { ...item, quantity: addQuantity(item.quantity, '1') } : item
            )));
            return;
        }

        setCartItems([...form.data.items, {
            product_id: product.id,
            name: product.name,
            code: product.code,
            barcode: product.barcode,
            quantity: '1',
            maximum_quantity: allowNegativeStock ? null : normalizeQuantity(product.available_stock),
            allow_decimal_quantity: Boolean(product.allow_decimal_quantity),
            unit_price: Number(product.sale_price ?? 0),
            manual_price: false,
            discount: 0,
            notes: null,
        }], true);
        setPriceTexts((prices) => ({ ...prices, [product.id]: formatMoneyValue(product.sale_price) }));
    };

    const updateItem = (index: number, payload: Partial<Item>) => {
        setCartItems(form.data.items.map((row, rowIndex) => (
            rowIndex === index ? { ...row, ...payload } : row
        )));
    };

    const updateUnitPriceText = (productId: number, value: string) => {
        if (/^\d*(?:\.\d*)?$/.test(value)) {
            setPriceTexts((prices) => ({ ...prices, [productId]: value }));
        }
    };

    const commitUnitPrice = (index: number) => {
        const item = form.data.items[index];

        if (!item) {
            return;
        }

        const parsed = parseMoneyValue(priceTexts[item.product_id] ?? '');

        if (parsed === null) {
            setPriceTexts((prices) => ({ ...prices, [item.product_id]: formatMoneyValue(item.unit_price) }));
            return;
        }

        updateItem(index, { unit_price: parsed, manual_price: true });
        setPriceTexts((prices) => ({ ...prices, [item.product_id]: formatMoneyValue(parsed) }));
    };

    const updateQuantity = (index: number, requestedQuantity: string) => {
        const item = form.data.items[index];

        if (!item) {
            return;
        }

        const error = quantityError(requestedQuantity, item.allow_decimal_quantity);
        if (error) {
            // A partial value is local-only until it becomes valid. It must also
            // invalidate any older confirmation that could otherwise restore it.
            invalidatePendingDraftSync();
            form.setData('items', form.data.items.map((row, rowIndex) => (
                rowIndex === index ? { ...row, quantity: requestedQuantity } : row
            )));
            setQuantityMessage(item.product_id, error);
            return;
        }

        const maximumQuantity = quantityLimit(item);
        if (maximumQuantity !== null && compareQuantities(requestedQuantity, maximumQuantity) > 0) {
            setQuantityMessage(item.product_id, `La cantidad máxima disponible es ${maximumQuantity}.`);
            return;
        }

        setQuantityMessage(item.product_id);
        updateItem(index, { quantity: requestedQuantity });
    };

    const submit = () => {
        if (!canModifyPreSale || draftSyncing || draftSyncError || form.processing || submitLockedRef.current || form.data.items.length === 0) {
            return;
        }

        submitLockedRef.current = true;
        form.setData('idempotency_key', preSaleKey);
        form.post(route('routes.mobile.visits.pre-sale.store', visit.id), {
            preserveScroll: true,
            onSuccess: () => {
                const nextKey = makeOperationKey('route-pre-sale');
                setPreSaleKey(nextKey);
                setEditingPreSale(false);
                setConfirmation(null);
                router.visit(window.location.href, {
                    method: 'get',
                    only: ['visit', 'preSale'],
                    preserveScroll: true,
                    preserveState: false,
                });
            },
            onFinish: () => {
                submitLockedRef.current = false;
            },
        });
    };

    const requestSavePreSale = () => {
        const invalidItem = form.data.items.find((item) => {
            const maximumQuantity = quantityLimit(item);

            return Boolean(quantityError(item.quantity, item.allow_decimal_quantity))
                || (maximumQuantity !== null && compareQuantities(item.quantity, maximumQuantity) > 0);
        });

        if (invalidItem) {
            const maximumQuantity = quantityLimit(invalidItem);
            setQuantityMessage(
                invalidItem.product_id,
                maximumQuantity !== null && compareQuantities(invalidItem.quantity, maximumQuantity) > 0
                    ? `La cantidad máxima disponible es ${maximumQuantity}.`
                    : quantityError(invalidItem.quantity, invalidItem.allow_decimal_quantity) ?? 'La cantidad no es válida.',
            );
            form.setError('items', 'Corrige las cantidades antes de guardar la preventa.');
            return;
        }

        form.clearErrors('items');
        const saveMessage = form.data.collect_now
            ? 'Al registrar el cobro, esta preventa quedará enviada y no podrá editarse normalmente. ¿Confirmas el pedido y el cobro?'
            : visitIsWithoutSale
            ? 'Esta visita está marcada como sin venta. Al guardar la preventa, se quitará ese estado. ¿Deseas continuar?'
            : `¿Guardar la preventa de ${customerDisplayName}?`;

        setConfirmation({
            kind: 'save',
            title: form.data.collect_now ? 'Confirmar pedido y cobrar' : 'Guardar preventa',
            message: saveMessage,
            details: `${form.data.items.length} producto${form.data.items.length === 1 ? '' : 's'} · Total Q ${total.toFixed(2)}`,
            confirmLabel: form.data.collect_now ? 'Sí, confirmar y cobrar' : 'Sí, guardar',
        });
    };

    const confirmationDetails = confirmation && form.errors.items
        ? <div className="space-y-2"><div>{confirmation.details}</div><p className="rounded-lg bg-red-50 px-3 py-2 font-semibold text-red-700">{form.errors.items}</p></div>
        : confirmation?.details;

    const requestEditPreSale = () => {
        if (!preSaleCanBeEdited) {
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
                {!routeCash.is_open && <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">No hay caja abierta para operar rutas y registrar comprobantes.</div>}
                <div>
                    <Link
                        href={route('routes.mobile.work-days.show', visit.route_work_day_id)}
                        preserveState={false}
                        preserveScroll={false}
                        className="text-sm font-semibold text-orange-700"
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
                    {preSaleCanBeEdited && !editingPreSale && (
                        <button type="button" onClick={requestEditPreSale} className="ml-2 mt-3 rounded-xl bg-orange-600 px-3 py-2 text-sm font-semibold text-white shadow-sm">
                            Editar orden
                        </button>
                    )}
                    {preSaleIsFrozen && (
                        <p className="mt-3 rounded-xl bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-700">
                            Preventa enviada. Ya no se puede editar.
                        </p>
                    )}
                    {preSaleIsDraft && (
                        <p className="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800">
                            Borrador pendiente: no se incluirá en la ruta hasta pulsar Guardar preventa.
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
                            <label className="block text-sm font-medium text-slate-700">
                                Referencia de ubicación
                                <textarea value={customerForm.data.address_reference} onChange={(event) => customerForm.setData('address_reference', event.target.value)} className="mt-1 w-full rounded-xl border-slate-200 text-base" rows={2} />
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
                            <button disabled={customerForm.processing} className="rounded-xl bg-orange-600 px-4 py-3 text-sm font-semibold text-white">Guardar</button>
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
                        <p className="mt-2 text-sm font-semibold text-orange-700">Buscando productos...</p>
                    )}
                    {searchTouched && !searchLoading && productResults.length === 0 && (
                        <p className="mt-2 text-sm text-slate-500">Sin resultados</p>
                    )}
                </div>

                <div className="space-y-2">
                    {productResults.map((product) => {
                        const disabled = !allowNegativeStock && compareQuantities(product.available_stock, '0') <= 0;
                        const reservedStock = Number(product.reserved_stock ?? 0);
                        const availableStock = Number(product.available_stock ?? 0);
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
                                        {reservedStock > 0 && (
                                            <p className="mt-1 text-xs font-semibold text-amber-700">
                                                {availableStock <= 0
                                                    ? `Sin disponibilidad: ${reservedStock} unidades están reservadas temporalmente.`
                                                    : `${reservedStock} unidades están reservadas temporalmente; quedan ${availableStock} disponibles.`}
                                            </p>
                                        )}
                                    </div>
                                    <span className="shrink-0 text-sm font-semibold text-orange-700">Q {Number(product.sale_price).toFixed(2)}</span>
                                </div>
                                {allowNegativeStock && compareQuantities(product.available_stock, '0') <= 0 && (
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
                    <label className="mt-3 block text-sm font-medium text-slate-700">
                        Forma de pago acordada
                        <select value={form.data.payment_method} disabled={!canModifyPreSale} onChange={(event) => form.setData('payment_method', event.target.value as 'cash' | 'card' | 'transfer' | 'check' | '')} className="mt-1 h-10 w-full rounded-lg border-slate-200 text-sm disabled:bg-slate-100">
                                <option value="">Selecciona una forma de pago</option>
                                {allowedPaymentOptions.map(([value, label]) => <option value={value} key={value}>{label}</option>)}
                            </select>
                        {existingMethodIsInvalid && <p className="mt-1 text-xs font-semibold text-amber-700">El método acordado actualmente ya no está disponible para esta sucursal. Selecciona una forma de pago válida.</p>}
                        {form.errors.payment_method && <p className="mt-1 text-xs font-semibold text-red-600">{form.errors.payment_method}</p>}
                    </label>
                    {canCollectNow && canModifyPreSale && (
                        <div className="mt-4 rounded-xl border border-orange-100 bg-orange-50 p-3">
                            <label className="flex items-center gap-2 text-sm font-semibold text-slate-800">
                                <input
                                    type="checkbox"
                                    checked={form.data.collect_now}
                                    onChange={(event) => {
                                        form.setData({
                                            ...form.data,
                                            collect_now: event.target.checked,
                                            collection_payment_method: event.target.checked
                                                ? (payment_policy.allowed_methods.includes(form.data.payment_method as 'cash' | 'card' | 'transfer' | 'check')
                                                    ? form.data.payment_method
                                                    : payment_policy.primary_method ?? '')
                                                : '',
                                        });
                                    }}
                                />
                                Registrar cobro ahora
                            </label>
                            {form.data.collect_now && (
                                <label className="mt-3 block text-sm font-medium text-slate-700">
                                    Método recibido
                                    <select value={form.data.collection_payment_method} onChange={(event) => form.setData('collection_payment_method', event.target.value as 'cash' | 'card' | 'transfer' | 'check' | '')} className="mt-1 h-10 w-full rounded-lg border-slate-200 text-sm">
                                        {allowedPaymentOptions.map(([value, label]) => <option value={value} key={value}>{label}</option>)}
                                    </select>
                                    {form.errors.collection_payment_method && <p className="mt-1 text-xs font-semibold text-red-600">{form.errors.collection_payment_method}</p>}
                                    <p className="mt-2 text-xs text-slate-600">Se cobrará el total al confirmar; la preventa quedará enviada.</p>
                                </label>
                            )}
                        </div>
                    )}
                    {form.errors.collect_now && <p className="mt-2 text-xs font-semibold text-red-600">{form.errors.collect_now}</p>}
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
                                        <button type="button" onClick={() => setCartItems(form.data.items.filter((_, i) => i !== index), true)} className="text-sm font-semibold text-red-600">
                                            Eliminar
                                        </button>
                                    )}
                                </div>
                                <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,0.8fr)]">
                                    <div className="min-w-0 space-y-1.5">
                                        <p className="text-xs font-semibold text-slate-500">Cantidad</p>
                                        <div className="flex items-center rounded-lg border border-slate-200 bg-white">
                                            <button type="button" disabled={!canModifyPreSale || compareQuantities(item.quantity, item.allow_decimal_quantity ? '0.0001' : '1') <= 0} onClick={() => updateQuantity(index, subtractQuantity(item.quantity, item.allow_decimal_quantity ? '0.0001' : '1'))} className="px-3 py-2 text-sm font-bold text-slate-700 disabled:opacity-40">-</button>
                                            <input
                                                type="text"
                                                inputMode={item.allow_decimal_quantity ? 'decimal' : 'numeric'}
                                                value={item.quantity}
                                                disabled={!canModifyPreSale}
                                                onChange={(event) => updateQuantity(index, event.target.value)}
                                                className="min-w-0 flex-1 border-0 p-2 text-center text-sm disabled:bg-slate-100"
                                            />
                                            <button type="button" disabled={!canModifyPreSale} onClick={() => updateQuantity(index, addQuantity(item.quantity, '1'))} className="px-3 py-2 text-sm font-bold text-slate-700 disabled:opacity-40">+</button>
                                        </div>
                                        {!allowNegativeStock && item.maximum_quantity !== null && <p className="text-xs font-semibold text-slate-500">Stock disponible: {item.maximum_quantity}</p>}
                                        {quantityMessages[item.product_id] && <p className="text-xs font-semibold text-red-600">{quantityMessages[item.product_id]}</p>}
                                    </div>

                                    <div className="min-w-0 space-y-1.5">
                                        <p className="text-xs font-semibold text-slate-500">Precio</p>
                                        <input
                                            type="text"
                                            inputMode="decimal"
                                            min="0.01"
                                            step="0.01"
                                            value={priceTexts[item.product_id] ?? formatMoneyValue(item.unit_price)}
                                            disabled={!allowManualPrice || !canModifyPreSale}
                                            onChange={(event) => updateUnitPriceText(item.product_id, event.target.value)}
                                            onBlur={() => commitUnitPrice(index)}
                                            className="w-full rounded-lg border-slate-200 text-sm disabled:bg-slate-100"
                                        />
                                    </div>

                                    <div className="min-w-0 space-y-1.5">
                                        <p className="text-xs font-semibold text-slate-500">Subtotal</p>
                                        <div className="rounded-lg bg-white px-3 py-2 text-right text-sm font-semibold text-slate-900">
                                            {formatCurrency(Number(item.quantity || 0) * item.unit_price)}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                    {form.errors.items && <p className="mt-3 text-sm font-medium text-red-600">{form.errors.items}</p>}
                    {draftSyncError && <p className="mt-3 text-sm font-medium text-red-600">{draftSyncError}</p>}
                    {draftSyncCanRetry && (
                        <button
                            type="button"
                            onClick={() => syncDraftCart(form.data.items, true)}
                            disabled={draftSyncing}
                            className="mt-2 text-sm font-semibold text-orange-700 underline disabled:opacity-50"
                        >
                            {draftSyncRequiresReauthentication ? 'Ya inicié sesión: reintentar reserva' : 'Reintentar reserva'}
                        </button>
                    )}
                    {draftSyncing && <p className="mt-3 text-sm font-medium text-slate-500">Reservando stock…</p>}
                    {(form.errors as Record<string, string>).pre_sale && <p className="mt-3 text-sm font-medium text-red-600">{(form.errors as Record<string, string>).pre_sale}</p>}
                </div>

                <div className="fixed inset-x-0 bottom-0 border-t border-slate-200 bg-white p-4">
                    <div className="mx-auto max-w-xl">
                        <div className="mb-3 flex items-center justify-between">
                            <span className="text-sm font-medium text-slate-500">Total preventa</span>
                            <span className="text-xl font-bold text-slate-950">Q {total.toFixed(2)}</span>
                        </div>
                        <button
                            disabled={form.processing || draftSyncing || Boolean(draftSyncError) || form.data.items.length === 0 || !canModifyPreSale}
                            onClick={requestSavePreSale}
                            className="w-full rounded-xl bg-orange-600 px-4 py-3 text-base font-semibold text-white disabled:opacity-50"
                        >
                            {form.data.collect_now ? 'Confirmar pedido y cobrar' : 'Guardar preventa'}
                        </button>
                    </div>
                </div>
            </div>
            <ConfirmDialog
                open={confirmation !== null}
                title={confirmation?.title ?? ''}
                message={confirmation?.message ?? ''}
                details={confirmationDetails}
                confirmLabel={confirmation?.confirmLabel ?? 'Confirmar'}
                processing={form.processing}
                onCancel={() => setConfirmation(null)}
                onConfirm={confirmAction}
            />
        </AuthenticatedLayout>
    );
}

function normalizeQuantity(value: string | number | null | undefined): string {
    return String(value ?? '').trim().replace(',', '.');
}

function canonicalStoredQuantity(value: string | number | null | undefined): string {
    const quantity = normalizeQuantity(value);

    return /^\d+(?:\.\d{1,4})?$/.test(quantity)
        ? quantityFromScaled(scaledQuantity(quantity))
        : quantity;
}

function csrfHeaders(): Record<string, string> {
    const cookie = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='));

    if (cookie) {
        const token = cookie.slice('XSRF-TOKEN='.length);

        try {
            return { 'X-XSRF-TOKEN': decodeURIComponent(token) };
        } catch {
            return { 'X-XSRF-TOKEN': token };
        }
    }

    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;

    return token ? { 'X-CSRF-TOKEN': token } : {};
}

function quantityError(value: string | number, allowDecimal: boolean): string | null {
    const quantity = normalizeQuantity(value);
    if (!/^\d+(?:\.\d{1,4})?$/.test(quantity) || compareQuantities(quantity, '0') <= 0) {
        return 'La cantidad debe ser mayor que cero y tener como máximo cuatro decimales.';
    }
    if (!allowDecimal && quantity.includes('.')) {
        return 'Este producto solo permite cantidades enteras.';
    }
    return null;
}

function scaledQuantity(value: string | number): bigint {
    const [whole = '0', fraction = ''] = normalizeQuantity(value).split('.');
    return BigInt(`${whole || '0'}${fraction.padEnd(4, '0').slice(0, 4)}`);
}

function compareQuantities(left: string | number, right: string | number): number {
    const difference = scaledQuantity(left) - scaledQuantity(right);
    return difference === 0n ? 0 : difference > 0n ? 1 : -1;
}

function quantityFromScaled(value: bigint): string {
    const negative = value < 0n;
    const absolute = negative ? -value : value;
    const whole = absolute / 10000n;
    const fraction = (absolute % 10000n).toString().padStart(4, '0').replace(/0+$/, '');
    return `${negative ? '-' : ''}${whole}${fraction ? `.${fraction}` : ''}`;
}

function addQuantity(left: string | number, right: string | number): string {
    return quantityFromScaled(scaledQuantity(left) + scaledQuantity(right));
}

function subtractQuantity(left: string | number, right: string | number): string {
    return quantityFromScaled(scaledQuantity(left) - scaledQuantity(right));
}
