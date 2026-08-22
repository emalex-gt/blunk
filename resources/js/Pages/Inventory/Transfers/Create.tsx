import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { makeOperationKey } from '@/lib/idempotency';
import { discardOperationDraft, listOperationDrafts, OperationDraftRecord, saveOperationDraft } from '@/lib/operationDrafts';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { FormEvent, KeyboardEvent, useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';

type Branch = { id: number; name: string; code: string | null };
type Product = { id: number; name: string; code: string | null; barcode: string | null; category_name?: string | null; brand_name?: string | null; stock: number; reserved_stock?: number; available_stock?: number; location?: string | null };
type Line = { product_id: number; quantity: string };
type TransferDraft = {
    from_branch_id: number | null;
    to_branch_id: number | null;
    notes: string;
    items: Line[];
};

export default function Create({
    branches,
    activeBranch,
    products,
    allow_negative_stock = false,
}: {
    branches: Branch[];
    activeBranch: Branch;
    products: Product[];
    allow_negative_stock?: boolean;
}) {
    const [fromBranchId, setFromBranchId] = useState(activeBranch?.id ?? branches[0]?.id ?? null);
    const [toBranchId, setToBranchId] = useState(branches.find((branch) => branch.id !== activeBranch?.id)?.id ?? null);
    const [notes, setNotes] = useState('');
    const [search, setSearch] = useState('');
    const [items, setItems] = useState<Line[]>([]);
    const [message, setMessage] = useState('');
    const [processing, setProcessing] = useState(false);
    const [activeDraftId, setActiveDraftId] = useState<number | null>(null);
    const [draftsOpen, setDraftsOpen] = useState(false);
    const [drafts, setDrafts] = useState<OperationDraftRecord<TransferDraft>[]>([]);
    const [draftLoading, setDraftLoading] = useState(false);
    const [draftSaving, setDraftSaving] = useState(false);
    const [loadedProducts, setLoadedProducts] = useState<Product[]>(products);
    const [productResults, setProductResults] = useState<Product[]>(products);
    const [productSearchLoading, setProductSearchLoading] = useState(false);
    const [confirmTransferOpen, setConfirmTransferOpen] = useState(false);
    const [idempotencyKey, setIdempotencyKey] = useState(() => makeOperationKey('transfer'));
    const submitLockedRef = useRef(false);
    const searchInputRef = useRef<HTMLInputElement>(null);
    const previousFromBranchId = useRef<number | null>(fromBranchId);
    const productsById = useMemo(() => new Map(loadedProducts.map((product) => [product.id, product])), [loadedProducts]);
    const filteredProducts = productResults;

    function productAvailable(product: Product | undefined): number {
        if (!product) {
            return 0;
        }

        return Number(product.available_stock ?? product.stock ?? 0);
    }

    function buildTransferDraft(): TransferDraft {
        return {
            from_branch_id: fromBranchId,
            to_branch_id: toBranchId,
            notes,
            items,
        };
    }

    function isMeaningfulTransferDraft(draft: TransferDraft): boolean {
        return draft.items.length > 0 || draft.notes.trim() !== '';
    }

    function clearTransferState() {
        setNotes('');
        setSearch('');
        setItems([]);
        setFromBranchId(activeBranch?.id ?? branches[0]?.id ?? null);
        setToBranchId(branches.find((branch) => branch.id !== activeBranch?.id)?.id ?? null);
        setActiveDraftId(null);
        setIdempotencyKey(makeOperationKey('transfer'));
        setMessage('');
        requestAnimationFrame(() => searchInputRef.current?.focus());
    }

    async function hydrateTransferProducts(productIds: number[], sourceBranchId = fromBranchId): Promise<Product[]> {
        const ids = [...new Set(productIds.filter(Boolean))];

        if (ids.length === 0 || !sourceBranchId) {
            return [];
        }

        const response = await axios.get<{ products: Product[] }>(route('inventory.transfers.products.search'), {
            params: { ids: ids.join(','), source_branch_id: sourceBranchId, limit: 30 },
            headers: { Accept: 'application/json' },
        });

        setLoadedProducts((current) => {
            const map = new Map(current.map((product) => [product.id, product]));
            response.data.products.forEach((product) => map.set(product.id, product));

            return Array.from(map.values());
        });

        return response.data.products;
    }

    async function restoreTransferDraft(draft: OperationDraftRecord<TransferDraft>) {
        if (draft.payload_version !== 1) {
            setMessage('Este borrador fue creado con una versión anterior y no se puede recuperar automáticamente.');
            return;
        }

        if (isMeaningfulTransferDraft(buildTransferDraft()) && !window.confirm('Hay datos sin guardar en la pantalla actual. Si recuperas este borrador, se reemplazarán. ¿Deseas continuar?')) {
            return;
        }

        const payload = draft.payload;
        const missingIds = (payload.items ?? [])
            .map((item) => Number(item.product_id))
            .filter((productId) => !productsById.has(productId));
        const hydratedProducts = await hydrateTransferProducts(missingIds, payload.from_branch_id ?? fromBranchId).catch(() => []);
        const productMap = new Map(productsById);
        hydratedProducts.forEach((product) => productMap.set(product.id, product));

        setFromBranchId(payload.from_branch_id ?? activeBranch?.id ?? null);
        setToBranchId(payload.to_branch_id ?? null);
        setNotes(payload.notes ?? '');
        setItems((payload.items ?? []).filter((item) => productMap.has(Number(item.product_id))));
        setActiveDraftId(draft.id);
        setDraftsOpen(false);
        setMessage((payload.items ?? []).some((item) => !productMap.has(Number(item.product_id)))
            ? 'Se descartaron productos inválidos del borrador.'
            : 'Borrador recuperado.');
        requestAnimationFrame(() => searchInputRef.current?.focus());
    }

    async function saveTransferDraft() {
        const payload = buildTransferDraft();

        if (!isMeaningfulTransferDraft(payload)) {
            setMessage('No hay datos para guardar como borrador.');
            return;
        }

        setDraftSaving(true);
        setMessage('');

        try {
            await saveOperationDraft({
                type: 'transfer',
                title: `Traslado pausado ${new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`,
                branch_id: fromBranchId,
                source_branch_id: fromBranchId,
                destination_branch_id: toBranchId,
                payload,
                payload_version: 1,
            });
            clearTransferState();
            setMessage('Borrador guardado.');
        } catch (error) {
            setMessage(error instanceof Error ? error.message : 'No se pudo guardar el borrador.');
        } finally {
            setDraftSaving(false);
        }
    }

    async function openTransferDrafts() {
        setDraftLoading(true);
        setDraftsOpen(true);
        setMessage('');

        try {
            const payload = await listOperationDrafts<TransferDraft>('transfer');
            setDrafts(payload.drafts);
        } catch (error) {
            setMessage(error instanceof Error ? error.message : 'No se pudieron cargar los borradores.');
        } finally {
            setDraftLoading(false);
        }
    }

    async function discardTransferDraft(draft: OperationDraftRecord<TransferDraft>) {
        if (!window.confirm('¿Descartar este borrador?')) {
            return;
        }

        try {
            await discardOperationDraft(draft.id);
            setDrafts((current) => current.filter((item) => item.id !== draft.id));
            if (activeDraftId === draft.id) {
                setActiveDraftId(null);
            }
        } catch (error) {
            setMessage(error instanceof Error ? error.message : 'No se pudo descartar el borrador.');
        }
    }

    function validateTransferBeforeSubmit(): boolean {
        setMessage('');

        const invalid = items.some((item) => {
            const product = productsById.get(Number(item.product_id));
            const quantity = Number(item.quantity);

            return !Number.isInteger(quantity) || quantity < 1 || (!allow_negative_stock && quantity > productAvailable(product));
        });

        if (invalid) {
            setMessage('No hay suficiente stock disponible para trasladar.');
            return false;
        }

        return true;
    }

    function requestTransferConfirmation() {
        if (!validateTransferBeforeSubmit()) {
            return;
        }

        setConfirmTransferOpen(true);
    }

    function submit() {
        if (submitLockedRef.current || !validateTransferBeforeSubmit()) {
            return;
        }

        submitLockedRef.current = true;
        setProcessing(true);
        setConfirmTransferOpen(false);

        router.post(route('inventory.transfers.store'), {
            idempotency_key: idempotencyKey,
            from_branch_id: fromBranchId,
            to_branch_id: toBranchId,
            notes,
            draft_id: activeDraftId,
            items: items.map((item) => ({
                product_id: item.product_id,
                quantity: Number(item.quantity),
            })),
        }, {
            preserveScroll: true,
            onSuccess: () => setIdempotencyKey(makeOperationKey('transfer')),
            onFinish: () => {
                submitLockedRef.current = false;
                setProcessing(false);
            },
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

                    const nextQuantity = allow_negative_stock
                        ? Number(item.quantity || 0) + 1
                        : Math.min(productAvailable(product), Number(item.quantity || 0) + 1);

                    return { ...item, quantity: String(Math.max(1, nextQuantity)) };
                });
            }

            if (!allow_negative_stock && productAvailable(product) < 1) {
                setMessage('No hay suficiente stock disponible para trasladar.');

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

    useEffect(() => {
        const term = search.trim();

        if (term.length < 2 || !fromBranchId) {
            setProductResults([]);
            setProductSearchLoading(false);
            return;
        }

        const timer = window.setTimeout(() => {
            setProductSearchLoading(true);
            axios.get<{ products: Product[] }>(route('inventory.transfers.products.search'), {
                params: { q: term, source_branch_id: fromBranchId, limit: 30 },
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
    }, [fromBranchId, search]);

    useEffect(() => {
        if (previousFromBranchId.current === fromBranchId) {
            return;
        }

        previousFromBranchId.current = fromBranchId;
        setItems([]);
        setLoadedProducts([]);
        setProductResults([]);
        setSearch('');
        setMessage('Se limpiaron las líneas porque cambió la sucursal origen.');
    }, [fromBranchId]);

    return (
        <AuthenticatedLayout>
            <Head title="Nuevo traslado" />
            <form onSubmit={(event) => event.preventDefault()} className="mx-auto max-w-5xl space-y-5 px-4 py-6 sm:px-6">
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
                        {productSearchLoading && (
                            <div className="absolute z-20 mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-500 shadow-lg">
                                Buscando productos...
                            </div>
                        )}
                        {!productSearchLoading && search.trim().length >= 2 && filteredProducts.length === 0 && (
                            <div className="absolute z-20 mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-500 shadow-lg">
                                Sin resultados.
                            </div>
                        )}
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
                    <button type="button" onClick={openTransferDrafts} className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Recuperar borrador
                    </button>
                    <button type="button" disabled={draftSaving} onClick={saveTransferDraft} className="rounded-xl border border-indigo-200 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 disabled:opacity-50">
                        Guardar borrador
                    </button>
                    <Link href={route('inventory.transfers.index')} className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Cancelar
                    </Link>
                    <button type="button" onClick={requestTransferConfirmation} disabled={processing} className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                        Registrar traslado
                    </button>
                </div>
            </form>

            {confirmTransferOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
                    <section className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
                        <h2 className="text-lg font-semibold text-slate-950">
                            ¿Has revisado la información de este traslado?
                        </h2>
                        <p className="mt-2 text-sm text-slate-600">
                            Confirma que sucursal origen, sucursal destino, productos y cantidades fueron comparados antes de guardar.
                        </p>
                        <div className="mt-5 flex justify-end gap-2">
                            <button
                                type="button"
                                disabled={processing}
                                onClick={() => setConfirmTransferOpen(false)}
                                className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                Cancelar
                            </button>
                            <button
                                type="button"
                                disabled={processing}
                                onClick={submit}
                                className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                            >
                                Sí, guardar traslado
                            </button>
                        </div>
                    </section>
                </div>
            )}

            {draftsOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
                    <div className="w-full max-w-3xl rounded-2xl bg-white p-5 shadow-xl">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-lg font-semibold text-slate-900">Borradores de traslado</h2>
                            <button type="button" onClick={() => setDraftsOpen(false)} className="rounded-lg px-3 py-1 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cerrar</button>
                        </div>
                        <div className="mt-4 space-y-3">
                            {draftLoading && <div className="rounded-xl border border-slate-200 p-4 text-sm text-slate-500">Cargando borradores...</div>}
                            {!draftLoading && drafts.length === 0 && <div className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">No hay borradores guardados.</div>}
                            {!draftLoading && drafts.map((draft) => (
                                <div key={draft.id} className="grid gap-3 rounded-xl border border-slate-200 p-4 md:grid-cols-[1fr_auto]">
                                    <div>
                                        <div className="text-sm font-semibold text-slate-900">{draft.title ?? 'Borrador de traslado'}</div>
                                        <div className="mt-1 text-xs text-slate-500">
                                            {draft.source_branch?.name ?? 'Origen'} → {draft.destination_branch?.name ?? 'Destino'} · {draft.item_count} productos · Usuario: {draft.user?.name ?? '-'}
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <button type="button" onClick={() => void restoreTransferDraft(draft)} className="rounded-xl bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Continuar borrador</button>
                                        <button type="button" onClick={() => discardTransferDraft(draft)} className="rounded-xl px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50">Descartar</button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            )}
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
