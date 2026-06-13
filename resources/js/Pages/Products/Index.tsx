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
import { ChangeEvent, FormEvent, useEffect, useMemo, useState } from 'react';

type Category = {
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
    supplier_cost_history: SupplierCostHistory[];
    prices: ProductPrice[];
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
    prices: {},
};

export default function ProductIndex({
    products,
    priceTypes,
    categories,
    filters,
    pricingScope = 'global',
    activeBranch = null,
    use_product_images = true,
}: {
    products: Product[];
    priceTypes: PriceType[];
    categories: Category[];
    filters: { search: string };
    pricingScope?: 'global' | 'branch';
    activeBranch?: { id: number; name: string } | null;
    use_product_images?: boolean;
}) {
    const business = usePage().props.business as { country?: string | null } | null;
    const country = business?.country ?? 'GT';
    const [editing, setEditing] = useState<Product | null>(null);
    const [search, setSearch] = useState(filters.search ?? '');
    const [imagePreview, setImagePreview] = useState<string | null>(null);
    const [imageError, setImageError] = useState('');
    const [showCategorySuggestions, setShowCategorySuggestions] = useState(false);
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
                    setEditing(null);
                    clearImagePreview();
                    reset();
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
                reset();
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

    function applySearch(event: FormEvent) {
        event.preventDefault();
        router.get(route('products.index'), { search }, { preserveState: true });
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

            <div className="mx-auto grid max-w-[1800px] gap-5 px-5 py-5 lg:grid-cols-[380px_1fr] sm:px-6">
                <form
                    onSubmit={submit}
                    autoComplete="off"
                    className="space-y-4 rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]"
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
                        <InputLabel htmlFor="category_name" value={t('products.category')} />
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
                            placeholder={t('products.category_placeholder')}
                            value={data.category_name}
                            onBlur={() => setTimeout(() => setShowCategorySuggestions(false), 120)}
                            onChange={(e) => {
                                setData('category_name', e.target.value);
                                setShowCategorySuggestions(true);
                            }}
                            onFocus={() => setShowCategorySuggestions(true)}
                        />
                        <p className="mt-1 text-xs text-slate-500">
                            {t('products.category_helper')}
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

                    <div>
                        <InputLabel htmlFor="location" value={t('common.location')} />
                        <TextInput id="location" className="mt-1 block w-full" value={data.location} onChange={(e) => setData('location', e.target.value)} />
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

                <section className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                    <form onSubmit={applySearch} className="mb-4 flex gap-2">
                        <TextInput className="w-full" placeholder={t('products.search_placeholder')} value={search} onChange={(e) => setSearch(e.target.value)} />
                        <PrimaryButton>{t('actions.search')}</PrimaryButton>
                    </form>

                    {identityMatches.length > 0 && (
                        <section className="mb-4 rounded-xl border border-amber-200 bg-amber-50/80 p-4">
                            <div>
                                <h3 className="text-sm font-semibold text-amber-950">Coincidencias encontradas</h3>
                                <p className="mt-1 text-xs text-amber-800">Revisa estos productos antes de guardar.</p>
                            </div>
                            <div className="mt-3 grid gap-3 lg:grid-cols-2">
                                {identityMatches.map((match) => {
                                    const visibleProduct = products.find((product) => product.id === match.id);

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
                        <table className="min-w-full divide-y divide-slate-100 text-sm">
                            <thead>
                                <tr className="bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    {use_product_images && <th className="py-2 pr-3">{t('products.image')}</th>}
                                    <th className="py-2 pr-3">{t('products.product')}</th>
                                    <th className="px-3 py-2">{t('products.category')}</th>
                                    <th className="px-3 py-2">{t('common.code')}</th>
                                    <th className="px-3 py-2">{t('common.barcode')}</th>
                                    <th className="px-3 py-2">{t('stock.stock')}</th>
                                    <th className="px-3 py-2">{t('common.location')}</th>
                                    <th className="px-3 py-2">{t('products.price')}</th>
                                    <th className="py-2 pl-3"></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {products.map((product) => (
                                    <tr
                                        key={product.id}
                                        className={`transition-colors ${identityMatchIds.has(product.id) ? 'bg-amber-50 ring-1 ring-inset ring-amber-200' : 'hover:bg-indigo-50/30'}`}
                                    >
                                        {use_product_images && (
                                            <td className="py-3 pr-3">
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
                                        <td className="py-3 pr-3 font-medium text-slate-950">{product.name}</td>
                                        <td className="px-3 py-3 text-slate-600">{product.category?.name ?? '-'}</td>
                                        <td className="px-3 py-3 text-slate-600">{product.code ?? '-'}</td>
                                        <td className="px-3 py-3 text-slate-600">{product.barcode ?? '-'}</td>
                                        <td className="px-3 py-3 text-slate-950">{product.stock}</td>
                                        <td className="px-3 py-3 text-slate-600">{product.location ?? '-'}</td>
                                        <td className="whitespace-nowrap px-3 py-3 text-slate-950">
                                            {formatCurrency(product.sale_price, country)}
                                        </td>
                                        <td className="py-3 pl-3 text-right">
                                            <Link
                                                href={route('products.stock-history', product.id)}
                                                className="mr-3 rounded-lg px-2 py-1 text-sm font-medium text-indigo-600 hover:bg-indigo-50"
                                            >
                                                Historial
                                            </Link>
                                            <button type="button" onClick={() => edit(product)} className="rounded-lg px-2 py-1 text-sm font-medium text-indigo-600 hover:bg-indigo-50">
                                                {t('actions.edit')}
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
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
