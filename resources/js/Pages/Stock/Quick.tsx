import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { getProductImageUrl } from '@/lib/cloudinary';
import { t } from '@/lib/i18n';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, KeyboardEvent, useEffect, useMemo, useRef, useState } from 'react';

type Product = {
    id: number;
    name: string;
    code: string | null;
    barcode: string | null;
    stock: number;
    min_stock: number;
    location: string | null;
    image_url: string | null;
};

type Mode = 'entry' | 'exit' | 'adjustment';

const modes: { value: Mode; label: string }[] = [
    { value: 'entry', label: '+ Entrada' },
    { value: 'exit', label: '- Salida' },
    { value: 'adjustment', label: 'Ajuste' },
];

export default function StockQuick({
    products,
    use_product_images = true,
}: {
    products: Product[];
    use_product_images?: boolean;
}) {
    const [items, setItems] = useState(products);
    const [search, setSearch] = useState('');
    const [selectedProduct, setSelectedProduct] = useState<Product | null>(null);
    const [mode, setMode] = useState<Mode>('entry');
    const [quantity, setQuantity] = useState('1');
    const [note, setNote] = useState('');
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const searchInputRef = useRef<HTMLInputElement>(null);
    const quantityInputRef = useRef<HTMLInputElement>(null);
    const messageTimerRef = useRef<number | null>(null);

    const filteredProducts = useMemo(() => {
        const term = search.toLowerCase().trim();

        if (!term) {
            return items.slice(0, 32);
        }

        return items
            .filter((product) =>
                [product.name, product.code, product.barcode]
                    .filter(Boolean)
                    .some((field) => field!.toLowerCase().includes(term)),
            )
            .slice(0, 32);
    }, [items, search]);

    function showMessage(value: string) {
        setMessage(value);
        setError('');

        if (messageTimerRef.current) {
            window.clearTimeout(messageTimerRef.current);
        }

        messageTimerRef.current = window.setTimeout(() => setMessage(''), 3000);
    }

    function showError(value: string) {
        setError(value);
        setMessage('');

        if (messageTimerRef.current) {
            window.clearTimeout(messageTimerRef.current);
        }

        messageTimerRef.current = window.setTimeout(() => setError(''), 4000);
    }

    function selectProduct(product: Product) {
        setSelectedProduct(product);
        setQuantity('1');
        setNote('');
        setError('');

        requestAnimationFrame(() => quantityInputRef.current?.focus());
    }

    function handleSearchKeyDown(event: KeyboardEvent<HTMLInputElement>) {
        if (event.key === 'Escape') {
            event.preventDefault();
            setSearch('');
            searchInputRef.current?.focus();
            return;
        }

        if (event.key !== 'Enter') {
            return;
        }

        event.preventDefault();

        if (filteredProducts.length === 1) {
            selectProduct(filteredProducts[0]);
            setSearch('');
            return;
        }

        if (filteredProducts.length === 0) {
            showError('No se encontró producto.');
            return;
        }

        showError('Hay varias coincidencias. Selecciona una de la lista.');
    }

    function saveMovement(event: FormEvent) {
        event.preventDefault();

        if (!selectedProduct || saving) {
            return;
        }

        const value = Number(quantity);

        if (!Number.isInteger(value) || value < 0 || (mode !== 'adjustment' && value < 1)) {
            showError('Ingresa una cantidad válida.');
            return;
        }

        if ((mode === 'exit' || mode === 'adjustment') && !note.trim()) {
            showError('La nota es obligatoria para salida y ajuste.');
            return;
        }

        setSaving(true);
        setError('');

        router.post(
            route('stock.quick.store'),
            {
                product_id: selectedProduct.id,
                type: mode,
                quantity: value,
                note,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setQuantity('1');
                    setNote('');
                    showMessage('Stock actualizado');
                    requestAnimationFrame(() => quantityInputRef.current?.focus());
                },
                onError: (errors) => {
                    const firstError = Object.values(errors)[0];
                    showError(String(firstError ?? 'No se pudo guardar.'));
                },
                onFinish: () => setSaving(false),
            },
        );
    }

    useEffect(() => {
        setItems(products);
        setSelectedProduct((current) => {
            if (!current) {
                return current;
            }

            return products.find((product) => product.id === current.id) ?? current;
        });
    }, [products]);

    useEffect(() => {
        return () => {
            if (messageTimerRef.current) {
                window.clearTimeout(messageTimerRef.current);
            }
        };
    }, []);

    return (
        <AuthenticatedLayout>
            <Head title={t('nav.stock')} />

            <div className="h-[calc(100vh-4rem)] bg-[#f4f6fb]">
                <div className="mx-auto grid h-full max-w-[1800px] gap-5 p-5 lg:grid-cols-[minmax(0,1fr)_420px]">
                    <section className="flex min-h-0 flex-col rounded-2xl border border-slate-200/80 bg-white/95 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="border-b border-gray-200 p-4">
                            <h1 className="text-xl font-semibold text-slate-950">Stock</h1>
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
                            {error && (
                                <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
                                    {error}
                                </div>
                            )}

                            {message && (
                                <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                                    {message}
                                </div>
                            )}

                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                {filteredProducts.map((product) => {
                                    const isSelected = selectedProduct?.id === product.id;
                                    const outOfStock = product.stock <= 0;
                                    const lowStock =
                                        product.stock > 0 && product.stock <= product.min_stock;

                                    return (
                                        <button
                                            key={product.id}
                                            type="button"
                                            onClick={() => selectProduct(product)}
                                            className={`flex items-stretch gap-3 rounded-2xl border bg-white p-3 text-left shadow-[0_4px_18px_rgba(15,23,42,0.05)] transition-all duration-200 hover:-translate-y-0.5 hover:border-indigo-200 hover:shadow-[0_12px_28px_rgba(15,23,42,0.10)] ${
                                                isSelected
                                                    ? 'border-indigo-400 ring-4 ring-indigo-100'
                                                    : 'border-slate-200'
                                            }`}
                                        >
                                            {use_product_images && product.image_url ? (
                                                <img
                                                    src={getProductImageUrl(product.image_url, 200) ?? ''}
                                                    alt={product.name}
                                                    loading="lazy"
                                                    className="h-24 w-24 shrink-0 rounded-md object-cover"
                                                />
                                            ) : null}

                                            <div className="flex min-w-0 flex-1 flex-col justify-between">
                                                <div>
                                                    <h3 className="truncate text-sm font-semibold text-slate-950">
                                                        {product.name}
                                                    </h3>
                                                    <div className="mt-1 truncate text-xs text-slate-500">
                                                        {product.barcode || product.code || 'Código'}
                                                    </div>
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
                                                            ? 'Sin stock'
                                                            : lowStock
                                                              ? `Stock bajo (${product.stock})`
                                                              : `Stock ${product.stock}`}
                                                    </span>
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

                    <aside className="flex min-h-0 flex-col rounded-2xl border border-slate-200/80 bg-white/95 shadow-[0_8px_30px_rgba(15,23,42,0.08)]">
                        {!selectedProduct ? (
                            <div className="flex h-full items-center justify-center p-8 text-center text-lg font-semibold text-slate-500">
                                Selecciona un producto
                            </div>
                        ) : (
                            <form onSubmit={saveMovement} className="flex h-full flex-col">
                                <div className="border-b border-gray-200 p-5">
                                    <div className="flex items-start justify-between gap-3">
                                        <h2 className="text-lg font-semibold text-gray-900">
                                            {selectedProduct.name}
                                        </h2>
                                        <Link
                                            href={route('products.stock-history', selectedProduct.id)}
                                            className="shrink-0 text-sm font-semibold text-gray-900 underline"
                                        >
                                            Ver historial
                                        </Link>
                                    </div>
                                    <div className="mt-4 rounded-lg bg-gray-50 p-4">
                                        <div className="text-sm font-semibold text-gray-500">
                                            Stock actual
                                        </div>
                                        <div className="mt-1 text-6xl font-bold text-gray-900">
                                            {selectedProduct.stock}
                                        </div>
                                    </div>
                                </div>

                                <div className="flex-1 space-y-5 p-5">
                                    <div className="grid grid-cols-3 gap-2">
                                        {modes.map((item) => (
                                            <button
                                                key={item.value}
                                                type="button"
                                                onClick={() => {
                                                    setMode(item.value);
                                                    requestAnimationFrame(() =>
                                                        quantityInputRef.current?.focus(),
                                                    );
                                                }}
                                                className={`rounded-lg border px-3 py-3 text-sm font-semibold transition ${
                                                    mode === item.value
                                                        ? 'border-gray-900 bg-gray-900 text-white'
                                                        : 'border-gray-300 bg-white text-gray-700 hover:border-gray-500'
                                                }`}
                                            >
                                                {item.label}
                                            </button>
                                        ))}
                                    </div>

                                    <div>
                                        <label
                                            htmlFor="quantity"
                                            className="block text-sm font-semibold text-gray-700"
                                        >
                                            {mode === 'adjustment' ? 'Nuevo stock' : 'Cantidad'}
                                        </label>
                                        <input
                                            ref={quantityInputRef}
                                            id="quantity"
                                            type="number"
                                            min={mode === 'adjustment' ? 0 : 1}
                                            value={quantity}
                                            onChange={(event) => setQuantity(event.target.value)}
                                            className="mt-1 h-16 w-full rounded-2xl border-slate-200 text-center text-3xl font-bold text-slate-950 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                        />
                                    </div>

                                    <div>
                                        <label
                                            htmlFor="note"
                                            className="block text-sm font-semibold text-gray-700"
                                        >
                                            Nota
                                        </label>
                                        <textarea
                                            id="note"
                                            rows={3}
                                            value={note}
                                            onChange={(event) => setNote(event.target.value)}
                                            placeholder={
                                                mode === 'entry'
                                                    ? 'Nota opcional'
                                                    : 'Nota obligatoria'
                                            }
                                            className="mt-1 w-full rounded-2xl border-slate-200 text-sm text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                        />
                                    </div>
                                </div>

                                <div className="border-t border-gray-200 p-5">
                                    <button
                                        type="submit"
                                        disabled={saving}
                                        className="h-14 w-full rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-4 text-base font-semibold text-white shadow-lg shadow-indigo-200 transition-all duration-200 hover:-translate-y-0.5 hover:from-indigo-700 hover:to-violet-700 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {saving ? 'Guardando...' : 'Guardar movimiento'}
                                    </button>
                                </div>
                            </form>
                        )}
                    </aside>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
