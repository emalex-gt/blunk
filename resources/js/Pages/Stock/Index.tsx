import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { t } from '@/lib/i18n';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useEffect, useMemo, useRef, useState } from 'react';

type Product = {
    id: number;
    name: string;
    code: string | null;
    stock: number;
    reserved_stock?: number;
    available_stock?: number;
    location: string | null;
};

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    from: number | null;
    to: number | null;
};

type StockFilters = {
    search: string;
    per_page: number;
};

export default function StockIndex({ products, filters = { search: '', per_page: 25 } }: { products: Paginated<Product>; filters?: StockFilters }) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [perPage, setPerPage] = useState(String(filters.per_page ?? 25));
    const didMountFilters = useRef(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        product_id: '',
        type: 'add',
        quantity: '1',
        note: '',
    });

    const productRows = products.data ?? [];

    function submit(event: FormEvent) {
        event.preventDefault();
        post(route('stock.store'), { onSuccess: () => reset('quantity', 'note') });
    }

    useEffect(() => {
        if (!didMountFilters.current) {
            didMountFilters.current = true;
            return;
        }

        const timer = window.setTimeout(() => {
            router.get(route('stock.index'), {
                search,
                per_page: perPage,
            }, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, 350);

        return () => window.clearTimeout(timer);
    }, [perPage, search]);

    const selectedProduct = useMemo(
        () => productRows.find((product) => String(product.id) === data.product_id),
        [data.product_id, productRows],
    );

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold text-gray-800">{t('stock.stock')}</h2>}
        >
            <Head title={t('stock.stock')} />

            <div className="mx-auto max-w-5xl space-y-4 px-4 py-6 sm:px-6 lg:px-8">
                <section className="grid gap-3 rounded-lg bg-white p-4 shadow sm:grid-cols-[minmax(0,1fr)_140px]">
                    <div>
                        <InputLabel htmlFor="stock_search" value="Buscar producto" />
                        <TextInput
                            id="stock_search"
                            className="mt-1 block w-full"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Nombre, código o código de barras"
                        />
                    </div>
                    <div>
                        <InputLabel htmlFor="stock_per_page" value="Por página" />
                        <select
                            id="stock_per_page"
                            value={perPage}
                            onChange={(event) => setPerPage(event.target.value)}
                            className="mt-1 block h-10 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        >
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                </section>

                <form onSubmit={submit} className="space-y-4 bg-white p-5 shadow sm:rounded-lg">
                    <div>
                        <InputLabel htmlFor="product_id" value={t('products.product')} />
                        <select id="product_id" value={data.product_id} onChange={(e) => setData('product_id', e.target.value)} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">{t('stock.select_product')}</option>
                            {productRows.map((product) => (
                                <option key={product.id} value={product.id}>
                                    {product.name} {product.code ? `(${product.code})` : ''}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.product_id} className="mt-2" />
                    </div>

                    {selectedProduct && (
                        <div className="rounded-md bg-gray-50 p-3 text-sm text-gray-700">
                            {t('stock.current_stock')}: <span className="font-semibold">{selectedProduct.stock}</span>
                            <span className="ml-3">Reservado: <span className="font-semibold">{selectedProduct.reserved_stock ?? 0}</span></span>
                            <span className="ml-3">Disponible: <span className="font-semibold">{selectedProduct.available_stock ?? selectedProduct.stock}</span></span>
                            {selectedProduct.location && <span> | {t('common.location')}: {selectedProduct.location}</span>}
                        </div>
                    )}

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="type" value={t('stock.type')} />
                            <select id="type" value={data.type} onChange={(e) => setData('type', e.target.value)} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="add">{t('stock.add_stock')}</option>
                                <option value="remove">{t('stock.remove_stock')}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel htmlFor="quantity" value={t('common.quantity')} />
                            <TextInput id="quantity" type="number" min="1" className="mt-1 block w-full" value={data.quantity} onChange={(e) => setData('quantity', e.target.value)} />
                            <InputError message={errors.quantity} className="mt-2" />
                        </div>
                    </div>

                    <div>
                        <InputLabel htmlFor="note" value={t('common.note')} />
                        <textarea id="note" value={data.note} onChange={(e) => setData('note', e.target.value)} className="mt-1 block min-h-24 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>

                    <PrimaryButton disabled={processing}>{t('stock.save_movement')}</PrimaryButton>
                </form>

                <section className="rounded-lg bg-white p-4 text-sm shadow">
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2 text-slate-600">
                        <span>
                            Mostrando {products.from ?? 0}-{products.to ?? 0} de {products.total}
                        </span>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200">
                            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                                <tr>
                                    <th className="px-3 py-2">Producto</th>
                                    <th className="px-3 py-2">Código</th>
                                    <th className="px-3 py-2 text-right">Existencia</th>
                                    <th className="px-3 py-2 text-right">Reservado</th>
                                    <th className="px-3 py-2 text-right">Disponible</th>
                                    <th className="px-3 py-2">Ubicación</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {productRows.map((product) => (
                                    <tr key={product.id}>
                                        <td className="px-3 py-2 font-semibold text-slate-900">{product.name}</td>
                                        <td className="px-3 py-2 text-slate-600">{product.code || '-'}</td>
                                        <td className="px-3 py-2 text-right">{product.stock}</td>
                                        <td className="px-3 py-2 text-right">{product.reserved_stock ?? 0}</td>
                                        <td className="px-3 py-2 text-right">{product.available_stock ?? product.stock}</td>
                                        <td className="px-3 py-2 text-slate-600">{product.location || '-'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="mt-4 flex flex-wrap gap-2">
                        {products.links.map((link, index) => (
                            link.url ? (
                                <Link
                                    key={`${link.label}-${index}`}
                                    href={link.url}
                                    preserveScroll
                                    className={`rounded-md border px-3 py-1.5 text-sm ${link.active ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-200 text-slate-700 hover:bg-slate-50'}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ) : (
                                <span
                                    key={`${link.label}-${index}`}
                                    className="rounded-md border border-slate-100 px-3 py-1.5 text-sm text-slate-400"
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            )
                        ))}
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
