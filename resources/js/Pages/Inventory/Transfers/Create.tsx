import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, KeyboardEvent, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';

type Branch = { id: number; name: string; code: string | null };
type Product = { id: number; name: string; code: string | null; barcode: string | null; stock: number; reserved_stock?: number; available_stock?: number };
type Line = { product_id: number; quantity: string };

export default function Create({
    branches,
    activeBranch,
    products,
}: {
    branches: Branch[];
    activeBranch: Branch;
    products: Product[];
}) {
    const [fromBranchId, setFromBranchId] = useState(activeBranch?.id ?? branches[0]?.id ?? null);
    const [toBranchId, setToBranchId] = useState(branches.find((branch) => branch.id !== activeBranch?.id)?.id ?? null);
    const [notes, setNotes] = useState('');
    const [search, setSearch] = useState('');
    const [items, setItems] = useState<Line[]>([]);
    const [message, setMessage] = useState('');
    const [processing, setProcessing] = useState(false);
    const searchInputRef = useRef<HTMLInputElement>(null);
    const productsById = useMemo(() => new Map(products.map((product) => [product.id, product])), [products]);
    const filteredProducts = useMemo(() => {
        const term = search.trim().toLowerCase();

        if (term.length < 3) {
            return [];
        }

        return products
            .filter((product) =>
                [product.name, product.code, product.barcode]
                    .filter(Boolean)
                    .some((value) => value!.toLowerCase().includes(term)),
            )
            .slice(0, 8);
    }, [products, search]);

    function productAvailable(product: Product | undefined): number {
        if (!product) {
            return 0;
        }

        return Number(product.available_stock ?? product.stock ?? 0);
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        setMessage('');

        const invalid = items.some((item) => {
            const product = productsById.get(Number(item.product_id));
            const quantity = Number(item.quantity);

            return !Number.isInteger(quantity) || quantity < 1 || quantity > productAvailable(product);
        });

        if (invalid) {
            setMessage('No hay suficiente disponible para trasladar.');
            return;
        }

        setProcessing(true);

        router.post(route('inventory.transfers.store'), {
            from_branch_id: fromBranchId,
            to_branch_id: toBranchId,
            notes,
            items: items.map((item) => ({
                product_id: item.product_id,
                quantity: Number(item.quantity),
            })),
        }, {
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    }

    function addProduct(product: Product) {
        setMessage('');
        setItems((current) => {
            const existing = current.find((item) => item.product_id === product.id);

            if (existing) {
                return current.map((item) => {
                    if (item.product_id !== product.id) {
                        return item;
                    }

                    const nextQuantity = Math.min(productAvailable(product), Number(item.quantity || 0) + 1);

                    return { ...item, quantity: String(Math.max(1, nextQuantity)) };
                });
            }

            if (productAvailable(product) < 1) {
                setMessage('No hay suficiente disponible para trasladar.');

                return current;
            }

            return [...current, { product_id: product.id, quantity: '1' }];
        });
        setSearch('');
        requestAnimationFrame(() => searchInputRef.current?.focus());
    }

    function handleSearchKeyDown(event: KeyboardEvent<HTMLInputElement>) {
        if (event.key !== 'Enter') {
            return;
        }

        event.preventDefault();
        const term = search.trim().toLowerCase();
        const exact = products.find((product) =>
            [product.code, product.barcode, product.name]
                .filter(Boolean)
                .some((value) => value!.toLowerCase() === term),
        );
        const product = exact ?? filteredProducts[0];

        if (product) {
            addProduct(product);
        }
    }

    function updateLine(index: number, field: keyof Line, value: string | number) {
        if (field === 'quantity' && /[.,]/.test(String(value))) {
            setMessage('La cantidad debe ser un número entero.');

            return;
        }

        setItems((current) => current.map((item, itemIndex) => (
            itemIndex === index ? { ...item, [field]: String(value).replace(/\D/g, '') } : item
        )));
    }

    return (
        <AuthenticatedLayout>
            <Head title="Nuevo traslado" />
            <form onSubmit={submit} className="mx-auto max-w-5xl space-y-5 px-4 py-6 sm:px-6">
                <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h1 className="text-2xl font-semibold text-slate-950">Nuevo traslado</h1>
                    <div className="mt-5 grid grid-cols-1 gap-4 md:grid-cols-3">
                        <Field label="Sucursal origen">
                            <select className={inputClass} value={fromBranchId ?? ''} disabled onChange={(event) => setFromBranchId(Number(event.target.value))}>
                                {branches.filter((branch) => branch.id === activeBranch?.id).map((branch) => (
                                    <option key={branch.id} value={branch.id}>{branch.name}</option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Sucursal destino">
                            <select className={inputClass} value={toBranchId ?? ''} onChange={(event) => setToBranchId(Number(event.target.value))}>
                                <option value="" disabled>Seleccionar destino</option>
                                {branches.map((branch) => (
                                    <option key={branch.id} value={branch.id}>{branch.name}</option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Notas">
                            <input className={inputClass} value={notes} onChange={(event) => setNotes(event.target.value)} />
                        </Field>
                    </div>
                </div>

                <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="mb-4 flex items-center justify-between">
                        <h2 className="text-lg font-semibold text-slate-900">Productos</h2>
                    </div>
                    {message && (
                        <div className="mb-3 rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
                            {message}
                        </div>
                    )}
                    <div className="relative mb-4">
                        <input
                            ref={searchInputRef}
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            onKeyDown={handleSearchKeyDown}
                            placeholder="Buscar por producto, SKU o código de barras"
                            className={inputClass}
                        />
                        {filteredProducts.length > 0 && (
                            <div className="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg">
                                {filteredProducts.map((product) => (
                                    <button
                                        key={product.id}
                                        type="button"
                                        onClick={() => addProduct(product)}
                                        className="block w-full px-3 py-2 text-left text-sm hover:bg-indigo-50"
                                    >
                                        <span className="block font-semibold text-slate-900">{product.name}</span>
                                        <span className="block text-xs text-slate-500">
                                            {product.barcode || product.code || 'Sin código'} · Disponible: {productAvailable(product)}
                                        </span>
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>

                    <div className="space-y-3">
                        {items.map((item, index) => {
                            const product = productsById.get(Number(item.product_id));
                            const available = productAvailable(product);

                            return (
                                <div key={item.product_id} className="grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3 md:grid-cols-[1fr_120px_80px]">
                                    <div>
                                        <div className="text-sm font-semibold text-slate-900">{product?.name ?? 'Producto'}</div>
                                        <div className="text-xs text-slate-500">
                                            {product?.barcode || product?.code || 'Sin código'} · Disponible: {available}
                                        </div>
                                    </div>
                                    <input
                                        className={inputClass}
                                        value={item.quantity}
                                        min="1"
                                        step="1"
                                        inputMode="numeric"
                                        pattern="[0-9]*"
                                        onChange={(event) => updateLine(index, 'quantity', event.target.value)}
                                    />
                                    <button type="button" onClick={() => setItems(items.filter((_, itemIndex) => itemIndex !== index))} className="rounded-xl px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50">
                                        Quitar
                                    </button>
                                </div>
                            );
                        })}
                        {items.length === 0 && (
                            <div className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                                Busca productos para agregarlos al traslado.
                            </div>
                        )}
                    </div>
                </div>

                <div className="flex justify-end gap-3">
                    <Link href={route('inventory.transfers.index')} className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Cancelar
                    </Link>
                    <button disabled={processing} className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                        Registrar traslado
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <label className="block text-sm font-medium text-slate-700">
            {label}
            <div className="mt-1">{children}</div>
        </label>
    );
}

const inputClass = 'h-11 w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100';
