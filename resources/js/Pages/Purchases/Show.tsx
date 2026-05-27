import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatCurrency } from '@/utils/currency';
import { Head, Link, usePage } from '@inertiajs/react';

type PurchaseItem = {
    id: number;
    product_name: string;
    quantity: string;
    unit_cost: string;
    previous_cost: string;
    new_average_cost: string;
    total: string;
    product: { id: number; code: string | null; barcode: string | null } | null;
};

type Purchase = {
    id: number;
    business_number: number | null;
    created_at: string;
    total: string;
    note: string | null;
    paid_from_cash: boolean;
    cash_register_session: { id: number } | null;
    supplier: { id: number; name: string } | null;
    created_by: { id: number; name: string } | null;
    items: PurchaseItem[];
};

export default function Show({ purchase }: { purchase: Purchase }) {
    const business = usePage().props.business as { country?: string | null } | null;
    const country = business?.country ?? 'GT';
    const purchaseNumber = `C-${purchase.business_number ?? purchase.id}`;

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-xl font-semibold text-slate-950">Ver compra</h2>
                    <Link
                        href={route('purchases.index')}
                        className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50"
                    >
                        Volver
                    </Link>
                </div>
            }
        >
            <Head title={`Compra ${purchaseNumber}`} />

            <div className="py-5">
                <div className="mx-auto max-w-[1800px] space-y-5 px-5 sm:px-6">
                    <section className="rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="grid gap-4 md:grid-cols-4">
                            <Summary label="Compra" value={purchaseNumber} />
                            <Summary label="Proveedor" value={purchase.supplier?.name ?? 'Sin proveedor'} />
                            <Summary label="Usuario" value={purchase.created_by?.name ?? '-'} />
                            <Summary label="Total" value={formatCurrency(purchase.total, country)} />
                        </div>
                        {purchase.paid_from_cash && (
                            <div className="mt-5 rounded-2xl border border-indigo-100 bg-indigo-50 p-4 text-sm font-semibold text-indigo-700">
                                Compra pagada desde caja
                                {purchase.cash_register_session?.id ? ` #${purchase.cash_register_session.id}` : ''}
                            </div>
                        )}
                        {purchase.note && (
                            <div className="mt-5 rounded-2xl bg-slate-50 p-4 text-sm text-slate-600">
                                {purchase.note}
                            </div>
                        )}
                    </section>

                    <section className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white/95 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="p-5">
                            <h3 className="text-xl font-semibold text-slate-950">Productos comprados</h3>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead>
                                    <tr className="bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        <th className="px-4 py-3">Producto</th>
                                        <th className="px-4 py-3">Código</th>
                                        <th className="px-4 py-3 text-right">Cantidad</th>
                                        <th className="px-4 py-3 text-right">Costo anterior</th>
                                        <th className="px-4 py-3 text-right">Costo unitario</th>
                                        <th className="px-4 py-3 text-right">Nuevo costo promedio</th>
                                        <th className="px-4 py-3 text-right">Total</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {purchase.items.map((item) => (
                                        <tr key={item.id} className="transition-colors hover:bg-indigo-50/30">
                                            <td className="px-4 py-3 font-semibold text-slate-950">{item.product_name}</td>
                                            <td className="px-4 py-3 text-slate-600">
                                                {item.product?.barcode || item.product?.code || '-'}
                                            </td>
                                            <td className="px-4 py-3 text-right text-slate-700">{Number(item.quantity)}</td>
                                            <td className="whitespace-nowrap px-4 py-3 text-right text-slate-700">
                                                {formatCurrency(item.previous_cost, country)}
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3 text-right text-slate-700">
                                                {formatCurrency(item.unit_cost, country)}
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-950">
                                                {formatCurrency(item.new_average_cost, country)}
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-950">
                                                {formatCurrency(item.total, country)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Summary({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-2 whitespace-nowrap text-xl font-bold text-slate-950">{value}</div>
        </div>
    );
}
