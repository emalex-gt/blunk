import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import SupplierInfoPopover from '@/Components/SupplierInfoPopover';
import Toast from '@/Components/Toast';
import { getProductImageUrl } from '@/lib/cloudinary';
import { clearDraft, loadDraft, makeDraftKey, saveDraft } from '@/lib/draftStorage';
import { useToast } from '@/hooks/useToast';
import { formatCurrency } from '@/utils/currency';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { FormEvent, KeyboardEvent, useEffect, useMemo, useRef, useState } from 'react';

type Product = {
    id: number;
    name: string;
    code: string | null;
    barcode: string | null;
    cost_price: string;
    stock: number;
    min_stock: number;
    location: string | null;
    image_url: string | null;
    supplier_costs: SupplierCost[];
};

type Supplier = {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    address: string | null;
    contact_person: string | null;
};

type CartItem = {
    product: Product;
    quantity: string;
    unit_cost: string;
};

type SupplierCost = {
    supplier_id: number;
    supplier_name: string;
    supplier_phone: string | null;
    supplier_email: string | null;
    supplier_address: string | null;
    supplier_contact_person: string | null;
    unit_cost: string | number;
    created_at: string;
    created_at_formatted: string | null;
    purchase_id: number;
    purchase_number: string | null;
};

type SupplierDraft = {
    name: string;
    address: string;
    email: string;
    phone: string;
    contact_person: string;
};

type PurchaseDraft = {
    items: { product_id: number; quantity: string; unit_cost: string }[];
    supplier_name: string;
    selected_supplier_id: number | null;
    note: string;
    paid_from_cash: boolean;
    branch_id: number | null;
};

function isMeaningfulPurchaseDraft(draft: PurchaseDraft) {
    return (
        (draft.items?.length ?? 0) > 0 ||
        (draft.supplier_name ?? '').trim() !== '' ||
        (draft.note ?? '').trim() !== '' ||
        Boolean(draft.paid_from_cash)
    );
}

function quantityError(value: string): string | null {
    if (!/^[1-9]\d*$/.test(value.trim())) {
        return 'La cantidad debe ser un número entero.';
    }

    return null;
}

function sanitizeQuantityInput(value: string): string | null {
    if (/[.,]/.test(value)) {
        return null;
    }

    return value.replace(/\D/g, '');
}

