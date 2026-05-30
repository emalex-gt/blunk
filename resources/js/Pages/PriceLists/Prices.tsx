import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Toast from '@/Components/Toast';
import TextInput from '@/Components/TextInput';
import { useToast } from '@/hooks/useToast';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatCurrency } from '@/utils/currency';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useEffect, useMemo, useState } from 'react';

type PriceType = {
    id: number;
    name: string;
    is_default: boolean;
};

type Product = {
    id: number;
    name: string;
    code: string | null;
    barcode: string | null;
    sale_price: string | number;
    image_url: string | null;
    prices: { price: string | number }[];
};

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
};

export default function Prices({
    priceType,
    products,
    filters,
    pricingScope = 'global',
    activeBranch = null,
}: {
    priceType: PriceType;
    products: Paginated<Product>;
    filters: { search: string };
    pricingScope?: 'global' | 'branch';
    activeBranch?: { id: number; name: string } | null;
}) {
    const business = usePage().props.business as { country?: string | null } | null;
    const country = business?.country ?? 'GT';
    const [search, setSearch] = useState(filters.search ?? '');
    const initialPrices = useMemo(() => products.data.map((product) => ({
        product_id: product.id,
        price: String(product.prices?.[0]?.price ?? product.sale_price ?? '0'),
    })), [products.data]);
    const form = useForm({ prices: initialPrices });
    const toast = useToast();

    useEffect(() => {
        form.setData('prices', initialPrices);
    }, [initialPrices]);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(route('price-lists.prices.update', priceType.id), {
            preserveScroll: true,
            onSuccess: () => toast.success('Precios actualizados.'),
            onError: () => toast.error('No se pudieron guardar los precios.'),
        });
    }

    function applySearch(event: FormEvent) {
        event.preventDefault();
        router.get(route('price-lists.prices', priceType.id), { search }, { preserveState: true });
    }

    function setPrice(productId: number, price: string) {
        form.setData('prices', form.data.prices.map((row) => (
            row.product_id === productId ? { ...row, price } : row
        )));
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-950">Precios: {priceType.name}</h2>}>
            <Head title={`Precios ${priceType.name}`} />
            <Toast toasts={toast.toasts} onClose={toast.removeToast} />

            <div className="mx-auto max-w-6xl px-5 py-6 sm:px-6">
                <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-950">Asignar precios</h1>
                        <p className="mt-1 text-sm text-slate-500">Lista: {priceType.name}</p>
                        {pricingScope === 'branch' && activeBranch && (
                            <p className="mt-1 text-sm font-semibold text-indigo-700">
                                Precios de sucursal: {activeBranch.name}
                            </p>
                        )}
                    </div>
                    <Link href={route('price-lists.index')}>
                        <SecondaryButton type="button">Volver</SecondaryButton>
                    </Link>
                </div>

                <form onSubmit={applySearch} className="mb-4 flex gap-2">
                    <TextInput value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar producto, SKU o código" className="w-full" />
                    <PrimaryButton>Buscar</PrimaryButton>
                </form>

                <form onSubmit={submit} className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-100 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-3">Producto</th>
                                <th className="px-4 py-3">SKU / código</th>
                                <th className="px-4 py-3 text-right">{pricingScope === 'branch' ? 'Precio actual sucursal' : 'Precio actual'}</th>
                                <th className="px-4 py-3 text-right">Nuevo precio</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {products.data.map((product) => {
                                const row = form.data.prices.find((price) => price.product_id === product.id);

                                return (
                                    <tr key={product.id} className="hover:bg-indigo-50/30">
                                        <td className="px-4 py-3 font-semibold text-slate-950">{product.name}</td>
                                        <td className="px-4 py-3 text-slate-600">{product.code ?? product.barcode ?? '-'}</td>
                                        <td className="px-4 py-3 text-right text-slate-700">{formatCurrency(product.prices?.[0]?.price ?? product.sale_price, country)}</td>
                                        <td className="px-4 py-3 text-right">
                                            <TextInput
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                value={row?.price ?? ''}
                                                onChange={(event) => setPrice(product.id, event.target.value)}
                                                className="ml-auto w-36 text-right"
                                            />
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>

                    <div className="flex items-center justify-between gap-3 border-t border-slate-100 px-4 py-3">
                        <InputError message={form.errors.prices} />
                        <PrimaryButton disabled={form.processing}>Guardar precios</PrimaryButton>
                    </div>
                </form>

                {products.links.length > 3 && (
                    <div className="mt-4 flex flex-wrap gap-2">
                        {products.links.map((link, index) => (
                            <button
                                key={`${link.label}-${index}`}
                                type="button"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                className={['rounded-lg border px-3 py-1 text-sm', link.active ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-200 bg-white text-slate-700 disabled:opacity-40'].join(' ')}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
