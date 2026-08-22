import Toast from '@/Components/Toast';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useToast } from '@/hooks/useToast';
import { t } from '@/lib/i18n';
import { makeOperationKey } from '@/lib/idempotency';
import { formatCurrency } from '@/utils/currency';
import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';
import { KeyboardEvent, useEffect, useMemo, useRef, useState } from 'react';

type StockProduct = {
    id: number;
    name: string;
    code: string | null;
    barcode: string | null;
    category_name: string | null;
    brand_name: string | null;
    location: string | null;
    sale_price: string | number | null;
    physical_stock: number;
    reserved_stock: number;
    available_stock: number;
};

type Branch = {
    id: number;
    name: string;
    code: string | null;
};

type AdjustmentType = 'increase' | 'decrease';

type AdjustmentResponse = {
    message: string;
    previous_stock: number;
    adjustment: number;
    new_stock: number;
    product: StockProduct;
};

export default function StockIndex({
    active_branch = null,
    allow_negative_stock = false,
    can_adjust_stock = false,
}: {
    branches_enabled?: boolean;
    active_branch?: Branch | null;
    allow_negative_stock?: boolean;
    can_adjust_stock?: boolean;
}) {
    const business = usePage().props.business as { country?: string | null } | null;
    const country = business?.country ?? 'GT';
    const toast = useToast();
    const searchInputRef = useRef<HTMLInputElement>(null);
    const quantityInputRef = useRef<HTMLInputElement>(null);
    const [search, setSearch] = useState('');
    const [products, setProducts] = useState<StockProduct[]>([]);
    const [loading, setLoading] = useState(false);
    const [searchMessage, setSearchMessage] = useState('');
    const [selectedProduct, setSelectedProduct] = useState<StockProduct | null>(null);
    const [adjustmentType, setAdjustmentType] = useState<AdjustmentType>('increase');
    const [quantity, setQuantity] = useState('1');
    const [note, setNote] = useState('');
    const [modalError, setModalError] = useState('');
    const [saving, setSaving] = useState(false);
    const [idempotencyKey, setIdempotencyKey] = useState(() => makeOperationKey('stock'));
    const submitLockedRef = useRef(false);

    const numericQuantity = Number(quantity);
    const estimatedNewPhysicalStock = selectedProduct
        ? selectedProduct.physical_stock + (adjustmentType === 'decrease' ? -numericQuantity : numericQuantity)
        : 0;
    const estimatedNewAvailableStock = selectedProduct
        ? selectedProduct.available_stock + (adjustmentType === 'decrease' ? -numericQuantity : numericQuantity)
        : 0;
    const negativeWarning = Boolean(
        selectedProduct
        && adjustmentType === 'decrease'
        && allow_negative_stock
        && Number.isFinite(numericQuantity)
        && numericQuantity > 0
        && estimatedNewAvailableStock < 0,
    );

    const canSubmitAdjustment = useMemo(() => {
        if (!selectedProduct || !can_adjust_stock || saving) {
            return false;
        }

        if (!Number.isFinite(numericQuantity) || numericQuantity <= 0 || note.trim().length < 5) {
            return false;
        }

        return allow_negative_stock || adjustmentType === 'increase' || selectedProduct.available_stock >= numericQuantity;
    }, [adjustmentType, allow_negative_stock, can_adjust_stock, note, numericQuantity, saving, selectedProduct]);

    function openAdjustmentModal(product: StockProduct) {
        if (!can_adjust_stock) {
            return;
        }

        setSelectedProduct(product);
        setAdjustmentType('increase');
        setQuantity('1');
        setNote('');
        setModalError('');
        setIdempotencyKey(makeOperationKey('stock'));
        requestAnimationFrame(() => quantityInputRef.current?.focus());
    }

    function closeAdjustmentModal() {
        if (saving) {
            return;
        }

        setSelectedProduct(null);
        setModalError('');
    }

    function handleSearchKeyDown(event: KeyboardEvent<HTMLInputElement>) {
        if (event.key === 'Escape') {
            event.preventDefault();
            setSearch('');
            setProducts([]);
            setSearchMessage('');
            return;
        }

        if (event.key !== 'Enter') {
            return;
        }

        event.preventDefault();

        if (products.length === 1) {
            openAdjustmentModal(products[0]);
            return;
        }

        if (products.length > 1) {
            setSearchMessage('Hay varias coincidencias. Selecciona un producto de la lista.');
        }
    }

    function validateAdjustment(): boolean {
        setModalError('');

        if (!selectedProduct) {
            return false;
        }

        if (!Number.isFinite(numericQuantity) || numericQuantity <= 0) {
            setModalError('La cantidad debe ser mayor a 0.');
            return false;
        }

        if (note.trim().length < 5) {
            setModalError('La nota debe tener al menos 5 caracteres.');
            return false;
        }

        if (adjustmentType === 'decrease' && !allow_negative_stock && selectedProduct.available_stock < numericQuantity) {
            setModalError('No puedes reducir esa cantidad porque dejaría stock disponible negativo.');
            return false;
        }

        return true;
    }

    function submitAdjustment() {
        if (!selectedProduct || saving || submitLockedRef.current || !validateAdjustment()) {
            return;
        }

        submitLockedRef.current = true;
        setSaving(true);
        setModalError('');

        axios.post<AdjustmentResponse>(route('stock.adjustments.store'), {
            idempotency_key: idempotencyKey,
            product_id: selectedProduct.id,
            type: adjustmentType,
            quantity: numericQuantity,
            note: note.trim(),
        }, {
            headers: { Accept: 'application/json' },
        })
            .then((response) => {
                const updatedProduct = response.data.product;
                setProducts((current) => current.map((product) => (
                    product.id === updatedProduct.id ? updatedProduct : product
                )));
                setSelectedProduct(null);
                setIdempotencyKey(makeOperationKey('stock'));
                toast.success(response.data.message || 'Stock ajustado correctamente.');
                requestAnimationFrame(() => searchInputRef.current?.focus());
            })
            .catch((error) => {
                const errors = error.response?.data?.errors;
                const firstError = errors ? Object.values(errors)[0] : null;
                const message = Array.isArray(firstError)
                    ? firstError[0]
                    : (error.response?.data?.message || 'No se pudo ajustar el stock.');
                setModalError(String(message));
            })
            .finally(() => {
                submitLockedRef.current = false;
                setSaving(false);
            });
    }

    useEffect(() => {
        const term = search.trim();

        if (term.length < 2) {
            setProducts([]);
            setSearchMessage('');
            setLoading(false);
            return;
        }

        const timer = window.setTimeout(() => {
            setLoading(true);
            setSearchMessage('');
            axios.get<{ products: StockProduct[] }>(route('stock.products.search'), {
                params: { q: term, limit: 20 },
                headers: { Accept: 'application/json' },
            })
                .then((response) => setProducts(response.data.products))
                .catch(() => {
                    setProducts([]);
                    setSearchMessage('No se pudo buscar productos.');
                })
                .finally(() => setLoading(false));
        }, 300);

        return () => window.clearTimeout(timer);
    }, [search]);

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold text-gray-800">Gestión de stock</h2>}
        >
            <Head title="Gestión de stock" />
            <Toast toasts={toast.toasts} onClose={toast.removeToast} />

            <div className="min-h-[calc(100vh-8rem)] overflow-x-hidden bg-[#f4f6fb]">
                <div className="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
                    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h1 className="text-2xl font-semibold text-slate-950">Gestión de stock</h1>
                                <p className="mt-1 text-sm text-slate-500">
                                    Busca un producto para consultar existencias y registrar ajustes manuales.
                                </p>
                            </div>
                            {active_branch && (
                                <div className="rounded-xl border border-indigo-100 bg-indigo-50 px-3 py-2 text-sm text-indigo-800">
                                    Sucursal activa: <span className="font-semibold">{active_branch.name}</span>
                                </div>
                            )}
                        </div>

                        <input
                            ref={searchInputRef}
                            autoFocus
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            onKeyDown={handleSearchKeyDown}
                            placeholder="Buscar producto por nombre, código o código de barras"
                            className="mt-5 h-14 w-full rounded-2xl border border-slate-200 bg-white px-5 text-lg font-medium text-slate-900 shadow-sm outline-none transition focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                        />

                        <div className="mt-3 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                            <span>Escribe al menos 2 caracteres.</span>
                            {!can_adjust_stock && (
                                <span className="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700">
                                    Solo consulta. No tienes permiso para ajustar inventario.
                                </span>
                            )}
                        </div>
                    </section>

                    <section className="min-h-[280px]">
                        {loading && (
                            <div className="rounded-2xl border border-slate-200 bg-white p-6 text-center text-sm font-medium text-slate-500">
                                Buscando productos...
                            </div>
                        )}

                        {!loading && search.trim().length < 2 && (
                            <div className="rounded-2xl border border-dashed border-slate-300 bg-white/80 p-8 text-center text-sm text-slate-500">
                                Usa el buscador para cargar productos. No se precarga el catálogo completo.
                            </div>
                        )}

                        {!loading && search.trim().length >= 2 && products.length === 0 && (
                            <div className="rounded-2xl border border-dashed border-slate-300 bg-white/80 p-8 text-center text-sm text-slate-500">
                                Sin resultados.
                            </div>
                        )}

                        {searchMessage && (
                            <div className="mb-3 rounded-xl border border-amber-100 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-700">
                                {searchMessage}
                            </div>
                        )}

                        <div className="grid grid-cols-1 gap-4 min-[1180px]:grid-cols-2">
                            {products.map((product) => (
                                <article
                                    key={product.id}
                                    className="rounded-2xl border border-slate-200 bg-white p-4 shadow-[0_4px_18px_rgba(15,23,42,0.05)]"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <h2 className="line-clamp-2 text-base font-semibold leading-5 text-slate-950">
                                                {product.name}
                                            </h2>
                                            <div className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500">
                                                <span>Código: <span className="font-semibold text-slate-700">{product.code || '-'}</span></span>
                                                <span>Barras: <span className="font-semibold text-slate-700">{product.barcode || '-'}</span></span>
                                                {(product.category_name || product.brand_name) && (
                                                    <span>{[product.category_name, product.brand_name].filter(Boolean).join(' · ')}</span>
                                                )}
                                            </div>
                                        </div>
                                        {can_adjust_stock && (
                                            <button
                                                type="button"
                                                onClick={() => openAdjustmentModal(product)}
                                                className="shrink-0 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
                                            >
                                                Ajustar stock
                                            </button>
                                        )}
                                    </div>

                                    <div className="mt-4 grid gap-2 text-sm sm:grid-cols-3">
                                        <div className="rounded-xl bg-slate-50 px-3 py-2">
                                            <div className="text-[11px] font-semibold uppercase text-slate-400">Stock físico</div>
                                            <div className="text-lg font-bold text-slate-950">{product.physical_stock}</div>
                                        </div>
                                        <div className="rounded-xl bg-slate-50 px-3 py-2">
                                            <div className="text-[11px] font-semibold uppercase text-slate-400">Reservado</div>
                                            <div className="text-lg font-bold text-slate-950">{product.reserved_stock}</div>
                                        </div>
                                        <div className="rounded-xl bg-slate-50 px-3 py-2">
                                            <div className="text-[11px] font-semibold uppercase text-slate-400">Disponible</div>
                                            <div className={`text-lg font-bold ${product.available_stock < 0 ? 'text-red-600' : 'text-slate-950'}`}>
                                                {product.available_stock}
                                            </div>
                                        </div>
                                    </div>

                                    <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                                        <span>Ubicación: <span className="font-semibold text-slate-700">{product.location || '-'}</span></span>
                                        {product.sale_price !== null && (
                                            <span>Precio: <span className="font-semibold text-slate-700">{formatCurrency(product.sale_price, country)}</span></span>
                                        )}
                                    </div>
                                </article>
                            ))}
                        </div>
                    </section>
                </div>
            </div>

            {selectedProduct && (
                <div className="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
                    <section className="max-h-[calc(100vh-2rem)] w-full max-w-2xl overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <h2 className="text-xl font-semibold text-slate-950">Ajustar stock</h2>
                                <p className="mt-1 text-sm text-slate-500">{selectedProduct.name}</p>
                            </div>
                            <button
                                type="button"
                                onClick={closeAdjustmentModal}
                                className="rounded-lg px-2 py-1 text-xl leading-none text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                            >
                                ×
                            </button>
                        </div>

                        <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                                <span>Código: <span className="font-semibold text-slate-700">{selectedProduct.code || '-'}</span></span>
                                <span>Barras: <span className="font-semibold text-slate-700">{selectedProduct.barcode || '-'}</span></span>
                                {active_branch && <span>Sucursal: <span className="font-semibold text-slate-700">{active_branch.name}</span></span>}
                                <span>Ubicación: <span className="font-semibold text-slate-700">{selectedProduct.location || '-'}</span></span>
                            </div>
                            <div className="mt-3 grid gap-2 sm:grid-cols-3">
                                <div className="rounded-xl bg-white px-3 py-2">
                                    <div className="text-[11px] font-semibold uppercase text-slate-400">Stock físico</div>
                                    <div className="text-lg font-bold text-slate-950">{selectedProduct.physical_stock}</div>
                                </div>
                                <div className="rounded-xl bg-white px-3 py-2">
                                    <div className="text-[11px] font-semibold uppercase text-slate-400">Reservado</div>
                                    <div className="text-lg font-bold text-slate-950">{selectedProduct.reserved_stock}</div>
                                </div>
                                <div className="rounded-xl bg-white px-3 py-2">
                                    <div className="text-[11px] font-semibold uppercase text-slate-400">Disponible</div>
                                    <div className={`text-lg font-bold ${selectedProduct.available_stock < 0 ? 'text-red-600' : 'text-slate-950'}`}>
                                        {selectedProduct.available_stock}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <label className="text-sm font-medium text-slate-700">Acción</label>
                                <select
                                    value={adjustmentType}
                                    onChange={(event) => setAdjustmentType(event.target.value as AdjustmentType)}
                                    className="mt-1 h-11 w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                >
                                    <option value="increase">Agregar stock</option>
                                    <option value="decrease">Reducir stock</option>
                                </select>
                            </div>
                            <div>
                                <label className="text-sm font-medium text-slate-700">Cantidad</label>
                                <input
                                    ref={quantityInputRef}
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    value={quantity}
                                    onChange={(event) => setQuantity(event.target.value)}
                                    className="mt-1 h-11 w-full rounded-xl border-slate-200 bg-white text-right text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                />
                            </div>
                        </div>

                        <div className="mt-4">
                            <label className="text-sm font-medium text-slate-700">Nota / motivo</label>
                            <textarea
                                value={note}
                                onChange={(event) => setNote(event.target.value)}
                                placeholder="Ejemplo: ajuste por conteo físico, merma, daño, corrección de inventario..."
                                className="mt-1 min-h-28 w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                            />
                        </div>

                        <div className="mt-4 grid gap-2 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm sm:grid-cols-3">
                            <div>
                                <div className="text-[11px] font-semibold uppercase text-slate-400">Stock actual</div>
                                <div className="font-bold text-slate-950">{selectedProduct.physical_stock}</div>
                            </div>
                            <div>
                                <div className="text-[11px] font-semibold uppercase text-slate-400">Ajuste</div>
                                <div className="font-bold text-slate-950">
                                    {adjustmentType === 'decrease' ? '-' : '+'}{Number.isFinite(numericQuantity) ? numericQuantity : 0}
                                </div>
                            </div>
                            <div>
                                <div className="text-[11px] font-semibold uppercase text-slate-400">Nuevo stock</div>
                                <div className="font-bold text-slate-950">
                                    {Number.isFinite(estimatedNewPhysicalStock) ? estimatedNewPhysicalStock : selectedProduct.physical_stock}
                                </div>
                            </div>
                        </div>

                        {negativeWarning && (
                            <div className="mt-3 rounded-xl border border-amber-100 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-700">
                                Este ajuste dejará el stock disponible en negativo.
                            </div>
                        )}

                        {modalError && (
                            <div className="mt-3 rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
                                {modalError}
                            </div>
                        )}

                        <div className="mt-5 flex flex-wrap justify-end gap-2">
                            <button
                                type="button"
                                disabled={saving}
                                onClick={closeAdjustmentModal}
                                className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                            >
                                Cancelar
                            </button>
                            <button
                                type="button"
                                disabled={!canSubmitAdjustment}
                                onClick={submitAdjustment}
                                className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300"
                            >
                                {saving ? 'Guardando...' : 'Confirmar ajuste'}
                            </button>
                        </div>
                    </section>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