export default function Create({
    products,
    suppliers,
    hasOpenCashRegister,
    branches_enabled = false,
    branches = [],
    active_branch = null,
    use_product_images = true,
}: {
    products: Product[];
    suppliers: Supplier[];
    hasOpenCashRegister: boolean;
    branches_enabled?: boolean;
    branches?: { id: number; name: string; code: string | null }[];
    active_branch?: { id: number; name: string; code: string | null } | null;
    use_product_images?: boolean;
}) {
    const business = usePage().props.business as { id?: number | null; country?: string | null } | null;
    const businessId = (usePage().props.current_business_id as number | null) ?? business?.id ?? null;
    const country = business?.country ?? 'GT';
    const draftKey = useMemo(() => makeDraftKey('purchase', businessId), [businessId]);
    const [search, setSearch] = useState('');
    const [supplierName, setSupplierName] = useState('');
    const [selectedSupplier, setSelectedSupplier] = useState<Supplier | null>(null);
    const [note, setNote] = useState('');
    const [branchId, setBranchId] = useState<number | null>(active_branch?.id ?? null);
    const [paidFromCash, setPaidFromCash] = useState(false);
    const [cart, setCart] = useState<CartItem[]>([]);
    const [message, setMessage] = useState('');
    const [processing, setProcessing] = useState(false);
    const [costHistoryProduct, setCostHistoryProduct] = useState<Product | null>(null);
    const [supplierModalOpen, setSupplierModalOpen] = useState(false);
    const [supplierDraft, setSupplierDraft] = useState<SupplierDraft>({
        name: '',
        address: '',
        email: '',
        phone: '',
        contact_person: '',
    });
    const [supplierModalError, setSupplierModalError] = useState('');
    const [restoreDraft, setRestoreDraft] = useState<PurchaseDraft | null>(null);
    const [draftReady, setDraftReady] = useState(false);
    const [showClearPurchaseModal, setShowClearPurchaseModal] = useState(false);
    const searchInputRef = useRef<HTMLInputElement>(null);
    const toast = useToast();

    const productsById = useMemo(
        () => new Map(products.map((product) => [product.id, product])),
        [products],
    );

    const supplierSuggestions = useMemo(() => {
        const term = supplierName.trim().toLowerCase();

        if (!term) {
            return suppliers.slice(0, 8);
        }

        return suppliers
            .filter((supplier) => supplier.name.toLowerCase().includes(term))
            .slice(0, 8);
    }, [supplierName, suppliers]);

    const filteredProducts = useMemo(() => {
        const term = search.trim().toLowerCase();

        if (!term) {
            return products.slice(0, 32);
        }

        return products
            .filter((product) =>
                [product.name, product.code, product.barcode]
                    .filter(Boolean)
                    .some((value) => value!.toLowerCase().includes(term)),
            )
            .slice(0, 32);
    }, [products, search]);

    const total = useMemo(
        () =>
            cart.reduce(
                (sum, item) => sum + Number(item.quantity || 0) * Number(item.unit_cost || 0),
                0,
            ),
        [cart],
    );

    function addProduct(product: Product) {
        setCart((items) => {
            const existing = items.find((item) => item.product.id === product.id);

            if (existing) {
                return items.map((item) =>
                    item.product.id === product.id
                        ? { ...item, quantity: String(Number(item.quantity || 0) + 1) }
                        : item,
                );
            }

            return [
                ...items,
                {
                    product,
                    quantity: '1',
                    unit_cost: String(product.cost_price ?? '0'),
                },
            ];
        });
    }

    function handleSearchKeyDown(event: KeyboardEvent<HTMLInputElement>) {
        if (event.key !== 'Enter') {
            return;
        }

        event.preventDefault();
        const product = filteredProducts[0];

        if (!product) {
            return;
        }

        addProduct(product);
        setSearch('');
        requestAnimationFrame(() => searchInputRef.current?.focus());
    }

    function updateItem(productId: number, field: 'quantity' | 'unit_cost', value: string) {
        if (field === 'quantity') {
            const sanitized = sanitizeQuantityInput(value);

            if (sanitized === null) {
                setMessage('La cantidad debe ser un número entero.');
                toast.error('La cantidad debe ser un número entero.');
                return;
            }

            value = sanitized;
        }

        setCart((items) =>
            items.map((item) =>
                item.product.id === productId ? { ...item, [field]: value } : item,
            ),
        );
    }

    function removeItem(productId: number) {
        setCart((items) => items.filter((item) => item.product.id !== productId));
    }

    function buildPurchaseDraft(): PurchaseDraft {
        return {
            items: cart.map((item) => ({
                product_id: item.product.id,
                quantity: item.quantity,
                unit_cost: item.unit_cost,
            })),
            supplier_name: supplierName,
            selected_supplier_id: selectedSupplier?.id ?? null,
            note,
            paid_from_cash: paidFromCash,
            branch_id: branchId,
        };
    }

    function restorePurchaseDraft(draft: PurchaseDraft) {
        const restoredItems = draft.items
            .map((item) => {
                const product = productsById.get(item.product_id);

                return product
                    ? {
                        product,
                        quantity: item.quantity ?? '1',
                        unit_cost: item.unit_cost ?? String(product.cost_price ?? '0'),
                    }
                    : null;
            })
            .filter((item): item is CartItem => Boolean(item));

        setCart(restoredItems);
        setSupplierName(draft.supplier_name ?? '');
        setSelectedSupplier(
            draft.selected_supplier_id
                ? suppliers.find((supplier) => supplier.id === draft.selected_supplier_id) ?? null
                : null,
        );
        setNote(draft.note ?? '');
        setPaidFromCash(Boolean(draft.paid_from_cash && hasOpenCashRegister));
        setBranchId(draft.branch_id ?? active_branch?.id ?? null);
    }

    function clearPurchaseState() {
        setSearch('');
        setSupplierName('');
        setSelectedSupplier(null);
        setNote('');
        setPaidFromCash(false);
        setBranchId(active_branch?.id ?? null);
        setCart([]);
        setMessage('');
        requestAnimationFrame(() => searchInputRef.current?.focus());
    }

    function clearPurchaseDraftAndState() {
        clearPurchaseState();
        clearDraft(draftKey);
    }

    function requestClearPurchase() {
        if (isMeaningfulPurchaseDraft(buildPurchaseDraft())) {
            setShowClearPurchaseModal(true);
            return;
        }

        clearPurchaseDraftAndState();
    }

    function handleProductCardKeyDown(event: KeyboardEvent<HTMLDivElement>, product: Product) {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }

        event.preventDefault();
        addProduct(product);
    }

    function validatePurchaseBeforeSubmit(): boolean {
        setMessage('');

        if (cart.length === 0) {
            setMessage('Agrega productos a la compra.');
            return false;
        }

        const invalidQuantity = cart.find((item) => quantityError(item.quantity));

        if (invalidQuantity) {
            setMessage('La cantidad debe ser un número entero.');
            toast.error('La cantidad debe ser un número entero.');
            return false;
        }

        const invalidItem = cart.find((item) => Number(item.unit_cost) < 0);

        if (invalidItem) {
            setMessage('Revisa cantidades y costos.');
            toast.error('Revisa cantidades y costos.');
            return false;
        }

        return true;
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        if (!validatePurchaseBeforeSubmit()) {
            return;
        }

        const cleanSupplierName = supplierName.trim();
        const matchingSupplier = cleanSupplierName
            ? suppliers.find((supplier) => supplier.name.trim().toLowerCase() === cleanSupplierName.toLowerCase())
            : null;

        if (!selectedSupplier && matchingSupplier) {
            submitPurchase(matchingSupplier, null);
            return;
        }

        if (!selectedSupplier && cleanSupplierName !== '') {
            setSupplierDraft({
                name: cleanSupplierName,
                address: '',
                email: '',
                phone: '',
                contact_person: '',
            });
            setSupplierModalError('');
            setSupplierModalOpen(true);
            return;
        }

        submitPurchase(selectedSupplier, null);
    }

    function submitPurchase(
        supplier: Supplier | null,
        newSupplier: SupplierDraft | null,
        options: { fromSupplierModal?: boolean } = {},
    ) {
        const cleanSupplierName = supplierName.trim();
        setProcessing(true);

        router.post(route('purchases.store'), {
            supplier_id: supplier?.id ?? null,
            supplier_name: newSupplier?.name || cleanSupplierName || null,
            supplier: newSupplier,
            paid_from_cash: paidFromCash,
            branch_id: branchId,
            note,
            items: cart.map((item) => ({
                product_id: item.product.id,
                quantity: Number(item.quantity),
                unit_cost: Number(item.unit_cost),
            })),
        }, {
            preserveScroll: true,
            onError: (errors) => {
                const firstError = Object.values(errors)[0];
                const errorMessage = String(firstError ?? 'No se pudo registrar la compra.');
                setMessage(errorMessage);
                toast.error(errorMessage);

                if (options.fromSupplierModal) {
                    setSupplierModalError(errorMessage);
                }
            },
            onSuccess: () => {
                clearDraft(draftKey);
                if (options.fromSupplierModal) {
                    setSupplierModalOpen(false);
                    setSupplierModalError('');
                }
            },
            onFinish: () => setProcessing(false),
        });
    }

    function submitSupplierModal(event: FormEvent) {
        event.preventDefault();
        setSupplierModalError('');

        if (supplierDraft.name.trim() === '') {
            setSupplierModalError('El nombre del proveedor es obligatorio.');
            return;
        }

        if (supplierDraft.email.trim() !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(supplierDraft.email.trim())) {
            setSupplierModalError('Ingresa un email válido.');
            return;
        }

        const cleanDraft = {
            name: supplierDraft.name.trim(),
            address: supplierDraft.address.trim(),
            email: supplierDraft.email.trim(),
            phone: supplierDraft.phone.trim(),
            contact_person: supplierDraft.contact_person.trim(),
        };

        setSupplierName(cleanDraft.name);
        submitPurchase(null, cleanDraft, { fromSupplierModal: true });
    }

    useEffect(() => {
        const draft = loadDraft<PurchaseDraft>(draftKey);

        if (draft && isMeaningfulPurchaseDraft(draft)) {
            setRestoreDraft(draft);
        } else {
            setDraftReady(true);
        }
    }, [draftKey]);

    useEffect(() => {
        if (!draftReady || restoreDraft) {
            return;
        }

        const draft = buildPurchaseDraft();
        const timer = window.setTimeout(() => {
            if (isMeaningfulPurchaseDraft(draft)) {
                saveDraft(draftKey, draft);
            } else {
                clearDraft(draftKey);
            }
        }, 500);

        return () => window.clearTimeout(timer);
    }, [
        cart,
        draftKey,
        draftReady,
        note,
        paidFromCash,
        restoreDraft,
        selectedSupplier,
        supplierName,
    ]);

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-950">Compras</h2>}>
            <Head title="Registrar compra" />
            <Toast toasts={toast.toasts} onClose={toast.removeToast} />

            <div className="h-[calc(100vh-8rem)] bg-[#f4f6fb]">
                <div className="mx-auto grid h-full max-w-[1800px] gap-5 p-5 lg:grid-cols-[minmax(0,1fr)_620px] xl:grid-cols-[minmax(0,1fr)_680px]">
                    <section className="flex min-h-0 flex-col rounded-2xl border border-slate-200/80 bg-white/95 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="border-b border-slate-200 p-4">
                            <div className="mb-4 flex items-center justify-between gap-3">
                                <div>
                                    <h1 className="text-2xl font-semibold text-slate-950">
                                        Registrar compra
                                    </h1>
                                    <p className="text-sm text-slate-500">
                                        Compra inventario y actualiza costo promedio.
                                    </p>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        onClick={requestClearPurchase}
                                        className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50"
                                    >
                                        Limpiar compra
                                    </button>
                                    <Link
                                        href={route('purchases.index')}
                                        className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50"
                                    >
                                        Historial
                                    </Link>
                                </div>
                            </div>

                            <div className="grid gap-3 md:grid-cols-2">
                                <div className="relative">
                                    <label className="text-sm font-medium text-slate-700">
                                        Proveedor
                                    </label>
                                    <input
                                        type="text"
                                        name="supplier_input"
                                        value={supplierName}
                                        onChange={(event) => {
                                            setSupplierName(event.target.value);
                                            if (selectedSupplier && event.target.value !== selectedSupplier.name) {
                                                setSelectedSupplier(null);
                                            }
                                        }}
                                        autoComplete="off"
                                        autoCorrect="off"
                                        spellCheck={false}
                                        placeholder="Escribe o selecciona un proveedor"
                                        className="mt-1 h-11 w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                    />
                                    <p className="mt-1 text-xs text-slate-500">
                                        Se creará un nuevo proveedor si no existe
                                    </p>
                                    {supplierName && !selectedSupplier && supplierSuggestions.length > 0 && (
                                        <div className="absolute z-20 mt-1 max-h-48 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg">
                                            {supplierSuggestions.map((supplier) => (
                                                <button
                                                    key={supplier.id}
                                                    type="button"
                                                    onClick={() => {
                                                        setSupplierName(supplier.name);
                                                        setSelectedSupplier(supplier);
                                                    }}
                                                    className="block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-indigo-50"
                                                >
                                                    <span className="block font-semibold">{supplier.name}</span>
                                                    {(supplier.phone || supplier.email) && (
                                                        <span className="block text-xs text-slate-500">
                                                            {[supplier.phone, supplier.email].filter(Boolean).join(' · ')}
                                                        </span>
                                                    )}
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                </div>

                                <div>
                                    <label className="text-sm font-medium text-slate-700">Nota</label>
                                    <input
                                        value={note}
                                        onChange={(event) => setNote(event.target.value)}
                                        placeholder="Nota opcional"
                                        className="mt-1 h-11 w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                    />
                                </div>
                                {branches_enabled && branches.length > 0 && (
                                    <div>
                                        <label className="text-sm font-medium text-slate-700">Sucursal destino</label>
                                        <select
                                            value={branchId ?? ''}
                                            onChange={(event) => setBranchId(Number(event.target.value))}
                                            className="mt-1 h-11 w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                        >
                                            {branches.map((branch) => (
                                                <option key={branch.id} value={branch.id}>
                                                    {branch.name}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                )}
                            </div>

                            <label className="mt-3 flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50/70 p-3 text-sm">
                                <input
                                    type="checkbox"
                                    checked={paidFromCash}
                                    disabled={!hasOpenCashRegister}
                                    onChange={(event) => setPaidFromCash(event.target.checked)}
                                    className="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50"
                                />
                                <span>
                                    <span className="block font-semibold text-slate-800">¿Pagar desde caja?</span>
                                    <span className="block text-xs text-slate-500">
                                        {hasOpenCashRegister
                                            ? 'La compra reducirá el efectivo esperado de caja.'
                                            : 'No hay caja abierta'}
                                    </span>
                                </span>
                            </label>

                            <input
                                ref={searchInputRef}
                                autoFocus
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                onKeyDown={handleSearchKeyDown}
                                placeholder="Buscar producto por nombre, código o código de barras"
                                className="mt-3 h-14 w-full rounded-2xl border border-slate-200 bg-white px-5 text-lg font-medium text-slate-900 shadow-sm outline-none transition focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                            />
                        </div>

                        <div className="min-h-0 flex-1 overflow-y-auto p-4">
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                {filteredProducts.map((product) => (
                                    <div
                                        key={product.id}
                                        role="button"
                                        tabIndex={0}
                                        onClick={() => addProduct(product)}
                                        onKeyDown={(event) => handleProductCardKeyDown(event, product)}
                                        className="relative flex cursor-pointer items-stretch gap-3 rounded-2xl border border-slate-200 bg-white p-3 text-left shadow-[0_4px_18px_rgba(15,23,42,0.05)] transition-all duration-200 hover:-translate-y-0.5 hover:border-indigo-200 hover:shadow-[0_12px_28px_rgba(15,23,42,0.10)] focus:outline-none focus:ring-4 focus:ring-indigo-100"
                                    >
                                        <button
                                            type="button"
                                            title="Ver últimos costos"
                                            onClick={(event) => {
                                                event.stopPropagation();
                                                setCostHistoryProduct(product);
                                            }}
                                            className="absolute right-2 top-2 z-10 flex h-7 w-7 items-center justify-center rounded-full border border-slate-200 bg-white/95 text-xs font-bold text-slate-500 shadow-sm transition hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700"
                                        >
                                            i
                                        </button>

                                        {use_product_images && product.image_url ? (
                                            <img
                                                src={getProductImageUrl(product.image_url, 200) ?? ''}
                                                alt={product.name}
                                                loading="lazy"
                                                className="h-20 w-20 shrink-0 rounded-xl object-cover"
                                            />
                                        ) : null}

                                        <div className="flex min-w-0 flex-1 flex-col justify-between">
                                            <div>
                                                <h3 className="truncate pr-7 text-sm font-semibold text-slate-950">
                                                    {product.name}
                                                </h3>
                                                <div className="mt-1 truncate text-xs text-slate-500">
                                                    {product.barcode || product.code || 'Código'}
                                                </div>
                                            </div>
                                            <div className="flex items-center justify-between gap-2 text-xs">
                                                <span className="text-slate-600">Stock {product.stock}</span>
                                                <span className="whitespace-nowrap font-semibold text-slate-950">
                                                    {formatCurrency(product.cost_price, country)}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </section>

                    <form
                        onSubmit={submit}
                        className="flex min-h-0 flex-col rounded-2xl border border-slate-200 bg-white shadow-[0_8px_30px_rgba(15,23,42,0.08)]"
                    >
                        <header className="border-b border-slate-200 p-4">
                            <h2 className="text-xl font-semibold text-slate-950">Compra</h2>
                            <p className="text-sm text-slate-500">
                                {cart.length} productos
                            </p>
                        </header>

                        <div className="min-h-0 flex-1 overflow-y-auto p-3">
                            {message && (
                                <div className="mb-3 rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
                                    {message}
                                </div>
                            )}

                            {cart.length === 0 && (
                                <div className="flex h-full items-center justify-center rounded-2xl border border-dashed border-slate-300 p-6 text-center text-slate-500">
                                    Agrega productos desde la búsqueda.
                                </div>
                            )}

                            <div>
                                {cart.map((item) => {
                                    const lineTotal = Number(item.quantity || 0) * Number(item.unit_cost || 0);
                                    const quantityMessage = quantityError(item.quantity);

                                    return (
                                        <div
                                            key={item.product.id}
                                            className="grid grid-cols-[minmax(0,1fr)_92px_112px_100px_32px] items-start gap-3 border-b border-slate-100 py-2 last:border-b-0"
                                        >
                                            <div className="min-w-0 text-sm">
                                                <div className="truncate font-semibold text-slate-950">
                                                    {item.product.barcode || item.product.code || 'Código'} - {item.product.name}
                                                </div>
                                            </div>
                                            <div>
                                                <input
                                                    type="number"
                                                    min="1"
                                                    step="1"
                                                    inputMode="numeric"
                                                    pattern="[0-9]*"
                                                    value={item.quantity}
                                                    onChange={(event) => updateItem(item.product.id, 'quantity', event.target.value)}
                                                    onWheel={(event) => event.currentTarget.blur()}
                                                    className={[
                                                        'h-10 w-full rounded-xl text-center text-sm font-semibold text-slate-900 focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100',
                                                        quantityMessage ? 'border-red-300' : 'border-slate-200',
                                                    ].join(' ')}
                                                />
                                                {quantityMessage && (
                                                    <div className="mt-1 text-xs font-semibold text-red-600">
                                                        {quantityMessage}
                                                    </div>
                                                )}
                                            </div>
                                            <input
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                value={item.unit_cost}
                                                onChange={(event) => updateItem(item.product.id, 'unit_cost', event.target.value)}
                                                className="h-10 rounded-xl border-slate-200 text-center text-sm font-semibold text-slate-900 focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                            />
                                            <div className="whitespace-nowrap text-right text-sm font-semibold text-slate-950">
                                                {formatCurrency(lineTotal, country)}
                                            </div>
                                            <button
                                                type="button"
                                                title="Eliminar"
                                                onClick={() => removeItem(item.product.id)}
                                                className="rounded-lg px-2 py-1 text-red-500 hover:bg-red-50 hover:text-red-700"
                                            >
                                                ×
                                            </button>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>

                        <footer className="border-t border-slate-200 bg-slate-50/70 p-4">
                            <div className="flex items-end justify-between">
                                <span className="text-sm font-semibold uppercase text-slate-500">Total</span>
                                <span className="whitespace-nowrap text-4xl font-bold text-slate-950">
                                    {formatCurrency(total, country)}
                                </span>
                            </div>
                            <button
                                type="submit"
                                disabled={cart.length === 0 || processing}
                                className="mt-4 h-14 w-full rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 text-base font-semibold text-white shadow-lg shadow-indigo-200 transition-all duration-200 hover:-translate-y-0.5 hover:from-indigo-700 hover:to-violet-700 active:scale-[0.98] disabled:cursor-not-allowed disabled:bg-none disabled:bg-slate-300 disabled:shadow-none"
                            >
                                {processing ? 'Registrando...' : 'Registrar compra'}
                            </button>
                        </footer>
                    </form>
                </div>
            </div>

            {costHistoryProduct && (
                <CostHistoryModal
                    product={costHistoryProduct}
                    country={country}
                    onClose={() => setCostHistoryProduct(null)}
                />
            )}

            {supplierModalOpen && (
                <NewSupplierModal
                    draft={supplierDraft}
                    error={supplierModalError}
                    processing={processing}
                    onChange={setSupplierDraft}
                    onCancel={() => {
                        setSupplierModalOpen(false);
                        setSupplierModalError('');
                    }}
                    onSubmit={submitSupplierModal}
                />
            )}

            {restoreDraft && (
                <div className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
                    <section className="w-full max-w-md rounded-2xl border border-amber-100 bg-white p-6 shadow-2xl">
                        <h2 className="text-lg font-semibold text-slate-950">
                            Trabajo pendiente encontrado
                        </h2>
                        <p className="mt-2 text-sm text-slate-600">
                            Se encontró una compra en proceso guardada automáticamente.
                        </p>
                        <div className="mt-5 flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => {
                                    clearDraft(draftKey);
                                    setRestoreDraft(null);
                                    setDraftReady(true);
                                    requestAnimationFrame(() => searchInputRef.current?.focus());
                                }}
                                className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                Descartar
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    restorePurchaseDraft(restoreDraft);
                                    setRestoreDraft(null);
                                    setDraftReady(true);
                                    requestAnimationFrame(() => searchInputRef.current?.focus());
                                }}
                                className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
                            >
                                Continuar
                            </button>
                        </div>
                    </section>
                </div>
            )}

            {showClearPurchaseModal && (
                <div className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
                    <section className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl">
                        <h2 className="text-lg font-semibold text-slate-950">
                            Limpiar compra
                        </h2>
                        <p className="mt-2 text-sm text-slate-600">
                            ¿Seguro que deseas limpiar esta compra?
                        </p>
                        <div className="mt-5 flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => setShowClearPurchaseModal(false)}
                                className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                Cancelar
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    setShowClearPurchaseModal(false);
                                    clearPurchaseDraftAndState();
                                }}
                                className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
                            >
                                Limpiar compra
                            </button>
                        </div>
                    </section>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

function CostHistoryModal({
    product,
    country,
    onClose,
}: {
    product: Product;
    country: string;
    onClose: () => void;
}) {
    const history = product.supplier_costs ?? [];

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 p-4 backdrop-blur-sm"
            onClick={onClose}
        >
            <section
                className="w-full max-w-2xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl"
                onClick={(event) => event.stopPropagation()}
            >
                <header className="flex items-start justify-between gap-4 border-b border-slate-100 p-5">
                    <div className="min-w-0">
                        <h2 className="text-lg font-semibold text-slate-950">
                            Últimos costos por proveedor
                        </h2>
                        <p className="mt-1 truncate text-sm text-slate-500">
                            {product.name}
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-sm font-semibold text-slate-600 transition hover:bg-slate-50"
                    >
                        Cerrar
                    </button>
                </header>

                <div className="p-5">
                    {history.length === 0 ? (
                        <div className="rounded-2xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                            No hay compras registradas para este producto.
                        </div>
                    ) : (
                        <div className="overflow-x-auto rounded-xl border border-slate-200">
                            <table className="min-w-full text-sm">
                                <thead>
                                    <tr className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        <th className="px-4 py-3">Proveedor</th>
                                        <th className="px-4 py-3 text-right">Último costo</th>
                                        <th className="px-4 py-3">Fecha</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {history.map((row) => (
                                        <tr key={`${row.supplier_id}-${row.purchase_id}`}>
                                            <td className="px-4 py-3 text-slate-950">
                                                <SupplierInfoPopover supplier={row} />
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-950">
                                                {formatCurrency(row.unit_cost, country)}
                                            </td>
                                            <td className="px-4 py-3 text-slate-600">
                                                {row.created_at_formatted ?? '-'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </section>
        </div>
    );
}

function NewSupplierModal({
    draft,
    error,
    processing,
    onChange,
    onCancel,
    onSubmit,
}: {
    draft: SupplierDraft;
    error: string;
    processing: boolean;
    onChange: (draft: SupplierDraft) => void;
    onCancel: () => void;
    onSubmit: (event: FormEvent) => void;
}) {
    function update(field: keyof SupplierDraft, value: string) {
        onChange({ ...draft, [field]: value });
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 p-4 backdrop-blur-sm">
            <form
                onSubmit={onSubmit}
                className="w-full max-w-xl rounded-2xl border border-slate-200 bg-white p-5 shadow-xl"
            >
                <h2 className="text-lg font-semibold text-slate-950">Nuevo proveedor</h2>

                <div className="mt-4 grid gap-3 md:grid-cols-2">
                    <SupplierField label="Nombre" value={draft.name} onChange={(value) => update('name', value)} required />
                    <SupplierField label="Persona de contacto" value={draft.contact_person} onChange={(value) => update('contact_person', value)} />
                    <SupplierField label="Email" type="email" value={draft.email} onChange={(value) => update('email', value)} />
                    <SupplierField label="Teléfono" value={draft.phone} onChange={(value) => update('phone', value)} />
                    <label className="block md:col-span-2">
                        <span className="text-sm font-medium text-slate-700">Dirección</span>
                        <input
                            value={draft.address}
                            onChange={(event) => update('address', event.target.value)}
                            className="mt-1 h-11 w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                        />
                    </label>
                </div>

                {error && (
                    <div className="mt-4 rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
                        {error}
                    </div>
                )}

                <div className="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={onCancel}
                        className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        Guardar proveedor
                    </button>
                </div>
            </form>
        </div>
    );
}

function SupplierField({
    label,
    value,
    onChange,
    type = 'text',
    required = false,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    type?: string;
    required?: boolean;
}) {
    return (
        <label className="block">
            <span className="text-sm font-medium text-slate-700">{label}</span>
            <input
                type={type}
                required={required}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="mt-1 h-11 w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
            />
        </label>
    );
}
