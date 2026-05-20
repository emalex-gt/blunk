import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useMemo, useState } from 'react';
import type { ReactNode } from 'react';

type Branch = { id: number; name: string; code: string | null };
type Product = { id: number; name: string; code: string | null; barcode: string | null; stock: number };
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
    const [items, setItems] = useState<Line[]>([{ product_id: products[0]?.id ?? 0, quantity: '1' }]);
    const [processing, setProcessing] = useState(false);
    const productsById = useMemo(() => new Map(products.map((product) => [product.id, product])), [products]);

    function submit(event: FormEvent) {
        event.preventDefault();
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

    function updateLine(index: number, field: keyof Line, value: string | number) {
        setItems((current) => current.map((item, itemIndex) => (
            itemIndex === index ? { ...item, [field]: value } : item
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
                            <select className={inputClass} value={fromBranchId ?? ''} onChange={(event) => setFromBranchId(Number(event.target.value))}>
                                {branches.map((branch) => (
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
                        <button type="button" onClick={() => setItems([...items, { product_id: products[0]?.id ?? 0, quantity: '1' }])} className="rounded-xl border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-100">
                            Agregar producto
                        </button>
                    </div>

                    <div className="space-y-3">
                        {items.map((item, index) => {
                            const product = productsById.get(Number(item.product_id));

                            return (
                                <div key={index} className="grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3 md:grid-cols-[1fr_140px_80px]">
                                    <select className={inputClass} value={item.product_id} onChange={(event) => updateLine(index, 'product_id', Number(event.target.value))}>
                                        {products.map((productOption) => (
                                            <option key={productOption.id} value={productOption.id}>
                                                {productOption.name} | Stock: {productOption.stock}
                                            </option>
                                        ))}
                                    </select>
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
                                    {product && (
                                        <div className="md:col-span-3 text-xs text-slate-500">
                                            Disponible en sucursal activa: {product.stock}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
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
