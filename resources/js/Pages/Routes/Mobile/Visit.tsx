import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useMemo } from 'react';

type Product = {
    id: number;
    name: string;
    code: string | null;
    barcode: string | null;
    sale_price: string | number;
    image_url: string | null;
    stock: number;
    reserved_stock: number;
    available_stock: number;
};
type Item = { product_id: number; name: string; quantity: number; discount: number };
type ExistingItem = { product_id: number; quantity: string; discount: string; product?: { name: string } };

export default function Visit({
    visit,
    preSale,
    products,
    filters,
    allowNegativeStock,
}: {
    visit: { id: number; customer: { name: string; doc_number: string | null; address: string | null; phone: string | null }; work_day?: { status: string }; zone?: { name: string } };
    preSale: { id: number; status: string; notes: string | null; items: ExistingItem[] } | null;
    products: Product[];
    filters: { search?: string };
    allowNegativeStock: boolean;
}) {
    const initialItems = (preSale?.items ?? []).map((item) => ({
        product_id: item.product_id,
        name: item.product?.name ?? 'Producto',
        quantity: Number(item.quantity),
        discount: Number(item.discount ?? 0),
    }));
    const form = useForm<{ notes: string; items: Item[] }>({ notes: preSale?.notes ?? '', items: initialItems });
    const total = useMemo(() => form.data.items.reduce((sum, item) => {
        const product = products.find((product) => product.id === item.product_id);
        return sum + (Number(product?.sale_price ?? 0) * item.quantity) - item.discount;
    }, 0), [form.data.items, products]);

    const search = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const value = (event.currentTarget.elements.namedItem('search') as HTMLInputElement).value;
        router.get(route('routes.mobile.visits.show', visit.id), { search: value }, { preserveState: true });
    };

    const addProduct = (product: Product) => {
        if (!allowNegativeStock && product.available_stock <= 0) {
            return;
        }

        const existing = form.data.items.find((item) => item.product_id === product.id);
        if (existing) {
            form.setData('items', form.data.items.map((item) => item.product_id === product.id ? { ...item, quantity: item.quantity + 1 } : item));
            return;
        }

        form.setData('items', [...form.data.items, { product_id: product.id, name: product.name, quantity: 1, discount: 0 }]);
    };

    const submit = () => {
        form.post(route('routes.mobile.visits.pre-sale.store', visit.id), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout>
            <Head title={`Visita ${visit.customer.name}`} />
            <div className="mx-auto max-w-xl space-y-4 px-4 pb-32 pt-5">
                <div>
                    <Link href={window.history.length > 1 ? '#' : route('routes.mobile.zones')} onClick={(event) => { event.preventDefault(); window.history.back(); }} className="text-sm font-semibold text-indigo-700">
                        Volver
                    </Link>
                    <h1 className="mt-2 text-2xl font-semibold text-slate-950">{visit.customer.name}</h1>
                    <p className="text-sm text-slate-500">{visit.customer.doc_number ?? '-'} · {visit.zone?.name}</p>
                </div>

                <form onSubmit={search} className="flex gap-2 rounded-xl bg-white p-3 shadow-sm ring-1 ring-slate-200">
                    <input name="search" defaultValue={filters.search ?? ''} placeholder="Buscar producto, código o barra" className="min-w-0 flex-1 rounded-lg border-slate-200 text-sm" />
                    <button className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Buscar</button>
                </form>

                <div className="space-y-2">
                    {products.map((product) => {
                        const disabled = !allowNegativeStock && product.available_stock <= 0;
                        return (
                            <button
                                type="button"
                                key={product.id}
                                disabled={disabled}
                                onClick={() => addProduct(product)}
                                className="w-full rounded-xl bg-white p-4 text-left shadow-sm ring-1 ring-slate-200 disabled:opacity-50"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <h2 className="font-semibold text-slate-950">{product.name}</h2>
                                        <p className="text-xs text-slate-500">{product.code ?? product.barcode ?? '-'}</p>
                                        <p className="mt-1 text-xs text-slate-500">Existencia: {product.stock} · Reservado: {product.reserved_stock} · Disponible: {product.available_stock}</p>
                                    </div>
                                    <span className="text-sm font-semibold text-indigo-700">Q {Number(product.sale_price).toFixed(2)}</span>
                                </div>
                                {allowNegativeStock && product.available_stock <= 0 && (
                                    <p className="mt-2 text-xs font-semibold text-amber-700">Stock negativo permitido</p>
                                )}
                            </button>
                        );
                    })}
                </div>

                <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <h2 className="font-semibold text-slate-950">Preventa</h2>
                    {form.data.items.length === 0 && <p className="mt-2 text-sm text-slate-500">Agrega productos para guardar la preventa.</p>}
                    <div className="mt-3 space-y-3">
                        {form.data.items.map((item, index) => (
                            <div key={item.product_id} className="rounded-lg bg-slate-50 p-3">
                                <div className="flex items-center justify-between gap-3">
                                    <p className="font-medium text-slate-900">{item.name}</p>
                                    <button onClick={() => form.setData('items', form.data.items.filter((_, i) => i !== index))} className="text-sm font-semibold text-red-600">Quitar</button>
                                </div>
                                <div className="mt-2 grid grid-cols-2 gap-2">
                                    <input type="number" min="0.0001" step="0.0001" value={item.quantity} onChange={(event) => form.setData('items', form.data.items.map((row, i) => i === index ? { ...row, quantity: Number(event.target.value) } : row))} className="rounded-lg border-slate-200 text-sm" />
                                    <input type="number" min="0" step="0.01" value={item.discount} onChange={(event) => form.setData('items', form.data.items.map((row, i) => i === index ? { ...row, discount: Number(event.target.value) } : row))} className="rounded-lg border-slate-200 text-sm" placeholder="Descuento" />
                                </div>
                            </div>
                        ))}
                    </div>
                    {form.errors.items && <p className="mt-3 text-sm font-medium text-red-600">{form.errors.items}</p>}
                    {(form.errors as Record<string, string>).pre_sale && <p className="mt-3 text-sm font-medium text-red-600">{(form.errors as Record<string, string>).pre_sale}</p>}
                </div>

                <div className="fixed inset-x-0 bottom-0 border-t border-slate-200 bg-white p-4">
                    <div className="mx-auto max-w-xl">
                        <div className="mb-3 flex items-center justify-between">
                            <span className="text-sm font-medium text-slate-500">Total preventa</span>
                            <span className="text-xl font-bold text-slate-950">Q {total.toFixed(2)}</span>
                        </div>
                        <button
                            disabled={form.processing || form.data.items.length === 0}
                            onClick={submit}
                            className="w-full rounded-xl bg-indigo-600 px-4 py-3 text-base font-semibold text-white disabled:opacity-50"
                        >
                            Guardar preventa
                        </button>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
