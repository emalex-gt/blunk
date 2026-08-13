import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import SupplierInfoPopover from '@/Components/SupplierInfoPopover';
import Toast from '@/Components/Toast';
import { getProductImageUrl } from '@/lib/cloudinary';
import { clearDraft, loadDraft, makeDraftKey, saveDraft } from '@/lib/draftStorage';
import { discardOperationDraft, listOperationDrafts, OperationDraftRecord, saveOperationDraft } from '@/lib/operationDrafts';
import { useToast } from '@/hooks/useToast';
import { formatCurrency } from '@/utils/currency';
import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { FormEvent, KeyboardEvent, useEffect, useMemo, useRef, useState } from 'react';

type Product = {
    id: number;
    name: string;
    code: string | null;
    barcode: string | null;
    category_name?: string | null;
    brand_name?: string | null;
    cost_price: string;
    sale_price?: string;
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
    payment_method: string;
    paid_from_cash: boolean;
    branch_id: number | null;
};

function isMeaningfulPurchaseDraft(draft: PurchaseDraft) {
    return (
        (draft.items?.length ?? 0) > 0 ||
        (draft.supplier_name ?? '').trim() !== '' ||
        (draft.note ?? '').trim() !== '' ||
        Boolean(draft.paid_from_cash) ||
        (draft.payment_method ?? 'cash') !== 'cash'
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
    const userId = (usePage().props.auth as { user?: { id?: number | null } } | undefined)?.user?.id ?? null;
    const country = business?.country ?? 'GT';
    const draftKey = useMemo(() => makeDraftKey('purchase', businessId, userId, active_branch?.id ?? null), [active_branch?.id, businessId, userId]);
    const [search, setSearch] = useState('');
    const [supplierName, setSupplierName] = useState('');
    const [selectedSupplier, setSelectedSupplier] = useState<Supplier | null>(null);
    const [note, setNote] = useState('');
    const [branchId, setBranchId] = useState<number | null>(active_branch?.id ?? null);
    const [paymentMethod, setPaymentMethod] = useState('cash');
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
    const [activeDraftId, setActiveDraftId] = useState<number | null>(null);
    const [savedDraftsOpen, setSavedDraftsOpen] = useState(false);
    const [savedDrafts, setSavedDrafts] = useState<OperationDraftRecord<PurchaseDraft>[]>([]);
    const [draftLoading, setDraftLoading] = useState(false);
    const [draftSaving, setDraftSaving] = useState(false);
    const [loadedProducts, setLoadedProducts] = useState<Product[]>(products);
    const [productResults, setProductResults] = useState<Product[]>(products);
    const [productSearchLoading, setProductSearchLoading] = useState(false);
    const [supplierResults, setSupplierResults] = useState<Supplier[]>([]);
    const [supplierSearchLoading, setSupplierSearchLoading] = useState(false);
    const [confirmPurchase, setConfirmPurchase] = useState<{
        supplier: Supplier | null;
        newSupplier: SupplierDraft | null;
        fromSupplierModal?: boolean;
    } | null>(null);
    const [purchaseDetailsExpanded, setPurchaseDetailsExpanded] = useState(true);
    const searchInputRef = useRef<HTMLInputElement>(null);
    const toast = useToast();

    const productsById = useMemo(
        () => new Map(loadedProducts.map((product) => [product.id, product])),
        [loadedProducts],
    );

    const supplierSuggestions = useMemo(() => {
        const term = supplierName.trim().toLowerCase();

        if (!term) {
            return suppliers.slice(0, 8);
        }

        return supplierResults.slice(0, 8);
    }, [supplierName, supplierResults, suppliers]);

    const filteredProducts = productResults;
    const paymentMethodLabels: Record<string, string> = {
        cash: 'Efectivo',
        card: 'Tarjeta',
        bank_transfer: 'Transferencia',
        check: 'Cheque',
        credit: 'Crédito',
        other: 'Otro',
    };
    const selectedBranch = useMemo(
        () => branches.find((branch) => branch.id === branchId) ?? active_branch ?? null,
        [active_branch, branchId, branches],
    );
    const canCompactPurchaseDetails = supplierName.trim() !== ''
        && (!branches_enabled || branches.length === 0 || branchId !== null);
    const purchaseDetailsCompact = !purchaseDetailsExpanded && canCompactPurchaseDetails;

    const total = useMemo(
        () =>
            cart.reduce(
                (sum, item) => sum + Number(item.quantity || 0) * Number(item.unit_cost || 0),
                0,
            ),
        [cart],
    );

    function addProduct(product: Product) {
        setLoadedProducts((current) => {
            if (current.some((item) => item.id === product.id)) {
                return current;
            }

            return [...current, product];
        });
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

    async function hydratePurchaseProducts(productIds: number[]): Promise<Product[]> {
        const ids = [...new Set(productIds.filter(Boolean))];

        if (ids.length === 0) {
            return [];
        }

        const response = await axios.get<{ products: Product[] }>(route('purchases.products.search'), {
            params: { ids: ids.join(','), limit: 30 },
            headers: { Accept: 'application/json' },
        });

        setLoadedProducts((current) => {
            const map = new Map(current.map((product) => [product.id, product]));
            response.data.products.forEach((product) => map.set(product.id, product));

            return Array.from(map.values());
        });

        return response.data.products;
    }

    async function hydrateSuppliers(supplierIds: number[]): Promise<Supplier[]> {
        const ids = [...new Set(supplierIds.filter(Boolean))];

        if (ids.length === 0) {
            return [];
        }

        const response = await axios.get<{ suppliers: Supplier[] }>(route('purchases.suppliers.search'), {
            params: { ids: ids.join(',') },
            headers: { Accept: 'application/json' },
        });

        setSupplierResults((current) => {
            const map = new Map(current.map((supplier) => [supplier.id, supplier]));
            response.data.suppliers.forEach((supplier) => map.set(supplier.id, supplier));

            return Array.from(map.values());
        });

        return response.data.suppliers;
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
            payment_method: paymentMethod,
            paid_from_cash: paidFromCash,
            branch_id: branchId,
        };
    }

    async function restorePurchaseDraft(draft: PurchaseDraft) {
        const missingIds = draft.items
            .map((item) => item.product_id)
            .filter((productId) => !productsById.has(productId));
        const fetchedProducts = await hydratePurchaseProducts(missingIds).catch(() => []);
        const productMap = new Map(productsById);
        fetchedProducts.forEach((product) => productMap.set(product.id, product));
        const restoredItems = draft.items
            .map((item) => {
                const product = productMap.get(item.product_id);

                return product
                    ? {
                        product,
                        quantity: item.quantity ?? '1',
                        unit_cost: item.unit_cost ?? String(product.cost_price ?? '0'),
                    }
                    : null;
            })
            .filter((item): item is CartItem => Boolean(item));

        if (restoredItems.length < draft.items.length) {
            toast.warning('Se descartaron productos inválidos del borrador.');
        }

        setCart(restoredItems);
        setSupplierName(draft.supplier_name ?? '');
        if (draft.selected_supplier_id) {
            const supplier = suppliers.find((supplier) => supplier.id === draft.selected_supplier_id)
                ?? supplierResults.find((supplier) => supplier.id === draft.selected_supplier_id)
                ?? (await hydrateSuppliers([draft.selected_supplier_id]).catch(() => []))[0]
                ?? null;

            setSelectedSupplier(supplier);
            if (supplier) {
                setSupplierName(supplier.name);
            } else {
                toast.warning('El proveedor del borrador ya no está disponible. Selecciona otro proveedor.');
            }
        } else {
            setSelectedSupplier(null);
        }
        setNote(draft.note ?? '');
        setPaymentMethod(draft.payment_method ?? 'cash');
        setPaidFromCash(Boolean((draft.payment_method ?? 'cash') === 'cash' && draft.paid_from_cash && hasOpenCashRegister));
        setBranchId(draft.branch_id ?? active_branch?.id ?? null);
        setPurchaseDetailsExpanded((draft.supplier_name ?? '').trim() === '');
    }

    function restoreSavedPurchaseDraft(draft: OperationDraftRecord<PurchaseDraft>) {
        if (draft.payload_version !== 1) {
            setMessage('Este borrador fue creado con una versión anterior y no se puede recuperar automáticamente.');
            toast.error('Este borrador fue creado con una versión anterior y no se puede recuperar automáticamente.');
            return;
        }

        if (isMeaningfulPurchaseDraft(buildPurchaseDraft()) && !window.confirm('Hay datos sin guardar en la pantalla actual. Si recuperas este borrador, se reemplazarán. ¿Deseas continuar?')) {
            return;
        }

        void restorePurchaseDraft(draft.payload);
        setActiveDraftId(draft.id);
        setSavedDraftsOpen(false);
        setMessage('Borrador recuperado.');
        toast.success('Borrador recuperado.');
    }

    function clearPurchaseState() {
        setSearch('');
        setSupplierName('');
        setSelectedSupplier(null);
        setNote('');
        setPaymentMethod('cash');
        setPaidFromCash(false);
        setBranchId(active_branch?.id ?? null);
        setCart([]);
        setMessage('');
        setActiveDraftId(null);
        setPurchaseDetailsExpanded(true);
        requestAnimationFrame(() => searchInputRef.current?.focus());
    }

    async function savePurchaseOperationDraft() {
        const payload = buildPurchaseDraft();

        if (!isMeaningfulPurchaseDraft(payload)) {
            setMessage('No hay datos para guardar como borrador.');
            toast.info('No hay datos para guardar como borrador.');
            return;
        }

        setDraftSaving(true);
        setMessage('');

        try {
            await saveOperationDraft({
                type: 'purchase',
                title: supplierName.trim() || `Compra pausada ${new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`,
                branch_id: branchId,
                supplier_id: selectedSupplier?.id ?? null,
                payload,
                payload_version: 1,
            });
            clearPurchaseDraftAndState();
            toast.success('Borrador guardado.');
        } catch (error) {
            const errorMessage = error instanceof Error ? error.message : 'No se pudo guardar el borrador.';
            setMessage(errorMessage);
            toast.error(errorMessage);
        } finally {
            setDraftSaving(false);
        }
    }

    async function openPurchaseDrafts() {
        setDraftLoading(true);
        setSavedDraftsOpen(true);
        setMessage('');

        try {
            const payload = await listOperationDrafts<PurchaseDraft>('purchase');
            setSavedDrafts(payload.drafts);
        } catch (error) {
            const errorMessage = error instanceof Error ? error.message : 'No se pudieron cargar los borradores.';
            setMessage(errorMessage);
            toast.error(errorMessage);
        } finally {
            setDraftLoading(false);
        }
    }

    async function discardPurchaseDraft(draft: OperationDraftRecord<PurchaseDraft>) {
        if (!window.confirm('¿Descartar este borrador?')) {
            return;
        }

        try {
            await discardOperationDraft(draft.id);
            setSavedDrafts((current) => current.filter((item) => item.id !== draft.id));
            if (activeDraftId === draft.id) {
                setActiveDraftId(null);
            }
            toast.success('Borrador descartado.');
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'No se pudo descartar el borrador.');
        }
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

    function submit(event?: FormEvent) {
        event?.preventDefault();

        if (!validatePurchaseBeforeSubmit()) {
            return;
        }

        const cleanSupplierName = supplierName.trim();
        const matchingSupplier = cleanSupplierName
            ? suppliers.find((supplier) => supplier.name.trim().toLowerCase() === cleanSupplierName.toLowerCase())
            : null;

        if (!selectedSupplier && matchingSupplier) {
            setConfirmPurchase({ supplier: matchingSupplier, newSupplier: null });
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

        setConfirmPurchase({ supplier: selectedSupplier, newSupplier: null });
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
            payment_method: paymentMethod,
            paid_from_cash: paymentMethod === 'cash' && paidFromCash,
            branch_id: branchId,
            draft_id: activeDraftId,
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
                setPurchaseDetailsExpanded(true);
                toast.error(errorMessage);

                if (options.fromSupplierModal) {
                    setSupplierModalOpen(true);
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
        setSupplierModalOpen(false);
        setConfirmPurchase({ supplier: null, newSupplier: cleanDraft, fromSupplierModal: true });
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
        branchId,
        draftKey,
        draftReady,
        note,
        paidFromCash,
        paymentMethod,
        restoreDraft,
        selectedSupplier,
        supplierName,
    ]);

    useEffect(() => {
        if (paymentMethod !== 'cash' && paidFromCash) {
            setPaidFromCash(false);
        }
    }, [paymentMethod, paidFromCash]);

    useEffect(() => {
        const term = search.trim();

        if (term.length < 2) {
            setProductResults([]);
            setProductSearchLoading(false);
            return;
        }

        const timer = window.setTimeout(() => {
            setProductSearchLoading(true);
            axios.get<{ products: Product[] }>(route('purchases.products.search'), {
                params: { q: term, limit: 30 },
                headers: { Accept: 'application/json' },
            })
                .then((response) => {
                    setProductResults(response.data.products);
                    setLoadedProducts((current) => {
                        const map = new Map(current.map((product) => [product.id, product]));
                        response.data.products.forEach((product) => map.set(product.id, product));

                        return Array.from(map.values());
                    });
                })
                .catch(() => setProductResults([]))
                .finally(() => setProductSearchLoading(false));
        }, 300);

        return () => window.clearTimeout(timer);
    }, [search]);

    useEffect(() => {
        const term = supplierName.trim();

        if (term.length < 2 || selectedSupplier?.name === supplierName) {
            setSupplierResults([]);
            setSupplierSearchLoading(false);
            return;
        }

        const timer = window.setTimeout(() => {
            setSupplierSearchLoading(true);
            axios.get<{ suppliers: Supplier[] }>(route('purchases.suppliers.search'), {
                params: { q: term },
                headers: { Accept: 'application/json' },
            })
                .then((response) => setSupplierResults(response.data.suppliers))
                .catch(() => setSupplierResults([]))
                .finally(() => setSupplierSearchLoading(false));
        }, 300);

        return () => window.clearTimeout(timer);
    }, [selectedSupplier?.name, supplierName]);

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-950">Compras</h2>}>
            <Head title="Registrar compra" />
            <Toast toasts={toast.toasts} onClose={toast.removeToast} />

            <div className="min-h-[calc(100vh-8rem)] overflow-x-hidden bg-[#f4f6fb]">
                <div className="mx-auto grid min-h-full max-w-[1800px] gap-5 p-4 sm:p-5 lg:grid-cols-[minmax(0,1fr)_440px] xl:grid-cols-[minmax(0,1fr)_480px] 2xl:grid-cols-[minmax(0,1fr)_600px]">
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
                                        onClick={openPurchaseDrafts}
                                        className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50"
                                    >
                                        Recuperar borrador
                                    </button>
                                    <button
                                        type="button"
                                        disabled={draftSaving}
                                        onClick={savePurchaseOperationDraft}
                                        className="rounded-xl border border-indigo-200 bg-white px-4 py-2 text-sm font-semibold text-indigo-700 shadow-sm hover:bg-indigo-50 disabled:opacity-50"
                                    >
                                        Guardar borrador
                                    </button>
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

                            {purchaseDetailsCompact ? (
                                <div className="rounded-2xl border border-indigo-100 bg-indigo-50/60 p-3">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <div className="text-[11px] font-semibold uppercase text-indigo-500">
                                                Datos de compra
                                            </div>
                                            <div className="mt-1 truncate text-sm font-semibold text-slate-950">
                                                Proveedor: {supplierName.trim()}
                                            </div>
                                            <div className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-600">
                                                <span>Pago: <span className="font-semibold">{paymentMethodLabels[paymentMethod] ?? paymentMethod}</span></span>
                                                {selectedBranch && (
                                                    <span>Destino: <span className="font-semibold">{selectedBranch.name}</span></span>
                                                )}
                                                <span>Desde caja: <span className="font-semibold">{paymentMethod === 'cash' && paidFromCash ? 'Sí' : 'No'}</span></span>
                                            </div>
                                            {note.trim() && (
                                                <div className="mt-1 line-clamp-1 text-xs text-slate-500">
                                                    Nota: {note.trim()}
                                                </div>
                                            )}
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => setPurchaseDetailsExpanded(true)}
                                            className="shrink-0 rounded-xl border border-indigo-200 bg-white px-3 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50"
                                        >
                                            Editar datos
                                        </button>
                                    </div>
                                </div>
                            ) : (
                                <>
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
                                        <div>
                                            <label className="text-sm font-medium text-slate-700">Forma de pago</label>
                                            <select
                                                value={paymentMethod}
                                                onChange={(event) => setPaymentMethod(event.target.value)}
                                                className="mt-1 h-11 w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                            >
                                                <option value="cash">Efectivo</option>
                                                <option value="card">Tarjeta</option>
                                                <option value="bank_transfer">Transferencia</option>
                                                <option value="check">Cheque</option>
                                                <option value="credit">Crédito</option>
                                                <option value="other">Otro</option>
                                            </select>
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
                                            disabled={!hasOpenCashRegister || paymentMethod !== 'cash'}
                                            onChange={(event) => setPaidFromCash(event.target.checked)}
                                            className="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50"
                                        />
                                        <span>
                                            <span className="block font-semibold text-slate-800">¿Pagar desde caja?</span>
                                            <span className="block text-xs text-slate-500">
                                                {hasOpenCashRegister
                                                    ? (paymentMethod === 'cash' ? 'La compra reducirá el efectivo esperado de caja.' : 'Solo aplica para pagos en efectivo.')
                                                    : 'No hay caja abierta'}
                                            </span>
                                        </span>
                                    </label>

                                    {canCompactPurchaseDetails && (
                                        <div className="mt-3 flex justify-end">
                                            <button
                                                type="button"
                                                onClick={() => setPurchaseDetailsExpanded(false)}
                                                className="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800"
                                            >
                                                Guardar datos
                                            </button>
                                        </div>
                                    )}
                                </>
                            )}

                            <input
                                ref={searchInputRef}
                                autoFocus
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                onKeyDown={handleSearchKeyDown}
                                placeholder="Buscar producto por nombre, código o código de barras"
                                className="mt-3 h-14 w-full rounded-2xl border border-slate-200 bg-white px-5 text-lg font-medium text-slate-900 shadow-sm outline-none transition focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                            />
                            {productSearchLoading && (
                                <p className="mt-2 text-sm font-medium text-slate-500">Buscando productos...</p>
                            )}
                        </div>

                        <div className="p-4">
                            {!productSearchLoading && search.trim().length >= 2 && filteredProducts.length === 0 && (
                                <div className="mb-3 rounded-xl border border-dashed border-slate-300 p-4 text-center text-sm text-slate-500">
                                    Sin resultados.
                                </div>
                            )}
                            {!productSearchLoading && search.trim().length < 2 && filteredProducts.length === 0 && (
                                <div className="mb-3 rounded-xl border border-dashed border-slate-300 p-4 text-center text-sm text-slate-500">
                                    Escribe al menos 2 caracteres para buscar productos.
                                </div>
                            )}
                            <div className="grid grid-cols-1 gap-3 min-[1180px]:grid-cols-2">
                                {filteredProducts.map((product) => (
                                    <div
                                        key={product.id}
                                        role="button"
                                        tabIndex={0}
                                        onClick={() => addProduct(product)}
                                        onKeyDown={(event) => handleProductCardKeyDown(event, product)}
                                        className="relative flex min-h-[148px] cursor-pointer gap-4 rounded-2xl border border-slate-200 bg-white p-4 text-left shadow-[0_4px_18px_rgba(15,23,42,0.05)] transition-all duration-200 hover:-translate-y-0.5 hover:border-indigo-200 hover:shadow-[0_12px_28px_rgba(15,23,42,0.10)] focus:outline-none focus:ring-4 focus:ring-indigo-100"
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
                                                className="h-24 w-24 shrink-0 rounded-xl object-cover"
                                            />
                                        ) : null}

                                        <div className="flex min-w-0 flex-1 flex-col justify-between gap-3">
                                            <div>
                                                <h3 className="line-clamp-2 pr-7 text-sm font-semibold leading-5 text-slate-950 sm:text-base">
                                                    {product.name}
                                                </h3>
                                                <div className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500">
                                                    <span>Código: <span className="font-semibold text-slate-700">{product.code || '-'}</span></span>
                                                    <span>Código de barras: <span className="font-semibold text-slate-700">{product.barcode || '-'}</span></span>
                                                    <span>Stock: <span className="font-semibold text-slate-700">{product.stock}</span></span>
                                                </div>
                                            </div>
                                            <div className="flex flex-wrap items-end justify-between gap-3">
                                                <div className="space-y-1 text-xs text-slate-500">
                                                    <div>
                                                        Costo actual:{' '}
                                                        <span className="font-semibold text-slate-900">
                                                            {formatCurrency(product.cost_price, country)}
                                                        </span>
                                                    </div>
                                                    {product.supplier_costs?.[0] && (
                                                        <div>
                                                            Último costo:{' '}
                                                            <span className="font-semibold text-slate-700">
                                                                {formatCurrency(product.supplier_costs[0].unit_cost, country)}
                                                            </span>
                                                        </div>
                                                    )}
                                                    {product.sale_price && (
                                                        <div>
                                                            Precio venta:{' '}
                                                            <span className="font-semibold text-slate-700">
                                                                {formatCurrency(product.sale_price, country)}
                                                            </span>
                                                        </div>
                                                    )}
                                                </div>
                                                <button
                                                    type="button"
                                                    onClick={(event) => {
                                                        event.stopPropagation();
                                                        addProduct(product);
                                                    }}
                                                    className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
                                                >
                                                    Agregar
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </section>

                    <form
                        onSubmit={(event) => event.preventDefault()}
                        className="flex min-h-0 flex-col rounded-2xl border border-slate-200 bg-white shadow-[0_8px_30px_rgba(15,23,42,0.08)] lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)]"
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

                            <div className="space-y-3">
                                {cart.map((item) => {
                                    const lineTotal = Number(item.quantity || 0) * Number(item.unit_cost || 0);
                                    const quantityMessage = quantityError(item.quantity);

                                    return (
                                        <div
                                            key={item.product.id}
                                            className="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm"
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="min-w-0">
                                                    <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                                        {item.product.barcode || item.product.code || 'Código'}
                                                    </div>
                                                    <div className="line-clamp-2 text-sm font-semibold leading-5 text-slate-950">
                                                        {item.product.name}
                                                    </div>
                                                </div>
                                                <button
                                                    type="button"
                                                    title="Eliminar"
                                                    onClick={() => removeItem(item.product.id)}
                                                    className="shrink-0 rounded-lg px-2 py-1 text-lg leading-none text-red-500 hover:bg-red-50 hover:text-red-700"
                                                >
                                                    ×
                                                </button>
                                            </div>
                                            <div className="mt-3 grid gap-2 sm:grid-cols-[116px_minmax(0,1fr)_auto] lg:grid-cols-[112px_minmax(0,1fr)] xl:grid-cols-[116px_minmax(0,1fr)_auto]">
                                                <div>
                                                    <label className="mb-1 block text-[11px] font-semibold uppercase text-slate-400">
                                                        Cantidad
                                                    </label>
                                                    <div className="flex h-10 overflow-hidden rounded-xl border border-slate-200 bg-white">
                                                        <button
                                                            type="button"
                                                            onClick={() => updateItem(item.product.id, 'quantity', String(Math.max(1, Number(item.quantity || 1) - 1)))}
                                                            className="w-9 text-sm font-bold text-slate-500 hover:bg-slate-50"
                                                        >
                                                            -
                                                        </button>
                                                        <input
                                                            type="number"
                                                            min="1"
                                                            step="1"
                                                            inputMode="numeric"
                                                            pattern="[0-9]*"
                                                            value={item.quantity}
                                                            onChange={(event) => updateItem(item.product.id, 'quantity', event.target.value)}
                                                            onWheel={(event) => event.currentTarget.blur()}
                                                            className="h-full min-w-0 flex-1 border-0 text-center text-sm font-semibold text-slate-900 focus:ring-0"
                                                        />
                                                        <button
                                                            type="button"
                                                            onClick={() => updateItem(item.product.id, 'quantity', String(Number(item.quantity || 0) + 1))}
                                                            className="w-9 text-sm font-bold text-slate-500 hover:bg-slate-50"
                                                        >
                                                            +
                                                        </button>
                                                    </div>
                                                    {quantityMessage && (
                                                        <div className="mt-1 text-xs font-semibold text-red-600">
                                                            {quantityMessage}
                                                        </div>
                                                    )}
                                                </div>
                                                <div>
                                                    <label className="mb-1 block text-[11px] font-semibold uppercase text-slate-400">
                                                        Costo unitario
                                                    </label>
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        value={item.unit_cost}
                                                        onChange={(event) => updateItem(item.product.id, 'unit_cost', event.target.value)}
                                                        className="h-10 w-full rounded-xl border-slate-200 text-right text-sm font-semibold text-slate-900 focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                                    />
                                                </div>
                                                <div className="rounded-xl bg-slate-50 px-3 py-2 text-right sm:col-span-2 lg:col-span-2 xl:col-span-1">
                                                    <div className="text-[11px] font-semibold uppercase text-slate-400">
                                                        Subtotal
                                                    </div>
                                                    <div className="whitespace-nowrap text-sm font-semibold text-slate-950">
                                                        {formatCurrency(lineTotal, country)}
                                                    </div>
                                                </div>
                                            </div>
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
                                type="button"
                                onClick={() => submit()}
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

            {confirmPurchase && (
                <div className="fixed inset-0 z-[75] flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
                    <section className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl">
                        <h2 className="text-lg font-semibold text-slate-950">
                            ¿Has revisado la información de esta compra?
                        </h2>
                        <p className="mt-2 text-sm text-slate-600">
                            Confirma que proveedor, productos, cantidades, costos y totales fueron revisados antes de guardar.
                        </p>
                        <div className="mt-5 flex justify-end gap-2">
                            <button
                                type="button"
                                disabled={processing}
                                onClick={() => setConfirmPurchase(null)}
                                className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                Cancelar
                            </button>
                            <button
                                type="button"
                                disabled={processing}
                                onClick={() => {
                                    const confirmation = confirmPurchase;
                                    setConfirmPurchase(null);
                                    submitPurchase(confirmation.supplier, confirmation.newSupplier, {
                                        fromSupplierModal: confirmation.fromSupplierModal,
                                    });
                                }}
                                className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50"
                            >
                                Sí, guardar compra
                            </button>
                        </div>
                    </section>
                </div>
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
                                    void restorePurchaseDraft(restoreDraft);
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

            {savedDraftsOpen && (
                <div className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
                    <section className="w-full max-w-3xl rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-lg font-semibold text-slate-950">Borradores de compra</h2>
                            <button type="button" onClick={() => setSavedDraftsOpen(false)} className="rounded-xl px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">
                                Cerrar
                            </button>
                        </div>
                        <div className="mt-4 space-y-3">
                            {draftLoading && <div className="rounded-xl border border-slate-200 p-4 text-sm text-slate-500">Cargando borradores...</div>}
                            {!draftLoading && savedDrafts.length === 0 && (
                                <div className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                                    No hay borradores guardados.
                                </div>
                            )}
                            {!draftLoading && savedDrafts.map((draft) => (
                                <div key={draft.id} className="grid gap-3 rounded-xl border border-slate-200 p-4 md:grid-cols-[1fr_auto]">
                                    <div>
                                        <div className="text-sm font-semibold text-slate-900">{draft.title ?? 'Borrador de compra'}</div>
                                        <div className="mt-1 text-xs text-slate-500">
                                            {draft.supplier?.name ?? draft.payload.supplier_name ?? 'Sin proveedor'} · {draft.item_count} productos · Total estimado {formatCurrency(Number(draft.total ?? 0), country)} · Usuario: {draft.user?.name ?? '-'}
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <button type="button" onClick={() => restoreSavedPurchaseDraft(draft)} className="rounded-xl bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                                            Continuar borrador
                                        </button>
                                        <button type="button" onClick={() => discardPurchaseDraft(draft)} className="rounded-xl px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50">
                                            Descartar
                                        </button>
                                    </div>
                                </div>
                            ))}
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
