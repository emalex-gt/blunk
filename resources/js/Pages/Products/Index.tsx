import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SupplierInfoPopover from '@/Components/SupplierInfoPopover';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { getProductImageUrl } from '@/lib/cloudinary';
import { compressImage } from '@/lib/images';
import { t } from '@/lib/i18n';
import { formatCurrency } from '@/utils/currency';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ChangeEvent, FormEvent, useEffect, useMemo, useRef, useState } from 'react';

type Category = {
    id: number;
    name: string;
};

type Brand = {
    id: number;
    name: string;
};

type ProductLocation = {
    id: number;
    name: string;
};

type Product = {
    id: number;
    name: string;
    code: string | null;
    barcode: string | null;
    cost_price: string;
    sale_price: string;
    stock: number;
    min_stock: number;
    location: string | null;
    is_active: boolean;
    image_url: string | null;
    image_public_id: string | null;
    category_id: number | null;
    category: Category | null;
    brand_id: number | null;
    brand: Brand | null;
    location_id: number | null;
    product_location: ProductLocation | null;
    supplier_cost_history: SupplierCostHistory[];
    prices: ProductPrice[];
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedProducts = {
    data: Product[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    per_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type PriceType = {
    id: number;
    name: string;
    is_default: boolean;
    is_active: boolean;
};

type ProductPrice = {
    id: number;
    price_type_id: number;
    price: string | number;
};

type SupplierCostHistory = {
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

type ProductForm = {
    name: string;
    code: string;
    barcode: string;
    cost_price: string;
    sale_price: string;
    stock: string;
    min_stock: string;
    location: string;
    is_active: boolean;
    image: File | null;
    category_name: string;
    brand_name: string;
    location_name: string;
    prices: Record<string, string>;
};

type IdentityMatch = {
    id: number;
    name: string;
    code: string | null;
    barcode: string | null;
    category: string | null;
    stock: number | string;
    location: string | null;
    price: string | number;
    image_url: string | null;
};

type IdentityMessages = Partial<Record<'code' | 'barcode', string>>;

const emptyForm: ProductForm = {
    name: '',
    code: '',
    barcode: '',
    cost_price: '0',
    sale_price: '0',
    stock: '0',
    min_stock: '0',
    location: '',
    is_active: true,
    image: null,
    category_name: '',
    brand_name: '',
    location_name: '',
    prices: {},
};

export default function ProductIndex({
    products,
    priceTypes,
    categories,
    brands,
    locations,
    filters,
    pricingScope = 'global',
    activeBranch = null,
    use_product_images = true,
}: {
    products: PaginatedProducts;
    priceTypes: PriceType[];
    categories: Category[];
    brands: Brand[];
    locations: ProductLocation[];
    filters: {
        search: string;
        category_id?: number | null;
        brand_id?: number | null;
        location_id?: number | null;
        status?: string;
        per_page?: number;
    };
    pricingScope?: 'global' | 'branch';
    activeBranch?: { id: number; name: string } | null;
    use_product_images?: boolean;
}) {
    const business = usePage().props.business as { country?: string | null } | null;
    const country = business?.country ?? 'GT';
    const [editing, setEditing] = useState<Product | null>(null);
    const [search, setSearch] = useState(filters.search ?? '');
    const didMountSearch = useRef(false);
    const [filterState, setFilterState] = useState({
        category_id: filters.category_id ? String(filters.category_id) : '',
        brand_id: filters.brand_id ? String(filters.brand_id) : '',
        location_id: filters.location_id ? String(filters.location_id) : '',
        status: filters.status ?? '',
        per_page: String(filters.per_page ?? products.per_page ?? 25),
    });
    const [imagePreview, setImagePreview] = useState<string | null>(null);
    const [imageError, setImageError] = useState('');
    const [showCategorySuggestions, setShowCategorySuggestions] = useState(false);
    const [showBrandSuggestions, setShowBrandSuggestions] = useState(false);
    const [showLocationSuggestions, setShowLocationSuggestions] = useState(false);
    const { data, setData, post, processing, errors, reset, transform } =
        useForm<ProductForm>(emptyForm);
    const [identityErrors, setIdentityErrors] = useState<IdentityMessages>({});
    const [identityWarnings, setIdentityWarnings] = useState<IdentityMessages>({});
    const [identityMatches, setIdentityMatches] = useState<IdentityMatch[]>([]);
    const [identityChecking, setIdentityChecking] = useState(false);
    const hasIdentityError = Boolean(identityErrors.code || identityErrors.barcode);
    const identityMatchIds = useMemo(
        () => new Set(identityMatches.map((match) => match.id)),
        [identityMatches],
    );
    const currentProducts = products.data;

    function submit(event: FormEvent) {
        event.preventDefault();

        if (hasIdentityError) {
            return;
        }

        if (editing) {
            transform((formData) => ({
                ...formData,
                _method: 'put',
            }));

            post(route('products.update', editing.id), {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => {
                    clearImagePreview();
                },
            });
            return;
        }

        transform((formData) => formData);

        post(route('products.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                clearImagePreview();
            },
        });
    }

    function edit(product: Product) {
        setEditing(product);
        setData({
            name: product.name,
            code: product.code ?? '',
            barcode: product.barcode ?? '',
            cost_price: product.cost_price,
            sale_price: product.sale_price,
            stock: String(product.stock),
            min_stock: String(product.min_stock),
            location: product.location ?? '',
            is_active: product.is_active,
            image: null,
            category_name: product.category?.name ?? '',
            brand_name: product.brand?.name ?? '',
            location_name: product.product_location?.name ?? product.location ?? '',
            prices: priceTypes.reduce<Record<string, string>>((values, priceType) => {
                const existing = product.prices?.find((price) => Number(price.price_type_id) === Number(priceType.id));
                values[String(priceType.id)] = existing ? String(existing.price) : '';

                return values;
            }, {}),
        });
        clearImagePreview();
    }

    const categorySuggestions = useMemo(() => {
        const term = data.category_name.trim().toLowerCase();

        return categories
            .filter((category) => !term || category.name.toLowerCase().includes(term))
            .slice(0, 8);
    }, [categories, data.category_name]);

    const brandSuggestions = useMemo(() => {
        const term = data.brand_name.trim().toLowerCase();

        return brands
            .filter((brand) => !term || brand.name.toLowerCase().includes(term))
            .slice(0, 8);
    }, [brands, data.brand_name]);

    const locationSuggestions = useMemo(() => {
        const term = data.location_name.trim().toLowerCase();

        return locations
            .filter((location) => !term || location.name.toLowerCase().includes(term))
            .slice(0, 8);
    }, [locations, data.location_name]);

    function applySearch(event: FormEvent) {
        event.preventDefault();
        router.get(route('products.index'), productQueryParams(), { preserveState: true, preserveScroll: true });
    }

    function productQueryParams(overrides: Record<string, string | number | null> = {}) {
        const params = {
            search,
            category_id: filterState.category_id,
            brand_id: filterState.brand_id,
            location_id: filterState.location_id,
            status: filterState.status,
            per_page: filterState.per_page,
            ...overrides,
        };

        return Object.fromEntries(
            Object.entries(params).filter(([, value]) => value !== '' && value !== null && value !== undefined),
        );
    }

    function updateFilter(field: keyof typeof filterState, value: string) {
        const next = { ...filterState, [field]: value };
        setFilterState(next);

        router.get(
            route('products.index'),
            Object.fromEntries(
                Object.entries({
                    search,
                    ...next,
                    page: 1,
                }).filter(([, current]) => current !== '' && current !== null && current !== undefined),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function clearImagePreview() {
        setImagePreview((current) => {
            if (current) {
                URL.revokeObjectURL(current);
            }

            return null;
        });
        setImageError('');
        setData('image', null);
    }

    async function handleImageChange(event: ChangeEvent<HTMLInputElement>) {
        const file = event.target.files?.[0] ?? null;

        if (!file) {
            clearImagePreview();
            return;
        }

        if (!file.type.startsWith('image/')) {
            setImageError(t('products.image_processing_failed'));
            return;
        }

        if (file.size > 20 * 1024 * 1024) {
            setImageError(t('products.image_too_large'));
            event.target.value = '';
            return;
        }

        try {
            const compressed = await compressImage(file);
            const previewUrl = URL.createObjectURL(compressed);

            setImagePreview((current) => {
                if (current) {
                    URL.revokeObjectURL(current);
                }

                return previewUrl;
            });
            setImageError('');
            setData('image', compressed);
        } catch {
            setImageError(t('products.image_processing_failed'));
            event.target.value = '';
        }
    }

    function imageInitials(name: string) {
        return name
            .split(' ')
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0])
            .join('')
            .toUpperCase();
    }

    useEffect(() => {
        const code = data.code.trim();
        const barcode = data.barcode.trim();

        if (!code && !barcode) {
            setIdentityErrors({});
            setIdentityWarnings({});
            setIdentityMatches([]);
            setIdentityChecking(false);
            return;
        }

        const controller = new AbortController();
        const timeout = window.setTimeout(() => {
            const params = new URLSearchParams();

            if (code) {
                params.set('code', code);
            }

            if (barcode) {
                params.set('barcode', barcode);
            }

            if (editing?.id) {
                params.set('ignore_product_id', String(editing.id));
            }

            setIdentityChecking(true);

            fetch(`${route('products.check-identity')}?${params.toString()}`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('identity-check-failed');
                    }

                    return response.json() as Promise<{
                        errors?: IdentityMessages;
                        warnings?: IdentityMessages;
                        matches?: IdentityMatch[];
                    }>;
                })
                .then((payload) => {
                    setIdentityErrors(payload.errors ?? {});
                    setIdentityWarnings(payload.warnings ?? {});
                    setIdentityMatches(payload.matches ?? []);
                })
                .catch((error) => {
                    if (error instanceof DOMException && error.name === 'AbortError') {
                        return;
                    }
                })
                .finally(() => {
                    if (!controller.signal.aborted) {
                        setIdentityChecking(false);
                    }
                });
        }, 400);

        return () => {
            window.clearTimeout(timeout);
            controller.abort();
        };
    }, [data.code, data.barcode, editing?.id]);

    useEffect(() => {
        if (!didMountSearch.current) {
            didMountSearch.current = true;
            return;
        }

        const timeout = window.setTimeout(() => {
            router.get(route('products.index'), productQueryParams({ page: 1 }), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, 400);

        return () => window.clearTimeout(timeout);
    }, [search]);

    useEffect(() => {
        return () => {
            if (imagePreview) {
                URL.revokeObjectURL(imagePreview);
            }
        };
    }, [imagePreview]);

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold text-slate-950">{t('products.products')}</h2>}
        >
            <Head title={t('products.products')} />

            <div className="mx-auto grid max-w-[1800px] gap-5 px-4 py-5 sm:px-5 lg:grid-cols-[360px_minmax(0,1fr)] xl:grid-cols-[380px_minmax(0,1fr)] 2xl:grid-cols-[420px_minmax(0,1fr)]">
                <form
                    onSubmit={submit}
                    autoComplete="off"
                    className="min-w-0 space-y-4 rounded-2xl border border-slate-200/80 bg-white/95 p-4 shadow-[0_8px_30px_rgba(15,23,42,0.06)] 2xl:p-5"
                >
                    <h3 className="text-base font-semibold text-slate-950">
                        {editing ? t('products.form_edit') : t('products.form_new')}
                    </h3>

                    {use_product_images && (
                        <div>
                            <InputLabel htmlFor="image" value={t('products.image')} />
                            <div className="mt-2 flex items-center gap-3">
                                {imagePreview || editing?.image_url ? (
                                    <img
                                        src={imagePreview ?? getProductImageUrl(editing?.image_url ?? null, 160) ?? ''}
                                        alt={t('products.image')}
                                        className="h-16 w-16 rounded-md object-cover"
                                    />
                                ) : (
                                    <div className="flex h-16 w-16 items-center justify-center rounded-xl bg-slate-100 text-xs font-semibold text-slate-500">
                                        {t('products.no_image')}
                                    </div>
                                )}
                                <label className="inline-flex cursor-pointer items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                                    {t('products.select_image')}
                                    <input
                                        id="image"
                                        type="file"
                                        accept="image/*"
                                        onChange={handleImageChange}
                                        className="sr-only"
                                    />
                                </label>
                            </div>
                            {(imageError || errors.image) && (
                                <InputError message={imageError || errors.image} className="mt-2" />
                            )}
                        </div>
                    )}

                    <div>
                        <InputLabel htmlFor="name" value={t('products.name')} />
                        <TextInput id="name" className="mt-1 block w-full" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                        <InputError message={errors.name} className="mt-2" />
                    </div>

                    <div className="relative">
                        <div className="flex items-center justify-between gap-3">
                            <InputLabel htmlFor="category_name" value={t('products.category')} />
                            <Link href={route('categories.index')} className="text-xs font-semibold text-indigo-600 hover:text-indigo-700">
                                Gestionar categorías
                            </Link>
                        </div>
                        <input
                            type="text"
                            className="hidden"
                            autoComplete="username"
                            tabIndex={-1}
                            aria-hidden="true"
                        />
                        <TextInput
                            id="category_name"
                            type="text"
                            name="category_input"
                            autoComplete="off"
                            autoCorrect="off"
                            autoCapitalize="none"
                            spellCheck={false}
                            inputMode="text"
                            className="mt-1 block w-full"
                            placeholder="Buscar o crear categoría"
                            value={data.category_name}
                            onBlur={() => setTimeout(() => setShowCategorySuggestions(false), 120)}
                            onChange={(e) => {
                                setData('category_name', e.target.value);
                                setShowCategorySuggestions(true);
                            }}
                            onFocus={() => setShowCategorySuggestions(true)}
                        />
                        <p className="mt-1 text-xs text-slate-500">
                            Escribe una categoría existente o una nueva. Se creará al guardar.
                        </p>
                        {showCategorySuggestions && categorySuggestions.length > 0 && (
                            <div className="absolute z-20 mt-1 max-h-48 w-full overflow-y-auto rounded-md border border-gray-200 bg-white shadow-lg">
                                {categorySuggestions.map((category) => (
                                    <button
                                        key={category.id}
                                        type="button"
                                        className="block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-indigo-50"
                                        onMouseDown={(event) => {
                                            event.preventDefault();
                                            setData('category_name', category.name);
                                            setShowCategorySuggestions(false);
                                        }}
                                    >
                                        {category.name}
                                    </button>
                                ))}
                            </div>
                        )}
                        <InputError message={errors.category_name} className="mt-2" />
                    </div>

                    <div className="relative">
                        <div className="flex items-center justify-between gap-3">
                            <InputLabel htmlFor="brand_name" value="Marca" />
                            <Link href={route('brands.index')} className="text-xs font-semibold text-indigo-600 hover:text-indigo-700">
                                Gestionar marcas
                            </Link>
                        </div>
                        <TextInput
                            id="brand_name"
                            type="text"
                            autoComplete="off"
                            autoCorrect="off"
                            autoCapitalize="none"
                            spellCheck={false}
                            className="mt-1 block w-full"
                            placeholder="Buscar o crear marca"
                            value={data.brand_name}
                            onBlur={() => setTimeout(() => setShowBrandSuggestions(false), 120)}
                            onChange={(e) => {
                                setData('brand_name', e.target.value);
                                setShowBrandSuggestions(true);
                            }}
                            onFocus={() => setShowBrandSuggestions(true)}
                        />
                        <p className="mt-1 text-xs text-slate-500">
                            Escribe una marca existente o una nueva. Se creará al guardar.
                        </p>
                        {showBrandSuggestions && brandSuggestions.length > 0 && (
                            <div className="absolute z-20 mt-1 max-h-48 w-full overflow-y-auto rounded-md border border-gray-200 bg-white shadow-lg">
                                {brandSuggestions.map((brand) => (
                                    <button
                                        key={brand.id}
                                        type="button"
                                        className="block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-indigo-50"
                                        onMouseDown={(event) => {
                                            event.preventDefault();
                                            setData('brand_name', brand.name);
                                            setShowBrandSuggestions(false);
                                        }}
                                    >
                                        {brand.name}
                                    </button>
                                ))}
                            </div>
                        )}
                        <InputError message={errors.brand_name} className="mt-2" />
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <InputLabel htmlFor="code" value={t('common.code')} />
                            <TextInput id="code" className="mt-1 block w-full" value={data.code} onChange={(e) => setData('code', e.target.value)} />
                            <InputError message={errors.code || identityErrors.code} className="mt-2" />
                            {!errors.code && !identityErrors.code && identityWarnings.code && (
                                <p className="mt-2 text-xs font-medium text-amber-700">{identityWarnings.code}</p>
                            )}
                        </div>
                        <div>
                            <InputLabel htmlFor="barcode" value={t('common.barcode')} />
                            <TextInput id="barcode" className="mt-1 block w-full" value={data.barcode} onChange={(e) => setData('barcode', e.target.value)} />
                            <InputError message={errors.barcode || identityErrors.barcode} className="mt-2" />
                            {!errors.barcode && !identityErrors.barcode && identityWarnings.barcode && (
                                <p className="mt-2 text-xs font-medium text-amber-700">{identityWarnings.barcode}</p>
                            )}
                        </div>
                    </div>

                    {identityChecking && (
                        <p className="-mt-2 text-xs text-slate-500">Verificando código y código de barras...</p>
                    )}

                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <InputLabel htmlFor="cost_price" value={t('products.cost')} />
                            <TextInput id="cost_price" type="number" step="0.01" className="mt-1 block w-full" value={data.cost_price} onChange={(e) => setData('cost_price', e.target.value)} />
                        </div>
                        <div>
                            <InputLabel
                                htmlFor="sale_price"
                                value={pricingScope === 'branch' && activeBranch ? `Precio de sucursal: ${activeBranch.name}` : t('products.sale_price')}
                            />
                            <TextInput id="sale_price" type="number" step="0.01" className="mt-1 block w-full" value={data.sale_price} onChange={(e) => setData('sale_price', e.target.value)} />
                        </div>
                    </div>

                    {priceTypes.length > 1 && (
                        <section className="rounded-xl border border-slate-200 bg-slate-50/70 p-3">
                            <h4 className="text-sm font-semibold text-slate-900">
                                {pricingScope === 'branch' && activeBranch ? `Precios de sucursal: ${activeBranch.name}` : 'Precios por lista'}
                            </h4>
                            <div className="mt-3 space-y-2">
                                {priceTypes.map((priceType) => (
                                    <label key={priceType.id} className="grid grid-cols-[1fr_140px] items-center gap-3 text-sm">
                                        <span className="font-medium text-slate-700">
                                            {priceType.name}
                                            {priceType.is_default && <span className="ml-2 text-xs text-indigo-600">Predeterminada</span>}
                                        </span>
                                        <TextInput
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            value={data.prices[String(priceType.id)] ?? ''}
                                            onChange={(event) => setData('prices', {
                                                ...data.prices,
                                                [String(priceType.id)]: event.target.value,
                                            })}
                                            placeholder={data.sale_price}
                                            className="w-full"
                                        />
                                    </label>
                                ))}
                            </div>
                        </section>
                    )}

                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <InputLabel htmlFor="stock" value={t('stock.stock')} />
                            <TextInput id="stock" type="number" className="mt-1 block w-full" value={data.stock} onChange={(e) => setData('stock', e.target.value)} />
                        </div>
                        <div>
                            <InputLabel htmlFor="min_stock" value={t('products.min_stock')} />
                            <TextInput id="min_stock" type="number" className="mt-1 block w-full" value={data.min_stock} onChange={(e) => setData('min_stock', e.target.value)} />
                        </div>
                    </div>

                    <div className="relative">
                        <div className="flex items-center justify-between gap-3">
                            <InputLabel htmlFor="location_name" value={t('common.location')} />
                            <Link href={route('product-locations.index')} className="text-xs font-semibold text-indigo-600 hover:text-indigo-700">
                                Gestionar ubicaciones
                            </Link>
                        </div>
                        <TextInput
                            id="location_name"
                            className="mt-1 block w-full"
                            placeholder="Buscar o crear ubicación"
                            value={data.location_name}
                            autoComplete="off"
                            autoCorrect="off"
                            autoCapitalize="none"
                            spellCheck={false}
                            onBlur={() => setTimeout(() => setShowLocationSuggestions(false), 120)}
                            onChange={(e) => {
                                const value = e.target.value;
                                setData({
                                    ...data,
                                    location_name: value,
                                    location: value,
                                });
                                setShowLocationSuggestions(true);
                            }}
                            onFocus={() => setShowLocationSuggestions(true)}
                        />
                        <p className="mt-1 text-xs text-slate-500">
                            Escribe una ubicación existente o una nueva. Se creará al guardar.
                        </p>
                        {showLocationSuggestions && locationSuggestions.length > 0 && (
                            <div className="absolute z-20 mt-1 max-h-48 w-full overflow-y-auto rounded-md border border-gray-200 bg-white shadow-lg">
                                {locationSuggestions.map((location) => (
                                    <button
                                        key={location.id}
                                        type="button"
                                        className="block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-indigo-50"
                                        onMouseDown={(event) => {
                                            event.preventDefault();
                                            setData({
                                                ...data,
                                                location_name: location.name,
                                                location: location.name,
                                            });
                                            setShowLocationSuggestions(false);
                                        }}
                                    >
                                        {location.name}
                                    </button>
                                ))}
                            </div>
                        )}
                        <InputError message={errors.location_name || errors.location} className="mt-2" />
                    </div>

                    <label className="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)} className="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                        {t('common.active')}
                    </label>

                    <div className="flex gap-2">
                        <PrimaryButton disabled={processing || hasIdentityError}>
                            {editing ? t('actions.update') : t('actions.create')}
                        </PrimaryButton>
                        {editing && (
                            <SecondaryButton type="button" onClick={() => { setEditing(null); reset(); }}>
                                {t('actions.cancel')}
                            </SecondaryButton>
                        )}
                    </div>

                    {editing && (
                        <SupplierCostHistorySection
                            history={editing.supplier_cost_history ?? []}
                            country={country}
                        />
                    )}
                </form>

                <section className="min-w-0 overflow-hidden rounded-2xl border border-slate-200/80 bg-white/95 p-4 shadow-[0_8px_30px_rgba(15,23,42,0.06)] 2xl:p-5">
                    <form onSubmit={applySearch} className="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-[minmax(18rem,1fr)_150px_150px_150px_130px_140px_auto]">
                        <TextInput className="w-full sm:col-span-2 xl:col-span-3 2xl:col-span-1" placeholder={t('products.search_placeholder')} value={search} onChange={(e) => setSearch(e.target.value)} />
                        <select
                            value={filterState.category_id}
                            onChange={(event) => updateFilter('category_id', event.target.value)}
                            className="rounded-xl border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100"
                        >
                            <option value="">Categoría</option>
                            {categories.map((category) => (
                                <option key={category.id} value={category.id}>{category.name}</option>
                            ))}
                        </select>
                        <select
                            value={filterState.brand_id}
                            onChange={(event) => updateFilter('brand_id', event.target.value)}
                            className="rounded-xl border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100"
                        >
                            <option value="">Marca</option>
                            {brands.map((brand) => (
                                <option key={brand.id} value={brand.id}>{brand.name}</option>
                            ))}
                        </select>
                        <select
                            value={filterState.location_id}
                            onChange={(event) => updateFilter('location_id', event.target.value)}
                            className="rounded-xl border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100"
                        >
                            <option value="">Ubicación</option>
                            {locations.map((location) => (
                                <option key={location.id} value={location.id}>{location.name}</option>
                            ))}
                        </select>
                        <select
                            value={filterState.status}
                            onChange={(event) => updateFilter('status', event.target.value)}
                            className="rounded-xl border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100"
                        >
                            <option value="">Estado</option>
                            <option value="active">Activos</option>
                            <option value="inactive">Inactivos</option>
                        </select>
                        <select
                            value={filterState.per_page}
                            onChange={(event) => updateFilter('per_page', event.target.value)}
                            className="rounded-xl border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100"
                            aria-label="Productos por página"
                        >
                            <option value="25">25 por página</option>
                            <option value="50">50 por página</option>
                            <option value="100">100 por página</option>
                        </select>
                        <PrimaryButton className="justify-center xl:col-span-3 2xl:col-span-1">{t('actions.search')}</PrimaryButton>
                    </form>

                    {identityMatches.length > 0 && (
                        <section className="mb-4 rounded-xl border border-amber-200 bg-amber-50/80 p-4">
                            <div>
                                <h3 className="text-sm font-semibold text-amber-950">Coincidencias encontradas</h3>
                                <p className="mt-1 text-xs text-amber-800">Revisa estos productos antes de guardar.</p>
                            </div>
                            <div className="mt-3 grid gap-3 lg:grid-cols-2">
                                {identityMatches.map((match) => {
                                    const visibleProduct = currentProducts.find((product) => product.id === match.id);

                                    return (
                                        <article key={match.id} className="flex gap-3 rounded-lg border border-amber-200 bg-white p-3 shadow-sm">
                                            {use_product_images && (
                                                match.image_url ? (
                                                    <img
                                                        src={getProductImageUrl(match.image_url, 96) ?? ''}
                                                        alt={match.name}
                                                        loading="lazy"
                                                        className="h-12 w-12 rounded-md object-cover"
                                                    />
                                                ) : (
                                                    <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-xs font-semibold text-amber-800">
                                                        {imageInitials(match.name)}
                                                    </div>
                                                )
                                            )}
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-semibold text-slate-950">{match.name}</p>
                                                <p className="mt-1 text-xs text-slate-600">{match.category ?? '-'}</p>
                                                <div className="mt-2 grid grid-cols-2 gap-2 text-xs text-slate-700">
                                                    <span>Código: <strong>{match.code ?? '-'}</strong></span>
                                                    <span>Barras: <strong>{match.barcode ?? '-'}</strong></span>
                                                    <span>Stock: <strong>{match.stock}</strong></span>
                                                    <span>Precio: <strong>{formatCurrency(match.price, country)}</strong></span>
                                                    <span className="col-span-2">Ubicación: <strong>{match.location ?? '-'}</strong></span>
                                                </div>
                                                <div className="mt-3 flex gap-2">
                                                    <button
                                                        type="button"
                                                        className="rounded-lg px-2 py-1 text-xs font-semibold text-indigo-600 hover:bg-indigo-50 disabled:cursor-not-allowed disabled:text-slate-400"
                                                        disabled={!visibleProduct}
                                                        onClick={() => visibleProduct && edit(visibleProduct)}
                                                    >
                                                        Editar
                                                    </button>
                                                    <Link
                                                        href={route('products.stock-history', match.id)}
                                                        className="rounded-lg px-2 py-1 text-xs font-semibold text-indigo-600 hover:bg-indigo-50"
                                                    >
                                                        Ver historial
                                                    </Link>
                                                </div>
                                            </div>
                                        </article>
                                    );
                                })}
                            </div>
                        </section>
                    )}

                    <div className="overflow-x-auto">
                        <table className="min-w-full table-fixed divide-y divide-slate-100 text-[11px] xl:text-xs 2xl:table-auto 2xl:text-sm">
                            <thead>
                                <tr className="bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    {use_product_images && <th className="hidden py-2 pr-3 2xl:table-cell">{t('products.image')}</th>}
                                    <th className="w-[30%] py-2 pr-2 2xl:w-auto 2xl:pr-3">{t('products.product')}</th>
                                    <th className="hidden px-3 py-2 2xl:table-cell">{t('products.category')}</th>
                                    <th className="hidden px-3 py-2 2xl:table-cell">Marca</th>
                                    <th className="w-[12%] px-1.5 py-2 2xl:w-auto 2xl:px-3">{t('common.code')}</th>
                                    <th className="w-[15%] px-1.5 py-2 2xl:w-auto 2xl:px-3">{t('common.barcode')}</th>
                                    <th className="w-[7%] px-1.5 py-2 2xl:w-auto 2xl:px-3">{t('stock.stock')}</th>
                                    <th className="w-[13%] px-1.5 py-2 2xl:w-auto 2xl:px-3">{t('common.location')}</th>
                                    <th className="w-[10%] px-1.5 py-2 2xl:w-auto 2xl:px-3">{t('products.price')}</th>
                                    <th className="w-[13%] py-2 pl-1.5 2xl:w-auto 2xl:pl-3"></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {currentProducts.map((product) => (
                                    <tr
                                        key={product.id}
                                        className={`transition-colors ${identityMatchIds.has(product.id) ? 'bg-amber-50 ring-1 ring-inset ring-amber-200' : 'hover:bg-indigo-50/30'}`}
                                    >
                                        {use_product_images && (
                                            <td className="hidden py-3 pr-3 2xl:table-cell">
                                                {product.image_url ? (
                                                    <img
                                                        src={getProductImageUrl(product.image_url, 96) ?? ''}
                                                        alt={product.name}
                                                        loading="lazy"
                                                        className="h-10 w-10 rounded-md object-cover"
                                                    />
                                                ) : (
                                                    <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-xs font-semibold text-slate-500">
                                                        {imageInitials(product.name)}
                                                    </div>
                                                )}
                                            </td>
                                        )}
                                        <td className="py-2.5 pr-2 align-top 2xl:py-3 2xl:pr-3">
                                            <div className="line-clamp-2 font-semibold leading-4 text-slate-950 2xl:leading-5" title={product.name}>
                                                {product.name}
                                            </div>
                                            {(product.category?.name || product.brand?.name) && (
                                                <div className="mt-1 line-clamp-2 text-[10px] leading-3.5 text-slate-500 xl:text-[11px] xl:leading-4 2xl:hidden">
                                                    {[product.category?.name && `Categoría: ${product.category.name}`, product.brand?.name && `Marca: ${product.brand.name}`].filter(Boolean).join(' · ')}
                                                </div>
                                            )}
                                        </td>
                                        <td className="hidden px-3 py-3 text-slate-600 2xl:table-cell">{product.category?.name ?? '-'}</td>
                                        <td className="hidden px-3 py-3 text-slate-600 2xl:table-cell">{product.brand?.name ?? '-'}</td>
                                        <td className="px-1.5 py-2.5 text-slate-600 2xl:px-3 2xl:py-3">
                                            <span className="line-clamp-2 break-words" title={product.code ?? '-'}>
                                                {product.code ?? '-'}
                                            </span>
                                        </td>
                                        <td className="px-1.5 py-2.5 text-slate-600 2xl:px-3 2xl:py-3">
                                            <span className="line-clamp-2 break-words" title={product.barcode ?? '-'}>
                                                {product.barcode ?? '-'}
                                            </span>
                                        </td>
                                        <td className="whitespace-nowrap px-1.5 py-2.5 text-slate-950 2xl:px-3 2xl:py-3">{product.stock}</td>
                                        <td className="px-1.5 py-2.5 text-slate-600 2xl:px-3 2xl:py-3">
                                            <span className="line-clamp-2" title={product.product_location?.name ?? product.location ?? '-'}>
                                                {product.product_location?.name ?? product.location ?? '-'}
                                            </span>
                                        </td>
                                        <td className="whitespace-nowrap px-1.5 py-2.5 text-slate-950 2xl:px-3 2xl:py-3">
                                            {formatCurrency(product.sale_price, country)}
                                        </td>
                                        <td className="whitespace-nowrap py-2.5 pl-1.5 text-right 2xl:py-3 2xl:pl-3">
                                            <Link
                                                href={route('products.stock-history', product.id)}
                                                className="mr-1 rounded-lg px-1 py-1 font-medium text-indigo-600 hover:bg-indigo-50 xl:mr-2 xl:px-2"
                                            >
                                                Historial
                                            </Link>
                                            <button type="button" onClick={() => edit(product)} className="rounded-lg px-1 py-1 font-medium text-indigo-600 hover:bg-indigo-50 xl:px-2">
                                                {t('actions.edit')}
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="mt-4 flex flex-col gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-sm text-slate-600">
                            Mostrando {products.from ?? 0}-{products.to ?? 0} de {products.total} productos
                        </p>
                        <div className="flex flex-wrap items-center gap-2">
                            <Link
                                href={products.prev_page_url ?? '#'}
                                preserveScroll
                                preserveState
                                className={`rounded-lg px-3 py-2 text-sm font-semibold ${products.prev_page_url ? 'bg-slate-100 text-slate-700 hover:bg-slate-200' : 'pointer-events-none bg-slate-50 text-slate-400'}`}
                            >
                                Anterior
                            </Link>
                            <span className="px-2 text-sm font-medium text-slate-600">
                                Página {products.current_page} de {products.last_page}
                            </span>
                            <Link
                                href={products.next_page_url ?? '#'}
                                preserveScroll
                                preserveState
                                className={`rounded-lg px-3 py-2 text-sm font-semibold ${products.next_page_url ? 'bg-slate-100 text-slate-700 hover:bg-slate-200' : 'pointer-events-none bg-slate-50 text-slate-400'}`}
                            >
                                Siguiente
                            </Link>
                        </div>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function SupplierCostHistorySection({
    history,
    country,
}: {
    history: SupplierCostHistory[];
    country: string;
}) {
    return (
        <section className="border-t border-slate-100 pt-4">
            <h4 className="text-sm font-semibold text-slate-950">
                Últimos costos por proveedor
            </h4>

            {history.length === 0 ? (
                <p className="mt-2 rounded-xl bg-slate-50 p-3 text-sm text-slate-500">
                    No hay compras registradas para este producto.
                </p>
            ) : (
                <div className="mt-3 overflow-hidden rounded-xl border border-slate-200">
                    <table className="min-w-full text-xs">
                        <thead>
                            <tr className="bg-slate-50 text-left font-semibold uppercase tracking-wide text-slate-500">
                                <th className="px-3 py-2">Proveedor</th>
                                <th className="px-3 py-2 text-right">Último costo</th>
                                <th className="px-3 py-2">Fecha</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {history.map((row) => (
                                <tr key={`${row.supplier_id}-${row.purchase_id}`} className="bg-white">
                                    <td className="px-3 py-2 text-slate-900">
                                        <SupplierInfoPopover supplier={row} />
                                    </td>
                                    <td className="whitespace-nowrap px-3 py-2 text-right font-semibold text-slate-900">
                                        {formatCurrency(row.unit_cost, country)}
                                    </td>
                                    <td className="px-3 py-2 text-slate-600">
                                        {row.created_at_formatted ?? '-'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}
