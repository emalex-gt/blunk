import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { t } from '@/lib/i18n';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Product = {
    id: number;
    name: string;
    code: string | null;
    stock: number;
    reserved_stock?: number;
    available_stock?: number;
    location: string | null;
};

export default function StockIndex({ products }: { products: Product[] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        product_id: '',
        type: 'add',
        quantity: '1',
        note: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        post(route('stock.store'), { onSuccess: () => reset('quantity', 'note') });
    }

    const selectedProduct = products.find((product) => String(product.id) === data.product_id);

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold text-gray-800">{t('stock.stock')}</h2>}
        >
            <Head title={t('stock.stock')} />

            <div className="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
                <form onSubmit={submit} className="space-y-4 bg-white p-5 shadow sm:rounded-lg">
                    <div>
                        <InputLabel htmlFor="product_id" value={t('products.product')} />
                        <select id="product_id" value={data.product_id} onChange={(e) => setData('product_id', e.target.value)} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">{t('stock.select_product')}</option>
                            {products.map((product) => (
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
            </div>
        </AuthenticatedLayout>
    );
}
