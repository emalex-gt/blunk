import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Toast from '@/Components/Toast';
import { getProductImageUrl } from '@/lib/cloudinary';
import { useToast } from '@/hooks/useToast';
import { t } from '@/lib/i18n';
import { clearDraft, loadDraft, makeDraftKey, saveDraft } from '@/lib/draftStorage';
import { formatCurrency } from '@/utils/currency';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    FormEvent,
    KeyboardEvent,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';

type Product = {
    id: number;
    category_id: number | null;
    name: string;
    code: string | null;
    barcode: string | null;
    cost_price: string;
    sale_price: string;
    stock: number;
    reserved_stock?: number;
    available_stock?: number;
    min_stock: number;
    location: string | null;
    image_url: string | null;
    prices?: ProductPrice[];
    branch_price_applied?: boolean;
};

type ProductPrice = {
    id: number | null;
    product_id: number;
    price_type_id: number;
    price: string | number;
};

type PriceType = {
    id: number;
    name: string;
    is_default: boolean;
};

type Category = {
    id: number;
    name: string;
};

type Customer = {
    id: number;
    name: string;
    doc_type: string | null;
    doc_number: string | null;
    tax_condition: string | null;
    address: string | null;
    phone: string | null;
    country: string;
    is_final_consumer?: boolean;
    name_locked?: boolean;
    tax_lookup_verified_at?: string | null;
};

type CustomerForm = {
    id: number | null;
    consumidor_final: boolean;
    doc_type: string;
    doc_number: string;
    tax_condition: string;
    name: string;
    address: string;
    phone: string;
    country: string;
    name_locked: boolean;
    tax_lookup_verified_at: string | null;
};

type PaymentLine = {
    method: string;
    amount: string;
    reference: string;
    details: PaymentDetails;
};

type PaymentDetails = {
    authorization: string;
    bank: string;
    transfer_reference: string;
    check_number: string;
    mercadopago_reference: string;
};

type CartItem = {
    product: Product;
    quantity: string;
    unit_price: string;
    original_price: string;
    price_type_id: number | null;
    price_source: 'price_list' | 'last_customer_price' | 'manual';
    manual_price: boolean;
    price_warning: string | null;
    credit_line_id?: number | null;
    max_quantity?: number | null;
    credit_receipt_number?: string | null;
    locked_credit_line?: boolean;
};

type CheckoutType = 'invoice' | 'receipt' | 'credit';

type CreditInvoicePayload = {
    source: 'credit';
    customer: CustomerForm;
    lines: {
        credit_line_id: number;
        product_id: number;
        quantity: number;
        max_quantity: number;
        unit_price: number;
        receipt_number: string;
    }[];
};

type SaleDiscount = {
    type: 'fixed' | 'percent';
    value: string;
    reason: string;
};

type HeldSale = {
    cart: CartItem[];
    customer: CustomerForm;
};

type PosDraft = {
    cart: {
        product_id: number;
        quantity: number | string;
        unit_price?: string;
        original_price?: string;
        price_type_id?: number | null;
        price_source?: 'price_list' | 'last_customer_price' | 'manual';
        manual_price?: boolean;
    }[];
    customer: CustomerForm;
    note: string;
    payments: PaymentLine[];
    document_type: 'invoice' | 'receipt';
    selected_category_id: number | null;
    main_payment_method: string;
    split_payment: boolean;
    discount_type: 'fixed' | 'percent' | null;
    discount_value: string;
    discount_reason: string;
};

const recentProductsKey = 'pos_recent_products';
const heldSaleKey = 'pos_held_sale';

const basePaymentMethods = [
    { value: 'cash', label: 'Efectivo' },
    { value: 'card', label: 'Tarjeta' },
    { value: 'transfer', label: 'Transferencia' },
    { value: 'check', label: 'Cheque' },
];

const arDocTypes = ['DNI', 'CUIT', 'CUIL', 'Consumidor Final'];
const arTaxConditions = ['Consumidor Final', 'Monotributo', 'Responsable Inscripto', 'Exento'];

const emptyPaymentDetails = (): PaymentDetails => ({
    authorization: '',
    bank: '',
    transfer_reference: '',
    check_number: '',
    mercadopago_reference: '',
});

const paymentLine = (method: string, amount: string): PaymentLine => ({
    method,
    amount,
    reference: '',
    details: emptyPaymentDetails(),
});

function loadJson<T>(key: string, fallback: T): T {
    try {
        const value = localStorage.getItem(key);

        return value ? (JSON.parse(value) as T) : fallback;
    } catch {
        return fallback;
    }
}

function emptyCustomer(country: string): CustomerForm {
    return {
        id: null,
        consumidor_final: country === 'GT',
        doc_type: country === 'AR' ? 'DNI' : 'CF',
        doc_number: country === 'GT' ? 'CF' : '',
        tax_condition: country === 'AR' ? 'Consumidor Final' : '',
        name: country === 'GT' ? 'Consumidor Final' : '',
        address: '',
        phone: '',
        country,
        name_locked: false,
        tax_lookup_verified_at: null,
    };
}

function sanitizeNit(value: string) {
    return value.replace(/[\s-]/g, '').replace(/[^A-Za-z0-9]/g, '');
}

async function readJsonResponse(response: globalThis.Response) {
    const text = await response.text();

    if (!text) {
        return null;
    }

    try {
        return JSON.parse(text);
    } catch {
        return {
            message: 'No se pudo consultar el NIT. Intenta nuevamente.',
        };
    }
}

function cleanPaymentDetails(method: string, details: PaymentDetails): Partial<PaymentDetails> {
    const allowed: Record<string, (keyof PaymentDetails)[]> = {
        card: ['authorization'],
        transfer: ['bank', 'transfer_reference'],
        check: ['bank', 'check_number'],
        mercadopago: ['mercadopago_reference'],
    };

    return (allowed[method] ?? []).reduce<Partial<PaymentDetails>>((payload, key) => {
        const value = details[key].trim();

        if (value !== '') {
            payload[key] = value;
        }

        return payload;
    }, {});
}

function isMeaningfulCustomer(customer: CustomerForm) {
    if (customer.country === 'GT' && customer.consumidor_final) {
        return false;
    }

    return Boolean(
        customer.id ||
        customer.doc_number.trim() ||
        customer.name.trim() ||
        customer.address.trim() ||
        customer.phone.trim(),
    );
}

const integerQuantityMessage = 'La cantidad debe ser un número entero.';

function normalizeQuantity(value: number | string | null | undefined) {
    return String(value ?? '').trim();
}

function sanitizeQuantityInput(value: number | string | null | undefined): string | null {
    const raw = String(value ?? '');

    if (/[.,]/.test(raw)) {
        return null;
    }

    return raw.replace(/\D/g, '');
}

function quantityError(value: number | string | null | undefined) {
    return /^[1-9]\d*$/.test(normalizeQuantity(value)) ? null : integerQuantityMessage;
}

function quantityNumber(value: number | string | null | undefined) {
    return quantityError(value) ? 0 : Number(normalizeQuantity(value));
}

function roundMoney(value: number) {
    return Math.round(value * 100) / 100;
}

function cartSubtotal(items: CartItem[]) {
    return roundMoney(items.reduce(
        (sum, item) => sum + Number(item.unit_price || item.product.sale_price) * quantityNumber(item.quantity),
        0,
    ));
}

function priceFromList(product: Product, priceTypeId: number | null, defaultPriceTypeId: number | null) {
    const selectedPrice = priceTypeId
        ? product.prices?.find((price) => Number(price.price_type_id) === Number(priceTypeId))
        : null;

    if (selectedPrice) {
        return {
            price: String(selectedPrice.price),
            priceTypeId,
            warning: null,
        };
    }

    const defaultPrice = defaultPriceTypeId
        ? product.prices?.find((price) => Number(price.price_type_id) === Number(defaultPriceTypeId))
        : null;

    return {
        price: String(defaultPrice?.price ?? product.sale_price ?? '0'),
        priceTypeId: defaultPrice?.price_type_id ?? priceTypeId ?? defaultPriceTypeId,
        warning: selectedPrice === undefined && priceTypeId && priceTypeId !== defaultPriceTypeId
            ? 'Usando precio de lista predeterminada.'
            : null,
    };
}

function priceSourceLabel(source: CartItem['price_source']) {
    return {
        price_list: 'Lista',
        last_customer_price: 'Último cliente',
        manual: 'Manual',
    }[source];
}

function productCost(product: Product) {
    const cost = Number(product.cost_price ?? 0);

    return Number.isFinite(cost) ? cost : 0;
}

function minimumManualPrice(product: Product, minMarginPercent: number) {
    return roundMoney(productCost(product) * (1 + Math.max(0, minMarginPercent) / 100));
}

function manualPriceError(item: CartItem, minMarginPercent: number) {
    if (!item.manual_price) {
        return null;
    }

    const price = Number(item.unit_price);

    if (!Number.isFinite(price) || price <= 0) {
        return 'Este precio no está permitido.';
    }

    const minimum = minimumManualPrice(item.product, minMarginPercent);

    if (minimum > 0 && price < minimum) {
        return 'Este precio no está permitido.';
    }

    return null;
}

function calculateDiscountAmount(discount: SaleDiscount | null, subtotal: number) {
    if (!discount) {
        return 0;
    }

    const value = Number(discount.value);

    if (!Number.isFinite(value) || value <= 0) {
        return 0;
    }

    return roundMoney(discount.type === 'percent' ? subtotal * (value / 100) : value);
}

function discountError(discount: SaleDiscount | null, subtotal: number) {
    if (!discount) {
        return null;
    }

    const value = Number(discount.value);

    if (!Number.isFinite(value) || value <= 0) {
        return 'Ingresa un valor de descuento válido.';
    }

    if (discount.reason.trim() === '') {
        return 'El motivo del descuento es obligatorio.';
    }

    if (calculateDiscountAmount(discount, subtotal) >= subtotal) {
        return 'El descuento no puede ser mayor o igual al total.';
    }

    return null;
}

function isMeaningfulPosDraft(draft: PosDraft) {
    return (draft.cart?.length ?? 0) > 0
        || (draft.customer ? isMeaningfulCustomer(draft.customer) : false)
        || Boolean(draft.discount_type && draft.discount_value);
}

export default function POS({
    products,
    categories,
    customers,
    hasOpenCashRegister,
    fel,
    price_types = [],
    default_price_type_id = null,
    price_settings = { allow_manual_price: false, remember_last_customer_product_price: false },
    available_document_types = ['receipt'],
    credit_available = false,
    credit_invoice = null,
    use_product_images = true,
}: {
    products: Product[];
    categories: Category[];
    customers: Customer[];
    hasOpenCashRegister: boolean;
    price_types?: PriceType[];
    default_price_type_id?: number | null;
    price_settings?: {
        allow_manual_price: boolean;
        manual_price_min_margin_percent?: number;
        can_use_manual_price?: boolean;
        remember_last_customer_product_price: boolean;
    };
    available_document_types?: ('invoice' | 'receipt')[];
    credit_available?: boolean;
    credit_invoice?: CreditInvoicePayload | null;
    fel?: {
        module_enabled: boolean;
        enabled: boolean;
        configured: boolean;
        available: boolean;
        provider: string;
        environment: string;
    };
    use_product_images?: boolean;
}) {
    const pageProps = usePage().props as {
        business?: { id?: number | null; country?: string | null } | null;
        current_business_id?: number | null;
        auth?: {
            user?: { is_super_admin?: boolean | null } | null;
            permissions?: string[];
        };
        enabled_modules?: string[];
        branches_enabled?: boolean;
        active_branch?: { id: number; name: string; code: string | null } | null;
    };
    const business = pageProps.business ?? null;
    const businessId = pageProps.current_business_id ?? business?.id ?? null;
    const canApplyDiscount = (pageProps.enabled_modules ?? []).includes('discounts')
        && (Boolean(pageProps.auth?.user?.is_super_admin)
            || (pageProps.auth?.permissions ?? []).includes('sales.discount.apply'));
    const canUseManualPrice = Boolean(price_settings.allow_manual_price)
        && (Boolean(price_settings.can_use_manual_price)
            || Boolean(pageProps.auth?.user?.is_super_admin)
            || (pageProps.auth?.permissions ?? []).includes('pos.manual_price'));
    const manualPriceMinMargin = Number(price_settings.manual_price_min_margin_percent ?? 0);
    const country = business?.country ?? 'GT';
    const draftKey = useMemo(() => makeDraftKey('pos', businessId), [businessId]);
    const [search, setSearch] = useState('');
    const [selectedCategoryId, setSelectedCategoryId] = useState<number | null>(null);
    const [cart, setCart] = useState<CartItem[]>([]);
    const [message, setMessage] = useState('');
    const [errorMessage, setErrorMessage] = useState('');
    const [recentProducts, setRecentProducts] = useState<Product[]>([]);
    const [hasHeldSale, setHasHeldSale] = useState(false);
    const [showCheckoutModal, setShowCheckoutModal] = useState(false);
    const [splitPayment, setSplitPayment] = useState(false);
    const [mainPaymentMethod, setMainPaymentMethod] = useState('cash');
    const [documentType, setDocumentType] = useState<CheckoutType>('receipt');
    const [creditProcessing, setCreditProcessing] = useState(false);
    const [openingFelPrint, setOpeningFelPrint] = useState(false);
    const [nitLookupLoading, setNitLookupLoading] = useState(false);
    const [nitLookupMessage, setNitLookupMessage] = useState('');
    const [payments, setPayments] = useState<PaymentLine[]>([
        paymentLine('cash', '0.00'),
    ]);
    const [restoreDraft, setRestoreDraft] = useState<PosDraft | null>(null);
    const [draftReady, setDraftReady] = useState(false);
    const [showClearSaleModal, setShowClearSaleModal] = useState(false);
    const [showDiscountModal, setShowDiscountModal] = useState(false);
    const [manualPriceProductId, setManualPriceProductId] = useState<number | null>(null);
    const [discount, setDiscount] = useState<SaleDiscount | null>(null);
    const [discountForm, setDiscountForm] = useState<SaleDiscount>({
        type: 'fixed',
        value: '',
        reason: '',
    });
    const [discountFormError, setDiscountFormError] = useState('');
    const toast = useToast();
    const searchInputRef = useRef<HTMLInputElement>(null);
    const cartRef = useRef<CartItem[]>([]);
    const messageTimerRef = useRef<number | null>(null);
    const tokenPrewarmStartedRef = useRef(false);
    const creditInvoiceLoadedRef = useRef(false);

    const { data, setData, post, processing, reset, transform, errors } = useForm<{
        note: string;
        customer: CustomerForm;
        document_type: 'invoice' | 'receipt';
        payments: { method: string; amount: number; reference: string | null; details: Partial<PaymentDetails> }[];
        items: {
            product_id: number;
            quantity: number;
            price_type_id?: number | null;
            unit_price?: number;
            price_source?: 'price_list' | 'last_customer_price' | 'manual';
            manual_price?: boolean;
            credit_line_id?: number | null;
        }[];
        discount: { type: 'fixed' | 'percent'; value: number; reason: string } | null;
    }>({
        note: '',
        customer: emptyCustomer(country),
        document_type: 'receipt',
        payments: [],
        items: [],
        discount: null,
    });
    const availableCheckoutTypes = useMemo<CheckoutType[]>(
        () => [...available_document_types, ...(credit_available ? ['credit' as const] : [])],
        [available_document_types, credit_available],
    );
    const availableDocumentTypesKey = availableCheckoutTypes.join('|');
    const singleDocumentType = availableCheckoutTypes.length === 1 ? availableCheckoutTypes[0] : null;
    const effectiveCheckoutType = singleDocumentType ?? documentType;
    const effectiveDocumentType: 'invoice' | 'receipt' = effectiveCheckoutType === 'credit' ? 'receipt' : effectiveCheckoutType;
    const noAvailableDocumentTypes = availableCheckoutTypes.length === 0;

    useEffect(() => {
        if (singleDocumentType) {
            setDocumentType(singleDocumentType);
            return;
        }

        if (availableCheckoutTypes.length > 0 && !availableCheckoutTypes.includes(documentType)) {
            setDocumentType(availableCheckoutTypes[0]);
        }
    }, [availableCheckoutTypes, availableDocumentTypesKey, documentType, singleDocumentType]);

    useEffect(() => {
        if (tokenPrewarmStartedRef.current || country !== 'GT' || !fel?.module_enabled || !fel?.enabled) {
            return;
        }

        tokenPrewarmStartedRef.current = true;
        const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

        void fetch(route('sales.fel.prewarm-token'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: '{}',
        }).catch(() => undefined);
    }, [country, fel?.enabled, fel?.module_enabled]);

    const paymentMethods = useMemo(
        () =>
            country === 'AR'
                ? [...basePaymentMethods, { value: 'mercadopago', label: 'MercadoPago' }]
                : basePaymentMethods,
        [country],
    );

    const filteredProducts = useMemo(() => {
        const value = search.toLowerCase().trim();
        const categoryProducts = selectedCategoryId
            ? products.filter((product) => product.category_id === selectedCategoryId)
            : products;

        if (!value) {
            return categoryProducts.slice(0, 24);
        }

        return categoryProducts
            .filter((product) =>
                [product.name, product.code, product.barcode]
                    .filter(Boolean)
                    .some((field) => field!.toLowerCase().includes(value)),
            )
            .slice(0, 24);
    }, [products, search, selectedCategoryId]);

    const productsById = useMemo(
        () => new Map(products.map((product) => [product.id, product])),
        [products],
    );

    const currentRecentProducts = useMemo(
        () =>
            recentProducts
                .map((product) => productsById.get(product.id))
                .filter((product): product is Product => Boolean(product))
                .slice(0, 8),
        [productsById, recentProducts],
    );

    useEffect(() => {
        if (!credit_invoice || creditInvoiceLoadedRef.current || productsById.size === 0) {
            return;
        }

        const creditItems = credit_invoice.lines
            .map((line) => {
                const product = productsById.get(line.product_id);

                if (!product) {
                    return null;
                }

                return {
                    product,
                    quantity: String(line.quantity),
                    unit_price: String(line.unit_price),
                    original_price: String(line.unit_price),
                    price_type_id: null,
                    price_source: 'price_list' as const,
                    manual_price: false,
                    price_warning: null,
                    credit_line_id: line.credit_line_id,
                    max_quantity: line.max_quantity,
                    credit_receipt_number: line.receipt_number,
                    locked_credit_line: true,
                };
            })
            .filter(Boolean) as CartItem[];

        if (creditItems.length === 0) {
            return;
        }

        creditInvoiceLoadedRef.current = true;
        setCart(creditItems);
        cartRef.current = creditItems;
        setData('customer', credit_invoice.customer);
        setDiscount(null);
        setDocumentType(singleDocumentType ?? (available_document_types[0] ?? 'receipt'));
        showMessage('Facturando productos a crédito.');
    }, [available_document_types, credit_invoice, productsById, setData, singleDocumentType]);

    const customerMatches = useMemo(() => {
        const value = [
            data.customer.doc_number,
            data.customer.name,
        ].join(' ').toLowerCase().trim();

        if (!value || data.customer.consumidor_final) {
            return [];
        }

        return customers
            .filter((customer) =>
                [customer.name, customer.doc_number]
                    .filter(Boolean)
                    .some((field) => field!.toLowerCase().includes(value)),
            )
            .slice(0, 5);
    }, [customers, data.customer.consumidor_final, data.customer.doc_number, data.customer.name]);

    const cartItemsCount = useMemo(
        () => cart.reduce((sum, item) => sum + quantityNumber(item.quantity), 0),
        [cart],
    );

    const subtotalBeforeDiscount = useMemo(() => cartSubtotal(cart), [cart]);
    const activeDiscountError = useMemo(
        () => discountError(discount, subtotalBeforeDiscount),
        [discount, subtotalBeforeDiscount],
    );
    const discountAmount = useMemo(
        () => activeDiscountError ? 0 : calculateDiscountAmount(discount, subtotalBeforeDiscount),
        [activeDiscountError, discount, subtotalBeforeDiscount],
    );
    const total = useMemo(
        () => roundMoney(Math.max(0, subtotalBeforeDiscount - discountAmount)),
        [discountAmount, subtotalBeforeDiscount],
    );

    const paidTotal = useMemo(
        () =>
            (splitPayment ? payments : [{ method: mainPaymentMethod, amount: total.toFixed(2), reference: '' }])
                .reduce((sum, payment) => sum + Number(payment.amount || 0), 0),
        [mainPaymentMethod, payments, splitPayment, total],
    );

    const pendingAmount = Number((total - paidTotal).toFixed(2));
    const paymentIsBalanced = Math.round(paidTotal * 100) === Math.round(total * 100);
    const hasInvalidCartQuantities = useMemo(
        () => cart.some((item) => Boolean(quantityError(item.quantity))),
        [cart],
    );

    function validateCartQuantities() {
        if (!hasInvalidCartQuantities) {
            return true;
        }

        showError(integerQuantityMessage);
        toast.error(integerQuantityMessage);

        return false;
    }

    function validateCartPrices() {
        if (cart.some((item) => item.manual_price) && !canUseManualPrice) {
            const message = 'No tienes permiso para aplicar precio manual.';
            showError(message);
            toast.error(message);

            return false;
        }

        const error = cart
            .map((item) => manualPriceError(item, manualPriceMinMargin))
            .find(Boolean);

        if (!error) {
            return true;
        }

        showError(error);
        toast.error(error);

        return false;
    }

    function validateDiscount() {
        if (!discount) {
            return true;
        }

        if (cart.some((item) => item.credit_line_id)) {
            const error = 'No se puede aplicar descuento general al facturar productos a crédito.';
            showError(error);
            toast.error(error);
            return false;
        }

        const error = discountError(discount, subtotalBeforeDiscount);

        if (!error) {
            return true;
        }

        showError(error);
        toast.error(error);

        return false;
    }

    function focusSearch() {
        requestAnimationFrame(() => {
            searchInputRef.current?.focus();
            searchInputRef.current?.select();
        });
    }

    function buildDraft(): PosDraft {
        return {
            cart: cart.map((item) => ({
                product_id: item.product.id,
                quantity: item.quantity,
                unit_price: item.unit_price,
                original_price: item.original_price,
                price_type_id: item.price_type_id,
                price_source: item.price_source,
                manual_price: item.manual_price,
            })),
            customer: data.customer,
            note: data.note,
            payments,
            document_type: effectiveCheckoutType === 'credit' ? 'receipt' : effectiveDocumentType,
            selected_category_id: selectedCategoryId,
            main_payment_method: mainPaymentMethod,
            split_payment: splitPayment,
            discount_type: discount?.type ?? null,
            discount_value: discount?.value ?? '',
            discount_reason: discount?.reason ?? '',
        };
    }

    function restorePosDraft(draft: PosDraft) {
        let discardedInvalidQuantity = false;
        const restoredCart = draft.cart
            .map((item) => {
                const product = productsById.get(item.product_id);

                if (!product) {
                    return null;
                }

                if (quantityError(item.quantity)) {
                    discardedInvalidQuantity = true;
                    return null;
                }

                const price = priceFromList(product, item.price_type_id ?? default_price_type_id, default_price_type_id);

                return {
                    product,
                    quantity: normalizeQuantity(item.quantity),
                    unit_price: item.unit_price ?? price.price,
                    original_price: item.original_price ?? price.price,
                    price_type_id: item.price_type_id ?? price.priceTypeId ?? null,
                    price_source: item.price_source ?? 'price_list',
                    manual_price: Boolean(item.manual_price),
                    price_warning: price.warning,
                };
            })
            .filter((item): item is CartItem => Boolean(item));
        const restoredDiscount = draft.discount_type && draft.discount_value
            ? {
                type: draft.discount_type,
                value: draft.discount_value,
                reason: draft.discount_reason ?? '',
            }
            : null;

        setCart(restoredCart);
        setDiscount(restoredDiscount && !discountError(restoredDiscount, cartSubtotal(restoredCart)) ? restoredDiscount : null);
        setData('customer', draft.customer);
        setData('note', draft.note ?? '');
        setPayments(draft.payments?.length ? draft.payments.map((payment) => ({
            method: payment.method ?? 'cash',
            amount: payment.amount ?? '0.00',
            reference: payment.reference ?? '',
            details: { ...emptyPaymentDetails(), ...(payment.details ?? {}) },
        })) : [paymentLine('cash', total.toFixed(2))]);
        setDocumentType(singleDocumentType ?? (
            availableCheckoutTypes.includes(draft.document_type) ? draft.document_type : (availableCheckoutTypes[0] ?? 'receipt')
        ));
        setSelectedCategoryId(draft.selected_category_id ?? null);
        setMainPaymentMethod(draft.main_payment_method ?? 'cash');
        setSplitPayment(Boolean(draft.split_payment));

        if (discardedInvalidQuantity) {
            toast.warning('Se descartaron cantidades inválidas del borrador.');
        }
    }

    function clearSaleState() {
        setCart([]);
        setSearch('');
        setErrorMessage('');
        setMessage('');
        setData('note', '');
        setData('customer', emptyCustomer(country));
        setShowCheckoutModal(false);
        setSplitPayment(false);
        setDocumentType(singleDocumentType ?? (availableCheckoutTypes[0] ?? 'receipt'));
        setPayments([paymentLine(mainPaymentMethod, '0.00')]);
        setDiscount(null);
    }

    function clearPosDraftAndState() {
        clearSaleState();
        clearDraft(draftKey);
        focusSearch();
    }

    function requestClearSale() {
        if (cart.length > 0 || isMeaningfulCustomer(data.customer) || data.note.trim() !== '' || discount) {
            setShowClearSaleModal(true);
            return;
        }

        clearPosDraftAndState();
    }

    function openDiscountModal() {
        setDiscountForm(discount ?? {
            type: 'fixed',
            value: '',
            reason: '',
        });
        setDiscountFormError('');
        setShowDiscountModal(true);
    }

    function applyDiscount() {
        const nextDiscount = {
            ...discountForm,
            value: discountForm.value.trim(),
            reason: discountForm.reason.trim(),
        };
        const error = discountError(nextDiscount, subtotalBeforeDiscount);

        if (error) {
            setDiscountFormError(error);
            toast.error(error);
            return;
        }

        setDiscount(nextDiscount);
        setShowDiscountModal(false);
        setDiscountFormError('');
        showMessage('Descuento aplicado.');
    }

    function clearAndFocusSearch() {
        setSearch('');
        setTimeout(() => {
            searchInputRef.current?.focus();
        }, 0);
    }

    function showMessage(value: string) {
        setMessage(value);
        setErrorMessage('');
    }

    function showError(value: string) {
        setErrorMessage(value);
        setMessage('');
    }

    function clearSearch() {
        setSearch('');
        focusSearch();
    }

    function clearSearchAndFeedback() {
        setSearch('');
        setErrorMessage('');
        focusSearch();
    }

    function saveRecentProduct(product: Product) {
        setRecentProducts((current) => {
            const next = [
                product,
                ...current.filter((item) => item.id !== product.id),
            ].slice(0, 8);

            localStorage.setItem(recentProductsKey, JSON.stringify(next));

            return next;
        });
    }

    function setCustomerField<K extends keyof CustomerForm>(field: K, value: CustomerForm[K]) {
        setData('customer', {
            ...data.customer,
            [field]: value,
        });
    }

    function setCustomerDocument(value: string) {
        const cleanValue = country === 'GT' ? sanitizeNit(value) : value.replace(/[\s-]/g, '');
        setCustomerField('doc_number', cleanValue);
    }

    function selectCustomer(customer: Customer) {
        setData('customer', {
            id: customer.id,
            consumidor_final: customer.doc_type === 'CF' || customer.doc_type === 'Consumidor Final',
            doc_type: customer.doc_type ?? (country === 'AR' ? 'DNI' : 'NIT'),
            doc_number: customer.doc_number ?? '',
            tax_condition: customer.tax_condition ?? (country === 'AR' ? 'Consumidor Final' : ''),
            name: customer.name,
            address: customer.address ?? '',
            phone: customer.phone ?? '',
            country: customer.country ?? country,
            name_locked: Boolean(customer.name_locked),
            tax_lookup_verified_at: customer.tax_lookup_verified_at ?? null,
        });
    }

    function handleCustomerKeyDown(event: KeyboardEvent<HTMLInputElement | HTMLSelectElement>) {
        if (event.key !== 'Enter') {
            return;
        }

        if (customerMatches.length === 0) {
            return;
        }

        event.preventDefault();
        selectCustomer(customerMatches[0]);
    }

    async function lookupNit() {
        const nit = sanitizeNit(data.customer.doc_number);

        if (!nit || country !== 'GT') {
            return;
        }

        setCustomerField('doc_number', nit);
        setNitLookupLoading(true);
        setNitLookupMessage('');

        try {
            const response = await fetch(`${route('customers.gt.nit-lookup')}?nit=${encodeURIComponent(nit)}`);
            const result = await readJsonResponse(response);

            if (!response.ok) {
                const message = result?.errors?.nit?.[0] || result?.message || 'No se pudo consultar el NIT.';
                throw new Error(message);
            }

            setData('customer', {
                ...data.customer,
                consumidor_final: false,
                doc_type: 'NIT',
                doc_number: nit,
                id: result.customer?.id ?? data.customer.id,
                name: result.customer?.name || result.name || '',
                address: result.customer?.address || result.address || data.customer.address,
                phone: result.customer?.phone || result.phone || data.customer.phone,
                country: 'GT',
                name_locked: Boolean(result.customer?.name_locked ?? result.name),
                tax_lookup_verified_at: result.customer?.tax_lookup_verified_at ?? result.tax_lookup_verified_at ?? null,
            });
            setNitLookupMessage(result.name ? 'Nombre obtenido automáticamente' : 'No se encontró nombre para el NIT.');
        } catch (error) {
            const message = error instanceof Error ? error.message : 'No se pudo consultar el NIT.';
            setNitLookupMessage(message);
            showError(message);
        } finally {
            setNitLookupLoading(false);
        }
    }

    function buildCartItem(product: Product): CartItem {
        const price = priceFromList(product, default_price_type_id, default_price_type_id);

        return {
            product,
            quantity: '1',
            unit_price: price.price,
            original_price: price.price,
            price_type_id: price.priceTypeId ?? null,
            price_source: 'price_list',
            manual_price: false,
            price_warning: price.warning,
            credit_line_id: null,
            max_quantity: null,
            credit_receipt_number: null,
            locked_credit_line: false,
        };
    }

    function requestLastCustomerPrice(product: Product) {
        if (!price_settings.remember_last_customer_product_price || !data.customer.id || data.customer.doc_number === 'CF' || data.customer.consumidor_final) {
            return;
        }

        fetch(route('customers.products.last-price', { customer: data.customer.id, product: product.id }), {
            headers: { Accept: 'application/json' },
        })
            .then(async (response) => {
                const text = await response.text();
                const payload = text ? JSON.parse(text) : {};

                if (!response.ok || !payload.price) {
                    return;
                }

                setCart((items) => items.map((item) => (
                    item.product.id === product.id && item.price_source === 'price_list'
                        ? {
                            ...item,
                            unit_price: String(payload.price),
                            price_type_id: payload.price_type_id ?? item.price_type_id,
                            price_source: 'last_customer_price',
                            manual_price: false,
                            price_warning: null,
                        }
                        : item
                )));
            })
            .catch(() => undefined);
    }

    function addProduct(product: Product): boolean {
        const availableStock = Math.floor(product.available_stock ?? product.stock);

        if (availableStock < 1) {
            showError(`${product.name}: ${t('pos.out_of_stock_for')}`);
            return false;
        }

        const items = cartRef.current;
        const existing = items.find((item) => item.product.id === product.id);
        const existingQuantity = existing ? quantityNumber(existing.quantity) : 0;
        if (existing && existingQuantity >= availableStock) {
            showError(
                `${product.name}: ${t('pos.stock_insufficient')} ${availableStock}.`,
            );
            return false;
        }

        const nextItems = existing
            ? items.map((item) =>
                  item.product.id === product.id
                      ? { ...item, quantity: String(quantityNumber(item.quantity) + 1) }
                      : item,
              )
            : [...items, buildCartItem(product)];

        cartRef.current = nextItems;
        setCart(nextItems);
        setErrorMessage('');
        saveRecentProduct(product);
        if (!existing) {
            requestLastCustomerPrice(product);
        }

        return true;
    }

    function removeProduct(productId: number) {
        setCart((items) => items.filter((item) => item.product.id !== productId));
        focusSearch();
    }

    function updateQuantity(productId: number, quantity: number | string) {
        const sanitizedQuantity = sanitizeQuantityInput(quantity);

        if (sanitizedQuantity === null) {
            showError(integerQuantityMessage);
            toast.error(integerQuantityMessage);
            return;
        }

        setCart((items) =>
            items.map((item) => {
                if (item.product.id !== productId) {
                    return item;
                }

                if (quantityError(sanitizedQuantity)) {
                    showError(integerQuantityMessage);
                    return { ...item, quantity: sanitizedQuantity };
                }

                const nextQuantity = Number(sanitizedQuantity);

                const availableStock = Math.floor(item.max_quantity ?? item.product.available_stock ?? item.product.stock);

                if (nextQuantity > availableStock) {
                    showError(
                        `${item.product.name}: ${t('pos.stock_insufficient')} ${availableStock}.`,
                    );

                    return { ...item, quantity: String(Math.max(1, availableStock)) };
                }

                setErrorMessage('');

                return { ...item, quantity: String(nextQuantity) };
            }),
        );
    }

    function changeLinePriceType(productId: number, priceTypeId: number) {
        setCart((items) => items.map((item) => {
            if (item.product.id !== productId) {
                return item;
            }

            const price = priceFromList(item.product, priceTypeId, default_price_type_id);

            return {
                ...item,
                unit_price: price.price,
                original_price: price.price,
                price_type_id: price.priceTypeId ?? priceTypeId,
                price_source: 'price_list',
                manual_price: false,
                price_warning: price.warning,
            };
        }));
    }

    function enableManualPrice(productId: number) {
        setCart((items) => items.map((item) => (
            item.product.id === productId
                ? { ...item, price_source: 'manual', manual_price: true, price_warning: null }
                : item
        )));
    }

    function updateManualPrice(productId: number, value: string) {
        setCart((items) => items.map((item) => (
            item.product.id === productId
                ? { ...item, unit_price: value, price_source: 'manual', manual_price: true, price_warning: null }
                : item
        )));
    }

    function applyCostMarkup(productId: number, percent: number) {
        setCart((items) => items.map((item) => {
            if (item.product.id !== productId) {
                return item;
            }

            const unitPrice = minimumManualPrice(item.product, percent).toFixed(2);

            return {
                ...item,
                unit_price: unitPrice,
                price_source: 'manual',
                manual_price: true,
                price_warning: null,
            };
        }));
    }

    function changeQuantity(productId: number, difference: number) {
        const item = cart.find((cartItem) => cartItem.product.id === productId);

        if (!item) {
            return;
        }

        const currentQuantity = quantityNumber(item.quantity) || 1;
        updateQuantity(productId, String(Math.max(1, currentQuantity + difference)));
    }

    function cancelSale(force = false) {
        if (cart.length > 0 && !force && !window.confirm(t('pos.cancel_current_sale'))) {
            focusSearch();
            return;
        }

        clearPosDraftAndState();
    }

    function holdSale() {
        if (cart.length === 0) {
            recoverSale();
            return;
        }

        const payload: HeldSale = {
            cart,
            customer: data.customer,
        };

        localStorage.setItem(heldSaleKey, JSON.stringify(payload));
        setHasHeldSale(true);
        cancelSale(true);
        showMessage(t('pos.sale_held'));
    }

    function recoverSale() {
        const heldSale = loadJson<HeldSale | null>(heldSaleKey, null);

        if (!heldSale) {
            showError(t('pos.held_sale_empty'));
            focusSearch();
            return;
        }

        setCart(
            (heldSale.cart ?? [])
                .map((item) => {
                    const currentProduct = productsById.get(item.product.id);

                    if (!currentProduct) {
                        return null;
                    }

                    if (quantityError(item.quantity) || Math.floor(currentProduct.stock) < 1) {
                        return null;
                    }

                    const price = priceFromList(currentProduct, item.price_type_id ?? default_price_type_id, default_price_type_id);

                    return {
                        product: currentProduct,
                        quantity: String(Math.max(
                            1,
                            Math.min(quantityNumber(item.quantity), Math.floor(currentProduct.stock)),
                        )),
                        unit_price: item.unit_price ?? price.price,
                        original_price: item.original_price ?? price.price,
                        price_type_id: item.price_type_id ?? price.priceTypeId ?? null,
                        price_source: item.price_source ?? 'price_list',
                        manual_price: Boolean(item.manual_price),
                        price_warning: price.warning,
                    };
                })
                .filter((item): item is CartItem => Boolean(item)),
        );
        setData('customer', heldSale.customer || emptyCustomer(country));
        localStorage.removeItem(heldSaleKey);
        setHasHeldSale(false);
        setSearch('');
        showMessage(t('pos.sale_recovered'));
        focusSearch();
    }

    function handleHoldShortcut() {
        if (cart.length > 0) {
            holdSale();
            return;
        }

        recoverSale();
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

        const added = addProduct(product);

        if (added) {
            clearAndFocusSearch();
        }
    }

    function openCheckout(event?: FormEvent) {
        event?.preventDefault();

        if (cart.length === 0 || processing || creditProcessing) {
            focusSearch();
            return;
        }

        if (!validateCartQuantities()) {
            return;
        }

        if (!validateCartPrices()) {
            return;
        }

        if (!validateDiscount()) {
            return;
        }

        if (!hasOpenCashRegister && !credit_available) {
            showError('No hay caja abierta. Abre caja para registrar ventas.');
            toast.error('No hay caja abierta. Abre caja para registrar ventas.');
            return;
        }

        if (noAvailableDocumentTypes) {
            const error = 'No hay ningún tipo de documento habilitado para esta empresa.';
            showError(error);
            toast.error(error);
            return;
        }

        setSplitPayment(false);
        setPayments([paymentLine(mainPaymentMethod, total.toFixed(2))]);
        setShowCheckoutModal(true);
    }

    function submitSale() {
        if (cart.length === 0 || processing || creditProcessing) {
            return;
        }

        if (!validateCartQuantities()) {
            return;
        }

        if (!validateDiscount()) {
            return;
        }

        if (effectiveCheckoutType === 'credit') {
            if (discount) {
                const message = 'El crédito no admite descuento general. Ajusta los precios antes de registrar el crédito.';
                showError(message);
                toast.error(message);
                return;
            }

            const docType = data.customer.doc_type.trim().toUpperCase();
            const docNumber = data.customer.doc_number.trim().toUpperCase();

            if (data.customer.consumidor_final || docType === 'CF' || docNumber === 'CF' || docType !== 'NIT' || !docNumber) {
                const message = 'Para crédito debes ingresar un NIT válido. CF no está permitido.';
                showError(message);
                toast.error(message);
                return;
            }

            setCreditProcessing(true);
            router.post(route('credits.receipts.store'), {
                note: data.note,
                customer: data.customer,
                items: cart.map((item) => ({
                    product_id: item.product.id,
                    quantity: quantityNumber(item.quantity),
                    unit_price: Number(item.unit_price),
                })),
            }, {
                preserveScroll: true,
                onSuccess: (page) => {
                    const flash = page.props.flash as {
                        credit_print_url?: string | null;
                    } | undefined;
                    const successMessage = 'Crédito registrado correctamente.';

                    setCart([]);
                    setSearch('');
                    setErrorMessage('');
                    setShowCheckoutModal(false);
                    setSplitPayment(false);
                    setDiscount(null);
                    setPayments([paymentLine(mainPaymentMethod, '0.00')]);
                    clearDraft(draftKey);
                    reset();
                    setData('customer', emptyCustomer(country));
                    focusSearch();

                    if (flash?.credit_print_url) {
                        window.open(flash.credit_print_url, '_blank');
                    }

                    showMessage(successMessage);
                    toast.success(successMessage, { persistent: true });
                },
                onError: (errors) => {
                    const firstError = Object.values(errors)[0] ?? 'No se pudo registrar el crédito.';

                    showError(firstError);
                    toast.error(firstError);
                },
                onFinish: () => setCreditProcessing(false),
            });
            return;
        }

        if (!hasOpenCashRegister) {
            showError('No hay caja abierta. Abre caja para registrar ventas.');
            toast.error('No hay caja abierta. Abre caja para registrar ventas.');
            return;
        }

        if (!paymentIsBalanced) {
            toast.warning('El total pagado debe coincidir con el total de la venta.');
            return;
        }

        if (invoiceNitNeedsVerification) {
            showError('El NIT del cliente no ha sido validado.');
            toast.error('El NIT del cliente no ha sido validado.');
            return;
        }

        if (invoiceCuiDisabled) {
            showError('CUI/DPI aún no está habilitado.');
            toast.error('CUI/DPI aún no está habilitado.');
            return;
        }

        const finalPayments = splitPayment
            ? payments
            : [payments[0] ?? paymentLine(mainPaymentMethod, total.toFixed(2))];

        if (effectiveDocumentType === 'invoice') {
            showMessage('Certificando factura FEL...');
        }

        transform(() => ({
            note: data.note,
            customer: data.customer,
            document_type: effectiveDocumentType,
            payments: finalPayments.map((payment) => ({
                method: payment.method,
                amount: Number(payment.amount || 0),
                reference: payment.reference.trim() || null,
                details: cleanPaymentDetails(payment.method, payment.details),
            })),
            items: cart.map((item) => ({
                product_id: item.product.id,
                quantity: quantityNumber(item.quantity),
                price_type_id: item.price_type_id,
                unit_price: Number(item.unit_price),
                price_source: item.price_source,
                manual_price: item.manual_price,
                credit_line_id: item.credit_line_id ?? null,
            })),
            discount: discount ? {
                type: discount.type,
                value: Number(discount.value),
                reason: discount.reason.trim(),
            } : null,
        }));

        post(route('sales.store'), {
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = page.props.flash as {
                    receipt_sale_id?: number | null;
                    fel_print_sale_id?: number | null;
                    fel_print_url?: string | null;
                    fel_success_message?: string | null;
                } | undefined;
                const receiptSaleId = flash?.receipt_sale_id;
                const felPrintSaleId = flash?.fel_print_sale_id;

                setCart([]);
                setSearch('');
                setErrorMessage('');
                setShowCheckoutModal(false);
                setSplitPayment(false);
                setDocumentType(singleDocumentType ?? (availableCheckoutTypes[0] ?? 'receipt'));
                setDiscount(null);
                setPayments([paymentLine(mainPaymentMethod, '0.00')]);
                clearDraft(draftKey);
                const successMessage = effectiveDocumentType === 'invoice'
                    ? (flash?.fel_success_message ?? 'Factura FEL certificada correctamente.')
                    : 'Venta guardada correctamente.';
                reset();
                setData('customer', emptyCustomer(country));
                focusSearch();

                if (effectiveDocumentType === 'receipt' && receiptSaleId) {
                    showMessage(successMessage);
                    toast.success(successMessage, { persistent: true });
                    window.open(route('sales.receipt', receiptSaleId), '_blank');
                    return;
                }

                if (effectiveDocumentType === 'invoice' && felPrintSaleId) {
                    setOpeningFelPrint(true);
                    showMessage('Factura certificada. Abriendo impresión...');

                    window.setTimeout(() => {
                        window.open(flash?.fel_print_url ?? route('sales.fel-document', felPrintSaleId), '_blank');
                        setOpeningFelPrint(false);
                        showMessage(successMessage);
                        toast.success(successMessage, { persistent: true });
                    }, 50);
                    return;
                }

                showMessage(successMessage);
                toast.success(successMessage, { persistent: true });
            },
            onError: (errors) => {
                const firstError = Object.values(errors)[0] ?? 'No se pudo completar la venta. Revisa los datos.';

                showError(firstError);
                toast.error(firstError);
            },
        });
    }

    useEffect(() => {
        setRecentProducts(loadJson<Product[]>(recentProductsKey, []));
        setHasHeldSale(Boolean(localStorage.getItem(heldSaleKey)));
        const draft = loadDraft<PosDraft>(draftKey);

        if (draft && isMeaningfulPosDraft(draft)) {
            setRestoreDraft(draft);
        } else {
            setDraftReady(true);
        }

        focusSearch();
    }, [draftKey]);

    useEffect(() => {
        if (!draftReady || restoreDraft) {
            return;
        }

        const draft = buildDraft();
        const timer = window.setTimeout(() => {
            if (isMeaningfulPosDraft(draft)) {
                saveDraft(draftKey, draft);
            } else {
                clearDraft(draftKey);
            }
        }, 500);

        return () => window.clearTimeout(timer);
    }, [
        cart,
        data.customer,
        data.note,
        discount,
        documentType,
        draftKey,
        draftReady,
        mainPaymentMethod,
        payments,
        restoreDraft,
        selectedCategoryId,
        splitPayment,
    ]);

    useEffect(() => {
        cartRef.current = cart;
    }, [cart]);

    useEffect(() => {
        const typedErrors = errors as Record<string, string>;
        const activeMessage = message || errorMessage || errors.items || errors.note || typedErrors['customer.doc_number'] || typedErrors.payments;

        if (!activeMessage) {
            return;
        }

        if (messageTimerRef.current) {
            window.clearTimeout(messageTimerRef.current);
        }

        messageTimerRef.current = window.setTimeout(() => {
            setMessage('');
            setErrorMessage('');
        }, 3500);

        return () => {
            if (messageTimerRef.current) {
                window.clearTimeout(messageTimerRef.current);
            }
        };
    }, [message, errorMessage, errors.items, errors.note, errors]);

    useEffect(() => {
        function handleShortcut(event: globalThis.KeyboardEvent) {
            if (event.key === 'F2') {
                event.preventDefault();
                focusSearch();
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                clearSearchAndFeedback();
            }

            if (event.key === 'F9' && cart.length > 0 && !processing && hasOpenCashRegister) {
                event.preventDefault();
                openCheckout();
            }

            if (event.ctrlKey && event.key === 'Backspace') {
                event.preventDefault();
                cancelSale();
            }

            if (event.ctrlKey && event.key.toLowerCase() === 'h') {
                event.preventDefault();
                handleHoldShortcut();
            }
        }

        window.addEventListener('keydown', handleShortcut);

        return () => window.removeEventListener('keydown', handleShortcut);
    }, [cart, processing, data.note, productsById, total, mainPaymentMethod, hasOpenCashRegister]);

    useEffect(() => {
        if (!showCheckoutModal || splitPayment) {
            return;
        }

        setPayments([paymentLine(mainPaymentMethod, total.toFixed(2))]);
    }, [mainPaymentMethod, showCheckoutModal, splitPayment, total]);

    function updatePayment(index: number, field: keyof PaymentLine, value: string) {
        setPayments((current) =>
            current.map((payment, paymentIndex) =>
                paymentIndex === index
                    ? {
                        ...payment,
                        [field]: value,
                        ...(field === 'method' ? { details: emptyPaymentDetails(), reference: '' } : {}),
                    }
                    : payment,
            ),
        );
    }

    function updatePaymentDetail(index: number, field: keyof PaymentDetails, value: string) {
        setPayments((current) =>
            current.map((payment, paymentIndex) =>
                paymentIndex === index
                    ? { ...payment, details: { ...payment.details, [field]: value } }
                    : payment,
            ),
        );
    }

    function addPaymentLine() {
        setPayments((current) => [
            ...current,
            paymentLine(paymentMethods[0].value, Math.max(pendingAmount, 0).toFixed(2)),
        ]);
    }

    function removePaymentLine(index: number) {
        setPayments((current) => current.filter((_, paymentIndex) => paymentIndex !== index));
    }

    function assignPending() {
        setPayments((current) => {
            if (current.length === 0) {
                return [paymentLine(paymentMethods[0].value, total.toFixed(2))];
            }

            const lastIndex = current.length - 1;
            const paidWithoutLast = current.reduce(
                (sum, payment, index) => (index === lastIndex ? sum : sum + Number(payment.amount || 0)),
                0,
            );
            const remaining = Math.max(total - paidWithoutLast, 0);

            return current.map((payment, index) =>
                index === lastIndex ? { ...payment, amount: remaining.toFixed(2) } : payment,
            );
        });
    }

    const productsToShow = search || selectedCategoryId ? filteredProducts : currentRecentProducts;
    const showingRecentProducts =
        !search && !selectedCategoryId && currentRecentProducts.length > 0;
    const typedErrors = errors as Record<string, string>;
    const customerError = typedErrors['customer.doc_number'];
    const paymentsError = typedErrors.payments;
    const cashRegisterError = typedErrors.cash_register;
    const customerModalErrors = [
        typedErrors['customer.name'],
        typedErrors['customer.doc_number'],
        typedErrors['customer.address'],
        typedErrors['customer.phone'],
    ].filter(Boolean);
    const paymentModalErrors = Object.entries(typedErrors)
        .filter(([key]) => key === 'payments' || key.startsWith('payments.'))
        .map(([, value]) => value);
    const documentError = typedErrors.document_type;
    const invoiceNitNeedsVerification =
        effectiveDocumentType === 'invoice' &&
        country === 'GT' &&
        !data.customer.consumidor_final &&
        data.customer.doc_type === 'NIT' &&
        !data.customer.tax_lookup_verified_at;
    const invoiceCuiDisabled =
        effectiveDocumentType === 'invoice' &&
        country === 'GT' &&
        data.customer.doc_type === 'CUI';
    const isFelProcessing = processing && effectiveDocumentType === 'invoice';
    const checkoutHasErrors =
        customerModalErrors.length > 0 ||
        paymentModalErrors.length > 0 ||
        Boolean(documentError || cashRegisterError || errors.items || invoiceNitNeedsVerification || invoiceCuiDisabled);

    function paymentFieldError(index: number, field: keyof PaymentLine) {
        return typedErrors[`payments.${index}.${field}`];
    }

    return (
        <AuthenticatedLayout>
            <Head title={t('nav.sales')} />
            <Toast toasts={toast.toasts} onClose={toast.removeToast} />

            <div className="h-[calc(100vh-4rem)] bg-[#f4f6fb]">
                <div className="mx-auto grid h-full max-w-[1800px] gap-5 p-5 lg:grid-cols-[minmax(0,1fr)_620px] xl:grid-cols-[minmax(0,1fr)_680px]">
                    <section className="flex min-h-0 flex-col rounded-2xl border border-slate-200/80 bg-white/95 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="border-b border-slate-200 p-4">
                            {credit_invoice && (
                                <div className="mb-4 rounded-xl border border-indigo-100 bg-indigo-50 px-4 py-3 text-sm font-semibold text-indigo-700">
                                    Facturando productos a crédito.
                                </div>
                            )}
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <h1 className="text-2xl font-semibold text-slate-900">
                                        {t('pos.title')}
                                    </h1>
                                    <p className="text-sm text-slate-500">
                                        {t('pos.focus_shortcuts')}
                                    </p>
                                </div>

                                <div className="flex gap-2">
                                    <button
                                        type="button"
                                        onClick={holdSale}
                                        disabled={cart.length === 0}
                                        className="h-11 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {t('pos.hold_sale')}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={recoverSale}
                                        disabled={!hasHeldSale}
                                        className="h-11 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {t('pos.recover_sale')}
                                    </button>
                                </div>
                            </div>

                            <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50/70 p-3">
                                <div className="mb-3 flex items-center justify-between gap-3">
                                    <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-600">
                                        Cliente
                                    </h2>
                                    {data.customer.id && (
                                        <span className="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
                                            Cliente guardado
                                        </span>
                                    )}
                                </div>

                                {country === 'GT' ? (
                                    <div className="space-y-3">
                                        <div className="flex flex-wrap gap-2">
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setData('customer', emptyCustomer('GT'));
                                                    setNitLookupMessage('');
                                                }}
                                                className={[
                                                    'rounded-full border px-4 py-2 text-sm font-semibold transition',
                                                    data.customer.consumidor_final
                                                        ? 'border-indigo-600 bg-indigo-600 text-white shadow-sm'
                                                        : 'border-slate-200 bg-white text-slate-700 hover:bg-indigo-50',
                                                ].join(' ')}
                                            >
                                                Consumidor Final
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setData('customer', {
                                                        ...emptyCustomer('GT'),
                                                        consumidor_final: false,
                                                        doc_type: 'NIT',
                                                        doc_number: '',
                                                        name: '',
                                                        name_locked: false,
                                                        tax_lookup_verified_at: null,
                                                    });
                                                    setNitLookupMessage('');
                                                }}
                                                className={[
                                                    'rounded-full border px-4 py-2 text-sm font-semibold transition',
                                                    !data.customer.consumidor_final && data.customer.doc_type === 'NIT'
                                                        ? 'border-indigo-600 bg-indigo-600 text-white shadow-sm'
                                                        : 'border-slate-200 bg-white text-slate-700 hover:bg-indigo-50',
                                                ].join(' ')}
                                            >
                                                NIT
                                            </button>
                                        </div>

                                        {data.customer.consumidor_final ? (
                                            <div className="grid gap-3 md:grid-cols-3">
                                                <input
                                                    value={data.customer.name}
                                                    onChange={(event) => setCustomerField('name', event.target.value)}
                                                    placeholder="Nombre"
                                                    className="h-10 w-full rounded-xl border-slate-200 bg-white text-sm text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                                />
                                                <input
                                                    value={data.customer.address}
                                                    onChange={(event) => setCustomerField('address', event.target.value)}
                                                    placeholder="Dirección"
                                                    className="h-10 w-full rounded-xl border-slate-200 bg-white text-sm text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                                />
                                                <input
                                                    value={data.customer.phone}
                                                    onChange={(event) => setCustomerField('phone', event.target.value)}
                                                    placeholder="Teléfono"
                                                    className="h-10 w-full rounded-xl border-slate-200 bg-white text-sm text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                                />
                                            </div>
                                        ) : (
                                            <>
                                                <div className="grid gap-3 md:grid-cols-[180px_auto_1fr]">
                                                    <div>
                                                        <label className="block text-xs font-medium text-slate-600">
                                                            NIT
                                                        </label>
                                                        <input
                                                            value={data.customer.doc_number}
                                                            onChange={(event) => {
                                                                const nit = sanitizeNit(event.target.value);
                                                                setData('customer', {
                                                                    ...data.customer,
                                                                    doc_number: nit,
                                                                    name: '',
                                                                    name_locked: false,
                                                                    tax_lookup_verified_at: null,
                                                                });
                                                                setNitLookupMessage('');
                                                            }}
                                                            onKeyDown={handleCustomerKeyDown}
                                                            placeholder="NIT"
                                                            className="mt-1 h-10 w-full rounded-xl border-slate-200 bg-white text-sm text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                                        />
                                                    </div>
                                                    <div className="flex items-end">
                                                        <button
                                                            type="button"
                                                            onClick={lookupNit}
                                                            disabled={nitLookupLoading || !data.customer.doc_number}
                                                            className="h-10 rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                        >
                                                            {nitLookupLoading ? 'Consultando...' : 'Consultar NIT'}
                                                        </button>
                                                    </div>
                                                    <div>
                                                        <label className="block text-xs font-medium text-slate-600">
                                                            Nombre
                                                        </label>
                                                        <input
                                                            value={data.customer.name}
                                                            readOnly
                                                            placeholder="Nombre obtenido automáticamente"
                                                            className="mt-1 h-10 w-full rounded-xl border-slate-200 bg-slate-100 text-sm text-slate-900 shadow-sm"
                                                        />
                                                    </div>
                                                </div>
                                                {nitLookupMessage && (
                                                    <div className="text-xs font-semibold text-slate-500">
                                                        {nitLookupMessage}
                                                    </div>
                                                )}
                                                <div className="grid gap-3 md:grid-cols-2">
                                                    <input
                                                        value={data.customer.address}
                                                        onChange={(event) => setCustomerField('address', event.target.value)}
                                                        placeholder="Dirección"
                                                        className="h-10 w-full rounded-xl border-slate-200 bg-white text-sm text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                                    />
                                                    <input
                                                        value={data.customer.phone}
                                                        onChange={(event) => setCustomerField('phone', event.target.value)}
                                                        placeholder="Teléfono"
                                                        className="h-10 w-full rounded-xl border-slate-200 bg-white text-sm text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                                    />
                                                </div>
                                            </>
                                        )}
                                    </div>
                                ) : (
                                    <div className="space-y-3">
                                        <div className="grid gap-3 md:grid-cols-4">
                                            <div>
                                                <label className="block text-xs font-medium text-slate-600">
                                                    Tipo de documento
                                                </label>
                                                <select
                                                    value={data.customer.doc_type}
                                                    onChange={(event) => {
                                                        const docType = event.target.value;
                                                        setData('customer', {
                                                            ...data.customer,
                                                            doc_type: docType,
                                                            doc_number: docType === 'Consumidor Final' ? '' : data.customer.doc_number,
                                                            name: docType === 'Consumidor Final' ? 'Consumidor Final' : data.customer.name,
                                                            tax_condition: docType === 'Consumidor Final' ? 'Consumidor Final' : data.customer.tax_condition,
                                                        });
                                                    }}
                                                    onKeyDown={handleCustomerKeyDown}
                                                    className="mt-1 h-10 w-full rounded-xl border-slate-200 bg-white text-sm text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                                >
                                                    {arDocTypes.map((type) => (
                                                        <option key={type} value={type}>
                                                            {type}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                            <div>
                                                <label className="block text-xs font-medium text-slate-600">
                                                    Número
                                                </label>
                                                <input
                                                    value={data.customer.doc_number}
                                                    disabled={data.customer.doc_type === 'Consumidor Final'}
                                                    onChange={(event) => setCustomerDocument(event.target.value)}
                                                    onKeyDown={handleCustomerKeyDown}
                                                    className="mt-1 h-10 w-full rounded-xl border-slate-200 bg-white text-sm text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100 disabled:bg-slate-100"
                                                />
                                            </div>
                                            <div className="md:col-span-2">
                                                <label className="block text-xs font-medium text-slate-600">
                                                    Razón social
                                                </label>
                                                <input
                                                    value={data.customer.name}
                                                    onChange={(event) => setCustomerField('name', event.target.value)}
                                                    onKeyDown={handleCustomerKeyDown}
                                                    className="mt-1 h-10 w-full rounded-xl border-slate-200 bg-white text-sm text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                                />
                                            </div>
                                        </div>

                                        <div className="grid gap-3 md:grid-cols-3">
                                            <select
                                                value={data.customer.tax_condition}
                                                onChange={(event) => setCustomerField('tax_condition', event.target.value)}
                                                className="h-10 w-full rounded-xl border-slate-200 bg-white text-sm text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                            >
                                                {arTaxConditions.map((condition) => (
                                                    <option key={condition} value={condition}>
                                                        {condition}
                                                    </option>
                                                ))}
                                            </select>
                                            <input
                                                value={data.customer.address}
                                                onChange={(event) => setCustomerField('address', event.target.value)}
                                                placeholder="Dirección"
                                                className="h-10 w-full rounded-xl border-slate-200 bg-white text-sm text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                            />
                                            <input
                                                value={data.customer.phone}
                                                onChange={(event) => setCustomerField('phone', event.target.value)}
                                                placeholder="Teléfono"
                                                className="h-10 w-full rounded-xl border-slate-200 bg-white text-sm text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                            />
                                        </div>
                                    </div>
                                )}

                                {customerMatches.length > 0 && (
                                    <div className="mt-2 flex gap-2 overflow-x-auto whitespace-nowrap">
                                        {customerMatches.map((customer) => (
                                            <button
                                                key={customer.id}
                                                type="button"
                                                onClick={() => selectCustomer(customer)}
                                                className="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-700 hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700"
                                            >
                                                {customer.name}
                                                {customer.doc_number ? ` · ${customer.doc_number}` : ''}
                                            </button>
                                        ))}
                                    </div>
                                )}

                                {customerError && (
                                    <div className="mt-2 text-sm font-medium text-red-600">
                                        {customerError}
                                    </div>
                                )}
                            </div>

                            <input
                                ref={searchInputRef}
                                autoFocus
                                value={search}
                                onChange={(event) => {
                                    setSearch(event.target.value);
                                    setErrorMessage('');
                                }}
                                onKeyDown={handleSearchKeyDown}
                                placeholder={t('pos.search_placeholder')}
                                className="mt-3 h-14 w-full rounded-2xl border border-slate-200 bg-white px-5 text-lg font-medium text-slate-900 shadow-sm outline-none transition focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                            />

                            <div className="mt-3 flex gap-2 overflow-x-auto whitespace-nowrap pb-2">
                                <button
                                    type="button"
                                    onClick={() => setSelectedCategoryId(null)}
                                    className={`rounded-full border px-3 py-1.5 text-sm font-semibold transition ${
                                        selectedCategoryId === null
                                            ? 'border-indigo-600 bg-indigo-600 text-white shadow-md shadow-indigo-200'
                                            : 'border-slate-200 bg-white text-slate-600 shadow-sm hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700'
                                    }`}
                                >
                                    {t('categories.all')}
                                </button>
                                {categories.map((category) => (
                                    <button
                                        key={category.id}
                                        type="button"
                                        onClick={() => setSelectedCategoryId(category.id)}
                                        className={`rounded-full border px-3 py-1.5 text-sm font-semibold transition ${
                                            selectedCategoryId === category.id
                                                ? 'border-indigo-600 bg-indigo-600 text-white shadow-md shadow-indigo-200'
                                                : 'border-slate-200 bg-white text-slate-600 shadow-sm hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700'
                                        }`}
                                    >
                                        {category.name}
                                    </button>
                                ))}
                            </div>
                        </div>

                        <div className="min-h-0 flex-1 overflow-y-auto p-4">
                            {(errorMessage || errors.items || errors.note || customerError || paymentsError || cashRegisterError) && (
                                <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
                                    {errorMessage || errors.items || errors.note || customerError || paymentsError || cashRegisterError}
                                </div>
                            )}

                            {message && (
                                <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                                    {message}
                                </div>
                            )}

                            {showingRecentProducts && (
                                <h2 className="mb-3 text-sm font-semibold uppercase text-slate-500">
                                    {t('pos.recent_products')}
                                </h2>
                            )}

                            {!search && !selectedCategoryId && currentRecentProducts.length === 0 && (
                                <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center text-slate-500">
                                    {t('pos.no_recent_products')}
                                </div>
                            )}

                            {(search || selectedCategoryId) && productsToShow.length === 0 && (
                                <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center text-slate-500">
                                    {t('pos.no_products_found')}
                                </div>
                            )}

                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                {productsToShow.map((product) => {
                                    const availableStock = Math.floor(product.available_stock ?? product.stock);
                                    const reservedStock = Math.floor(product.reserved_stock ?? 0);
                                    const outOfStock = availableStock <= 0;
                                    const lowStock =
                                        availableStock > 0 && availableStock <= product.min_stock;

                                    return (
                                        <button
                                            key={product.id}
                                            type="button"
                                            disabled={outOfStock || processing}
                                            onClick={() => addProduct(product)}
                                            className="flex items-stretch gap-3 rounded-2xl border border-slate-200 bg-white p-3 text-left shadow-[0_4px_18px_rgba(15,23,42,0.05)] transition-all duration-200 hover:-translate-y-0.5 hover:border-indigo-200 hover:shadow-[0_12px_28px_rgba(15,23,42,0.10)] disabled:cursor-not-allowed disabled:bg-slate-50 disabled:opacity-70"
                                        >
                                            {use_product_images && product.image_url ? (
                                                <img
                                                    src={getProductImageUrl(product.image_url, 200) ?? ''}
                                                    alt={product.name}
                                                    loading="lazy"
                                                        className="h-24 w-24 shrink-0 rounded-xl object-cover"
                                                />
                                            ) : null}

                                            <div className="flex min-w-0 flex-1 flex-col justify-between">
                                                <div>
                                                    <h3 className="truncate text-sm font-semibold text-slate-900">
                                                        {product.name}
                                                    </h3>
                                                    <div className="mt-1 whitespace-nowrap text-right text-sm font-bold text-slate-900">
                                                        {formatCurrency(product.sale_price, country)}
                                                    </div>
                                                </div>

                                                <div className="truncate text-xs text-slate-500">
                                                    {product.barcode || product.code || t('common.code')}
                                                </div>

                                                <div className="flex items-center justify-between gap-2 text-xs">
                                                    <span
                                                        className={
                                                            outOfStock
                                                                ? 'font-semibold text-red-600'
                                                                : lowStock
                                                                  ? 'font-semibold text-orange-600'
                                                                  : 'text-slate-700'
                                                        }
                                                    >
                                                        {outOfStock
                                                            ? t('stock_warning.out_of_stock')
                                                            : lowStock
                                                              ? `${t('stock_warning.low_stock')} (${availableStock})`
                                                              : `Disponible ${availableStock}`}
                                                    </span>
                                                    {reservedStock > 0 && (
                                                        <span className="text-slate-500">Reservado {reservedStock}</span>
                                                    )}
                                                    {product.location && (
                                                        <span className="truncate text-slate-500">
                                                            {product.location}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    </section>

                    <form
                        onSubmit={openCheckout}
                        className="flex min-h-0 flex-col rounded-2xl border border-slate-200 bg-white shadow-[0_8px_30px_rgba(15,23,42,0.08)]"
                    >
                        <header className="border-b border-slate-200 p-4">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h2 className="text-xl font-semibold text-slate-900">
                                        {t('cart.title')}
                                    </h2>
                                    <p className="text-sm text-slate-500">
                                        {cartItemsCount} {t('unit.units')} | {cart.length} {t('unit.products')}
                                    </p>
                                    {pageProps.branches_enabled && pageProps.active_branch && (
                                        <p className="mt-1 text-xs font-semibold text-indigo-600">
                                            Sucursal activa: {pageProps.active_branch.name}
                                        </p>
                                    )}
                                </div>
                                <div className="flex flex-wrap justify-end gap-2">
                                    {canApplyDiscount && (
                                        <button
                                            type="button"
                                            onClick={openDiscountModal}
                                            className="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-100"
                                        >
                                            Aplicar descuento
                                        </button>
                                    )}
                                    <button
                                        type="button"
                                        onClick={requestClearSale}
                                        className="rounded-lg px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50"
                                    >
                                        Limpiar venta
                                    </button>
                                </div>
                            </div>
                        </header>

                        <div className="min-h-0 flex-1 overflow-y-auto p-3">
                            {cart.length === 0 && (
                                <div className="flex h-full items-center justify-center rounded-xl border border-dashed border-slate-300 p-6 text-center text-slate-500">
                                    {t('cart.empty')}
                                </div>
                            )}

                            <div>
                                {cart.map((item) => (
                                    <div
                                        key={item.product.id}
                                        className="grid grid-cols-[minmax(0,1fr)_144px_180px_120px_32px] items-center gap-3 border-b border-slate-100 py-2 last:border-b-0"
                                    >
                                        <div className="flex min-w-0 flex-1 items-center text-sm">
                                            <span className="shrink-0 font-normal text-gray-500">
                                                {item.product.barcode || item.product.code || t('common.code')}
                                            </span>
                                            <span className="shrink-0 px-1 text-slate-400">-</span>
                                            <span className="truncate font-semibold text-slate-900">
                                                {item.product.name}
                                            </span>
                                            {item.credit_receipt_number && (
                                                <span className="ml-2 shrink-0 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-700">
                                                    {item.credit_receipt_number}
                                                </span>
                                            )}
                                        </div>

                                        <div className="flex shrink-0 flex-col">
                                            <div className="flex items-center">
                                                <button
                                                    type="button"
                                                    disabled={processing}
                                                    onClick={() => changeQuantity(item.product.id, -1)}
                                                    className="h-9 w-9 rounded-l-md border border-slate-300 text-base font-semibold text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                                >
                                                    -
                                                </button>
                                                <input
                                                    type="text"
                                                    min="1"
                                                    max={Math.floor(item.max_quantity ?? item.product.available_stock ?? item.product.stock)}
                                                    step="1"
                                                    inputMode="numeric"
                                                    pattern="[0-9]*"
                                                    value={item.quantity}
                                                    onChange={(event) =>
                                                        updateQuantity(
                                                            item.product.id,
                                                            event.target.value,
                                                        )
                                                    }
                                                    onWheel={(event) => event.currentTarget.blur()}
                                                    className={`h-9 w-20 border-y bg-white text-center text-sm font-semibold text-slate-900 outline-none focus:ring-2 focus:ring-indigo-500 ${
                                                        quantityError(item.quantity) ? 'border-red-300 text-red-700' : 'border-slate-300'
                                                    }`}
                                                />
                                                <button
                                                    type="button"
                                                    disabled={processing}
                                                    onClick={() => changeQuantity(item.product.id, 1)}
                                                    className="h-9 w-9 rounded-r-md border border-slate-300 text-base font-semibold text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                                >
                                                    +
                                                </button>
                                            </div>
                                            {quantityError(item.quantity) && (
                                                <p className="mt-1 text-xs font-semibold text-red-600">
                                                    {integerQuantityMessage}
                                                </p>
                                            )}
                                        </div>

                                        <div className="flex min-w-0 flex-col gap-1">
                                            {price_types.length > 1 && !item.locked_credit_line && (
                                                <select
                                                    value={item.price_type_id ?? ''}
                                                    onChange={(event) => changeLinePriceType(item.product.id, Number(event.target.value))}
                                                    className="h-8 rounded-lg border-slate-200 bg-white text-xs font-semibold text-slate-700 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"
                                                >
                                                    {price_types.map((priceType) => (
                                                        <option key={priceType.id} value={priceType.id}>
                                                            {priceType.name}
                                                        </option>
                                                    ))}
                                                </select>
                                            )}
                                            <div className="flex items-center gap-2">
                                                {item.manual_price ? (
                                                    <input
                                                        type="number"
                                                        min="0.01"
                                                        step="0.01"
                                                        value={item.unit_price}
                                                        onChange={(event) => updateManualPrice(item.product.id, event.target.value)}
                                                        className="h-8 w-24 rounded-lg border-slate-200 bg-white text-right text-xs font-semibold text-slate-900 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"
                                                    />
                                                ) : (
                                                    <span className="whitespace-nowrap text-xs font-semibold text-slate-700">
                                                        {formatCurrency(Number(item.unit_price), country)}
                                                    </span>
                                                )}
                                                <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">
                                                    {priceSourceLabel(item.price_source)}
                                                </span>
                                            </div>
                                            {item.price_warning && (
                                                <p className="text-[11px] font-semibold text-amber-600">{item.price_warning}</p>
                                            )}
                                            {item.manual_price && manualPriceError(item, manualPriceMinMargin) && (
                                                <p className="text-[11px] font-semibold text-red-600">
                                                    {manualPriceError(item, manualPriceMinMargin)}
                                                </p>
                                            )}
                                            {canUseManualPrice && !item.locked_credit_line && (
                                                <button
                                                    type="button"
                                                    onClick={() => setManualPriceProductId(item.product.id)}
                                                    className="self-start text-[11px] font-semibold text-indigo-600 hover:text-indigo-700"
                                                >
                                                    {item.manual_price ? 'Editar precio manual' : 'Precio manual'}
                                                </button>
                                            )}
                                        </div>

                                        <div className="w-[120px] shrink-0 whitespace-nowrap text-right text-sm font-semibold text-slate-900">
                                            {formatCurrency(Number(item.unit_price) * quantityNumber(item.quantity), country)}
                                        </div>

                                        <button
                                            type="button"
                                            title={t('actions.remove')}
                                            aria-label={t('actions.remove')}
                                            disabled={processing}
                                            onClick={() => removeProduct(item.product.id)}
                                            className="shrink-0 rounded-md px-2 py-1 text-base text-red-500 hover:bg-red-50 hover:text-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            🗑
                                        </button>
                                    </div>
                                ))}
                            </div>
                        </div>

                        <footer className="border-t border-slate-200 bg-slate-50/70 p-4">
                            {!hasOpenCashRegister && (
                                <div className="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">
                                    <span>No hay caja abierta. Abre caja para registrar ventas.</span>
                                    <Link href={route('cash-register.index')} className="rounded-lg bg-amber-100 px-3 py-1 text-xs font-bold text-amber-900 hover:bg-amber-200">
                                        Abrir caja
                                    </Link>
                                </div>
                            )}
                            <div className="flex items-end justify-between">
                                <span className="text-sm font-semibold uppercase text-slate-500">
                                    {discount ? 'Total final' : t('common.total')}
                                </span>
                                <span className="whitespace-nowrap text-4xl font-bold tracking-normal text-slate-900">
                                    {formatCurrency(total, country)}
                                </span>
                            </div>
                            {discount && (
                                <div className="mt-3 space-y-1 rounded-xl border border-indigo-100 bg-white px-4 py-3 text-sm">
                                    <div className="flex justify-between gap-3 text-slate-600">
                                        <span>Total antes</span>
                                        <strong className="whitespace-nowrap text-slate-900">
                                            {formatCurrency(subtotalBeforeDiscount, country)}
                                        </strong>
                                    </div>
                                    <div className="flex justify-between gap-3 text-indigo-700">
                                        <span>Descuento</span>
                                        <strong className="whitespace-nowrap">
                                            -{formatCurrency(discountAmount, country)}
                                        </strong>
                                    </div>
                                    <div className="flex justify-between gap-3 text-slate-950">
                                        <span>Total final</span>
                                        <strong className="whitespace-nowrap">
                                            {formatCurrency(total, country)}
                                        </strong>
                                    </div>
                                    <div className="flex items-center justify-between gap-3 pt-1 text-xs text-slate-500">
                                        <span className="truncate">Motivo: {discount.reason}</span>
                                        <button
                                            type="button"
                                            onClick={() => setDiscount(null)}
                                            className="shrink-0 font-semibold text-red-600 hover:text-red-700"
                                        >
                                            Quitar
                                        </button>
                                    </div>
                                    {activeDiscountError && (
                                        <div className="text-xs font-semibold text-red-600">
                                            {activeDiscountError}
                                        </div>
                                    )}
                                </div>
                            )}

                            <button
                                type="submit"
                                disabled={cart.length === 0 || processing || (!hasOpenCashRegister && !credit_available) || hasInvalidCartQuantities || noAvailableDocumentTypes}
                                className="mt-4 h-14 w-full rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 text-lg font-semibold text-white shadow-lg shadow-indigo-200 transition-all duration-200 hover:-translate-y-0.5 hover:from-indigo-700 hover:to-violet-700 active:scale-[0.98] disabled:cursor-not-allowed disabled:bg-none disabled:bg-slate-300 disabled:shadow-none"
                            >
                                {isFelProcessing ? 'Certificando FEL...' : (processing ? t('pos.finalizing') : t('pos.finalize_sale'))}
                            </button>
                        </footer>
                    </form>
                </div>
            </div>

            {manualPriceProductId && (() => {
                const item = cart.find((cartItem) => cartItem.product.id === manualPriceProductId);

                if (!item) {
                    return null;
                }

                return (
                    <div className="fixed inset-0 z-[90] flex items-end justify-center bg-slate-950/50 p-4 backdrop-blur-sm sm:items-center">
                        <section className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl">
                            <div className="flex items-start justify-between gap-4">
                                <div className="min-w-0">
                                    <h2 className="text-lg font-semibold text-slate-950">Precio manual</h2>
                                    <p className="mt-1 truncate text-sm text-slate-500">{item.product.name}</p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setManualPriceProductId(null)}
                                    className="rounded-xl border border-slate-200 px-3 py-1.5 text-sm font-semibold text-slate-600 hover:bg-slate-50"
                                >
                                    Cerrar
                                </button>
                            </div>

                            <div className="mt-5 space-y-4">
                                <div>
                                    <div className="mb-2 text-xs font-semibold uppercase text-slate-500">Porcentaje</div>
                                    <div className="flex flex-wrap gap-2">
                                        {[10, 15, 20, 25, 30, 50]
                                            .filter((percent) => percent >= manualPriceMinMargin)
                                            .map((percent) => (
                                                <button
                                                    key={percent}
                                                    type="button"
                                                    disabled={processing}
                                                    onClick={() => applyCostMarkup(item.product.id, percent)}
                                                    className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:border-indigo-200 hover:bg-indigo-50 disabled:cursor-not-allowed disabled:opacity-50"
                                                >
                                                    +{percent}%
                                                </button>
                                            ))}
                                        <input
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            placeholder="Otro %"
                                            disabled={processing}
                                            onChange={(event) => {
                                                const percent = Number(event.target.value);

                                                if (Number.isFinite(percent) && percent >= manualPriceMinMargin) {
                                                    applyCostMarkup(item.product.id, percent);
                                                }
                                            }}
                                            className="h-10 w-28 rounded-xl border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"
                                        />
                                    </div>
                                </div>

                                <label className="block">
                                    <span className="text-sm font-medium text-slate-700">Precio unitario</span>
                                    <input
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        value={item.unit_price}
                                        disabled={processing}
                                        onChange={(event) => updateManualPrice(item.product.id, event.target.value)}
                                        className="mt-1 h-12 w-full rounded-xl border-slate-200 text-lg font-semibold text-slate-950 focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                    />
                                </label>

                                {manualPriceError(item, manualPriceMinMargin) && (
                                    <p className="rounded-xl border border-red-100 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700">
                                        {manualPriceError(item, manualPriceMinMargin)}
                                    </p>
                                )}

                                <button
                                    type="button"
                                    disabled={Boolean(manualPriceError(item, manualPriceMinMargin))}
                                    onClick={() => {
                                        enableManualPrice(item.product.id);
                                        setManualPriceProductId(null);
                                    }}
                                    className="h-12 w-full rounded-xl bg-indigo-600 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300"
                                >
                                    Aplicar
                                </button>
                            </div>
                        </section>
                    </div>
                );
            })()}

            {(isFelProcessing || openingFelPrint) && (
                <div className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm">
                    <div className="w-full max-w-sm rounded-2xl border border-indigo-100 bg-white px-6 py-7 text-center shadow-2xl">
                        <div className="mx-auto h-9 w-9 animate-spin rounded-full border-4 border-indigo-100 border-t-indigo-600" />
                        <div className="mt-4 text-lg font-semibold text-slate-950">
                            {openingFelPrint ? 'Factura certificada. Abriendo impresión...' : 'Certificando factura FEL...'}
                        </div>
                        {!openingFelPrint && <div className="mt-2 text-sm text-slate-600">No cierres esta ventana.</div>}
                    </div>
                </div>
            )}

            {showCheckoutModal && (
                <div className="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
                    <div className="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-2xl border border-slate-200 bg-white shadow-2xl">
                        <div className="border-b border-slate-200 p-5">
                            <div className="flex items-start justify-between gap-4">
                                <div>
                                    <h2 className="text-2xl font-semibold text-slate-950">Cobrar venta</h2>
                                    <p className="mt-1 text-sm text-slate-500">Total a cobrar</p>
                                </div>
                                <div className="whitespace-nowrap text-right text-3xl font-bold text-slate-950">
                                    {formatCurrency(total, country)}
                                </div>
                            </div>
                        </div>

                        <div className="space-y-5 p-5">
                            {checkoutHasErrors && (
                                <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                                    {cashRegisterError || 'No se pudo completar la venta. Revisa los datos ingresados.'}
                                </div>
                            )}

                            {isFelProcessing && (
                                <div className="rounded-xl border border-indigo-100 bg-indigo-50 px-4 py-3 text-sm text-indigo-800">
                                    <div className="font-semibold">Certificando factura FEL...</div>
                                    <div className="mt-1">No cierres esta ventana.</div>
                                </div>
                            )}

                            {customerModalErrors.length > 0 && (
                                <section className="rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-700">
                                    <div className="font-semibold">Datos del cliente</div>
                                    <ul className="mt-1 list-inside list-disc space-y-1">
                                        {customerModalErrors.map((error, index) => (
                                            <li key={`${error}-${index}`}>{error}</li>
                                        ))}
                                    </ul>
                                </section>
                            )}

                            <section>
                                <div className="mb-3 flex items-center justify-between gap-3">
                                    <h3 className="text-sm font-semibold uppercase tracking-wide text-slate-600">
                                        Forma de pago principal
                                    </h3>
                                    <label className="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                                        <input
                                            type="checkbox"
                                            checked={splitPayment}
                                            onChange={(event) => {
                                                const checked = event.target.checked;
                                                setSplitPayment(checked);
                                                setPayments([paymentLine(mainPaymentMethod, total.toFixed(2))]);
                                            }}
                                            className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                        />
                                        Dividir pago
                                    </label>
                                </div>

                                <div className="flex flex-wrap gap-2">
                                    {paymentMethods.map((method) => (
                                        <button
                                            key={method.value}
                                            type="button"
                                            onClick={() => {
                                                setMainPaymentMethod(method.value);
                                                if (!splitPayment) {
                                                    setPayments([paymentLine(method.value, total.toFixed(2))]);
                                                }
                                            }}
                                            className={`rounded-full border px-4 py-2 text-sm font-semibold transition ${
                                                mainPaymentMethod === method.value
                                                    ? 'border-indigo-600 bg-indigo-600 text-white shadow-md shadow-indigo-200'
                                                    : 'border-slate-200 bg-white text-slate-700 hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700'
                                            }`}
                                        >
                                            {method.label}
                                        </button>
                                    ))}
                                </div>
                            </section>

                            {!splitPayment && payments[0] && (
                                <PaymentDetailsFields
                                    payment={payments[0]}
                                    index={0}
                                    onChange={updatePaymentDetail}
                                    errors={typedErrors}
                                />
                            )}

                            {splitPayment && (
                                <section className="space-y-3">
                                    <div className="overflow-x-auto rounded-xl border border-slate-200">
                                        <table className="min-w-full text-sm">
                                            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                                <tr>
                                                    <th className="px-3 py-2">Método</th>
                                                    <th className="px-3 py-2">Monto</th>
                                                    <th className="px-3 py-2 text-right">Eliminar</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {payments.map((payment, index) => (
                                                    <tr key={index} className="border-t border-slate-100 align-top">
                                                        <td className="px-3 py-2">
                                                            <select
                                                                value={payment.method}
                                                                onChange={(event) => updatePayment(index, 'method', event.target.value)}
                                                                className={[
                                                                    'h-10 w-full rounded-xl bg-white text-sm text-slate-900 focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100',
                                                                    paymentFieldError(index, 'method') ? 'border-red-300' : 'border-slate-200',
                                                                ].join(' ')}
                                                            >
                                                                {paymentMethods.map((method) => (
                                                                    <option key={method.value} value={method.value}>
                                                                        {method.label}
                                                                    </option>
                                                                ))}
                                                            </select>
                                                            <PaymentDetailsFields
                                                                payment={payment}
                                                                index={index}
                                                                onChange={updatePaymentDetail}
                                                                errors={typedErrors}
                                                                compact
                                                            />
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            <input
                                                                type="number"
                                                                min="0"
                                                                step="0.01"
                                                                value={payment.amount}
                                                                onChange={(event) => updatePayment(index, 'amount', event.target.value)}
                                                                className={[
                                                                    'h-10 w-32 rounded-xl bg-white text-right text-sm font-semibold text-slate-900 focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100',
                                                                    paymentFieldError(index, 'amount') ? 'border-red-300' : 'border-slate-200',
                                                                ].join(' ')}
                                                            />
                                                            {paymentFieldError(index, 'amount') && (
                                                                <div className="mt-1 text-xs font-semibold text-red-600">
                                                                    {paymentFieldError(index, 'amount')}
                                                                </div>
                                                            )}
                                                        </td>
                                                        <td className="px-3 py-2 text-right">
                                                            <button
                                                                type="button"
                                                                onClick={() => removePaymentLine(index)}
                                                                className="rounded-lg px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50"
                                                            >
                                                                Eliminar
                                                            </button>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>

                                    <div className="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            onClick={addPaymentLine}
                                            className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                                        >
                                            Agregar forma de pago
                                        </button>
                                        <button
                                            type="button"
                                            onClick={assignPending}
                                            className="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-100"
                                        >
                                            Asignar pendiente
                                        </button>
                                    </div>
                                </section>
                            )}

                            <section>
                                <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-600">
                                    Documento
                                </h3>
                                {availableCheckoutTypes.length > 1 && (
                                    <div className="grid gap-3 sm:grid-cols-3">
                                        <button
                                            type="button"
                                            onClick={() => setDocumentType('receipt')}
                                            className={`rounded-2xl border px-4 py-3 text-left transition ${
                                                documentType === 'receipt'
                                                    ? 'border-indigo-600 bg-indigo-50 text-indigo-700 shadow-sm'
                                                    : 'border-slate-200 bg-white text-slate-700 hover:border-indigo-200 hover:bg-indigo-50'
                                            }`}
                                        >
                                            <div className="font-semibold">Comprobante</div>
                                            <div className="mt-1 text-xs text-slate-500">Genera impresión al finalizar.</div>
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setDocumentType('invoice')}
                                            className={`rounded-2xl border px-4 py-3 text-left transition ${
                                                documentType === 'invoice'
                                                    ? 'border-indigo-600 bg-indigo-50 text-indigo-700 shadow-sm'
                                                    : 'border-slate-200 bg-white text-slate-700 hover:border-indigo-200 hover:bg-indigo-50'
                                            }`}
                                        >
                                            <div className="font-semibold">Factura</div>
                                            <div className="mt-1 text-xs text-slate-500">Certifica FEL con Digifact.</div>
                                        </button>
                                        {credit_available && (
                                            <button
                                                type="button"
                                                onClick={() => setDocumentType('credit')}
                                                className={`rounded-2xl border px-4 py-3 text-left transition ${
                                                    documentType === 'credit'
                                                        ? 'border-indigo-600 bg-indigo-50 text-indigo-700 shadow-sm'
                                                        : 'border-slate-200 bg-white text-slate-700 hover:border-indigo-200 hover:bg-indigo-50'
                                                }`}
                                            >
                                                <div className="font-semibold">Crédito</div>
                                                <div className="mt-1 text-xs text-slate-500">Reserva productos sin facturar.</div>
                                            </button>
                                        )}
                                    </div>
                                )}
                                {singleDocumentType && (
                                    <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700">
                                        Documento: {singleDocumentType === 'invoice' ? 'Factura FEL' : (singleDocumentType === 'credit' ? 'Crédito' : 'Comprobante')}
                                    </div>
                                )}
                                {noAvailableDocumentTypes && (
                                    <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                                        No hay ningún tipo de documento habilitado para esta empresa.
                                    </div>
                                )}
                                {documentError && (
                                    <div className="mt-2 text-sm font-semibold text-red-600">
                                        {documentError}
                                    </div>
                                )}
                                {invoiceNitNeedsVerification && (
                                    <div className="mt-2 rounded-xl border border-amber-100 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-700">
                                        El NIT del cliente no ha sido validado.
                                    </div>
                                )}
                                {invoiceCuiDisabled && (
                                    <div className="mt-2 rounded-xl border border-amber-100 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-700">
                                        CUI/DPI aún no está habilitado.
                                    </div>
                                )}
                            </section>

                            <section className="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 md:grid-cols-4">
                                {discount && (
                                    <>
                                        <Summary label="Total antes" value={formatCurrency(subtotalBeforeDiscount, country)} />
                                        <Summary label="Descuento" value={`-${formatCurrency(discountAmount, country)}`} />
                                    </>
                                )}
                                <Summary label="Total venta" value={formatCurrency(total, country)} />
                                <Summary label="Total pagado" value={formatCurrency(paidTotal, country)} />
                                <Summary
                                    label={pendingAmount < 0 ? 'Excedente' : 'Pendiente'}
                                    value={formatCurrency(Math.abs(pendingAmount), country)}
                                    danger={pendingAmount !== 0}
                                />
                            </section>

                            {splitPayment && !paymentIsBalanced && (
                                <div className="rounded-xl border border-amber-100 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-700">
                                    El total pagado debe coincidir con el total de la venta.
                                </div>
                            )}

                            {paymentsError && (
                                <div className="rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                                    {paymentsError}
                                </div>
                            )}

                            {paymentModalErrors.length > 0 && !paymentsError && (
                                <div className="rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                                    {paymentModalErrors[0]}
                                </div>
                            )}
                        </div>

                        <div className="flex justify-end gap-3 border-t border-slate-200 bg-slate-50 p-5">
                            <button
                                type="button"
                                onClick={() => setShowCheckoutModal(false)}
                                disabled={processing}
                                className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                Cancelar
                            </button>
                            <button
                                type="button"
                                onClick={submitSale}
                                disabled={cart.length === 0 || processing || creditProcessing || (effectiveCheckoutType !== 'credit' && !paymentIsBalanced) || invoiceNitNeedsVerification || invoiceCuiDisabled || hasInvalidCartQuantities || noAvailableDocumentTypes}
                                className="rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-5 py-2 text-sm font-semibold text-white shadow-lg shadow-indigo-200 hover:from-indigo-700 hover:to-violet-700 disabled:cursor-not-allowed disabled:bg-none disabled:bg-slate-300 disabled:shadow-none"
                            >
                                {isFelProcessing ? 'Certificando FEL...' : ((processing || creditProcessing) ? 'Confirmando...' : (effectiveCheckoutType === 'credit' ? 'Confirmar crédito' : 'Confirmar venta'))}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {restoreDraft && (
                <div className="fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
                    <section className="w-full max-w-md rounded-2xl border border-amber-100 bg-white p-6 shadow-2xl">
                        <h2 className="text-lg font-semibold text-slate-950">
                            Trabajo pendiente encontrado
                        </h2>
                        <p className="mt-2 text-sm text-slate-600">
                            Se encontró una venta en proceso guardada automáticamente.
                        </p>
                        <div className="mt-5 flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => {
                                    clearDraft(draftKey);
                                    setRestoreDraft(null);
                                    setDraftReady(true);
                                    focusSearch();
                                }}
                                className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                Descartar
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    restorePosDraft(restoreDraft);
                                    setRestoreDraft(null);
                                    setDraftReady(true);
                                    focusSearch();
                                }}
                                className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
                            >
                                Continuar
                            </button>
                        </div>
                    </section>
                </div>
            )}

            {showDiscountModal && (
                <div className="fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
                    <section className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl">
                        <h2 className="text-lg font-semibold text-slate-950">
                            Aplicar descuento
                        </h2>
                        <div className="mt-5 space-y-4">
                            <label className="block">
                                <span className="text-sm font-semibold text-slate-700">Tipo</span>
                                <select
                                    value={discountForm.type}
                                    onChange={(event) => setDiscountForm((current) => ({
                                        ...current,
                                        type: event.target.value as SaleDiscount['type'],
                                    }))}
                                    className="mt-1 h-11 w-full rounded-xl border-slate-300 text-sm font-semibold text-slate-900 focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                >
                                    <option value="fixed">Monto fijo</option>
                                    <option value="percent">Porcentaje</option>
                                </select>
                            </label>

                            <label className="block">
                                <span className="text-sm font-semibold text-slate-700">Valor</span>
                                <input
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    value={discountForm.value}
                                    onChange={(event) => setDiscountForm((current) => ({
                                        ...current,
                                        value: event.target.value,
                                    }))}
                                    className="mt-1 h-11 w-full rounded-xl border-slate-300 text-right text-sm font-semibold text-slate-900 focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                />
                            </label>

                            <label className="block">
                                <span className="text-sm font-semibold text-slate-700">Motivo</span>
                                <textarea
                                    value={discountForm.reason}
                                    onChange={(event) => setDiscountForm((current) => ({
                                        ...current,
                                        reason: event.target.value,
                                    }))}
                                    rows={3}
                                    className="mt-1 w-full rounded-xl border-slate-300 text-sm text-slate-900 focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                />
                            </label>

                            <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm">
                                <div className="flex justify-between gap-3 text-slate-600">
                                    <span>Total antes</span>
                                    <strong className="whitespace-nowrap text-slate-900">
                                        {formatCurrency(subtotalBeforeDiscount, country)}
                                    </strong>
                                </div>
                                <div className="mt-1 flex justify-between gap-3 text-indigo-700">
                                    <span>Descuento</span>
                                    <strong className="whitespace-nowrap">
                                        -{formatCurrency(calculateDiscountAmount(discountForm, subtotalBeforeDiscount), country)}
                                    </strong>
                                </div>
                            </div>

                            {discountFormError && (
                                <div className="rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                                    {discountFormError}
                                </div>
                            )}
                        </div>
                        <div className="mt-5 flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => setShowDiscountModal(false)}
                                className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                Cancelar
                            </button>
                            <button
                                type="button"
                                onClick={applyDiscount}
                                className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
                            >
                                Aplicar descuento
                            </button>
                        </div>
                    </section>
                </div>
            )}

            {showClearSaleModal && (
                <div className="fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
                    <section className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl">
                        <h2 className="text-lg font-semibold text-slate-950">
                            Limpiar venta
                        </h2>
                        <p className="mt-2 text-sm text-slate-600">
                            ¿Seguro que deseas limpiar esta venta?
                        </p>
                        <div className="mt-5 flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => setShowClearSaleModal(false)}
                                className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                Cancelar
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    setShowClearSaleModal(false);
                                    clearPosDraftAndState();
                                }}
                                className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
                            >
                                Limpiar venta
                            </button>
                        </div>
                    </section>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

function Summary({ label, value, danger = false }: { label: string; value: string; danger?: boolean }) {
    return (
        <div>
            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</div>
            <div className={`mt-1 text-lg font-bold ${danger ? 'text-amber-700' : 'text-slate-950'}`}>
                {value}
            </div>
        </div>
    );
}

function PaymentDetailsFields({
    payment,
    index,
    onChange,
    errors,
    compact = false,
}: {
    payment: PaymentLine;
    index: number;
    onChange: (index: number, field: keyof PaymentDetails, value: string) => void;
    errors: Record<string, string>;
    compact?: boolean;
}) {
    const fields = paymentDetailFields(payment.method);

    if (fields.length === 0) {
        return null;
    }

    return (
        <div className={compact ? 'mt-2 grid gap-2 sm:grid-cols-2' : 'grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:grid-cols-2'}>
            {fields.map((field) => {
                const error = errors[`payments.${index}.details.${field.key}`];

                return (
                    <label key={field.key} className="block">
                        <span className="text-xs font-semibold text-slate-600">{field.label}</span>
                        <input
                            value={payment.details[field.key]}
                            onChange={(event) => onChange(index, field.key, event.target.value)}
                            placeholder={field.placeholder}
                            className={[
                                'mt-1 h-9 w-full rounded-xl bg-white text-sm text-slate-900 focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100',
                                error ? 'border-red-300' : 'border-slate-200',
                            ].join(' ')}
                        />
                        {error && <span className="mt-1 block text-xs font-semibold text-red-600">{error}</span>}
                    </label>
                );
            })}
        </div>
    );
}

function paymentDetailFields(method: string): { key: keyof PaymentDetails; label: string; placeholder?: string }[] {
    const fields: Record<string, { key: keyof PaymentDetails; label: string; placeholder?: string }[]> = {
        card: [
            { key: 'authorization', label: 'Autorización / voucher', placeholder: 'Voucher 99881 / Auth ABC123 / POS 445566' },
        ],
        transfer: [
            { key: 'bank', label: 'Banco' },
            { key: 'transfer_reference', label: 'Referencia / número de operación' },
        ],
        check: [
            { key: 'bank', label: 'Banco' },
            { key: 'check_number', label: 'Número de cheque' },
        ],
        mercadopago: [
            { key: 'mercadopago_reference', label: 'Referencia / operación' },
        ],
    };

    return fields[method] ?? [];
}
