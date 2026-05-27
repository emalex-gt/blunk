import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatCurrency } from '@/utils/currency';
import { Head, Link, usePage } from '@inertiajs/react';

type Purchase = {
    id: number;
    business_number: number | null;
    created_at: string;
    total: string;
    paid_from_cash: boolean;
    supplier: { id: number; name: string } | null;
    created_by: { id: number; name: string } | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type Paginated<T> = {
    data: T[];
    links: PaginationLink[];
};

export default function Index({ purchases }: { purchases: Paginated<Purchase> }) {
    const business = usePage().props.business as { country?: string | null } | null;
    const country = business?.country ?? 'GT';

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-950">Compras</h2>}>
            <Head title="Historial de compras" />

            <div className="py-5">
                <div className="mx-auto max-w-[1800px] space-y-5 px-5 sm:px-6">
                    <section className="rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h1 className="text-xl font-semibold text-slate-950">Historial de compras</h1>
                                <p className="mt-1 text-sm text-slate-500">
                                    Revisa compras registradas y costos promedio.
                                </p>
                            </div>
                            <Link
                                href={route('purchases.create')}
                                className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
                            >
                                Registrar compra
                            </Link>
                        </div>
                    </section>

                    <section className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white/95 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead>
                                    <tr className="bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        <th className="px-4 py-3">Fecha</th>
                                        <th className="px-4 py-3">Compra</th>
                                        <th className="px-4 py-3">Proveedor</th>
                                        <th className="px-4 py-3">Usuario</th>
                                        <th className="px-4 py-3">Caja</th>
                                        <th className="px-4 py-3 text-right">Total</th>
                                        <th className="px-4 py-3 text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {purchases.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={7} className="px-4 py-12 text-center text-slate-500">
                                                Sin compras registradas
                                            </td>
                                        </tr>
                                    ) : (
                                        purchases.data.map((purchase) => (
                                            <tr key={purchase.id} className="transition-colors hover:bg-indigo-50/30">
                                                <td className="px-4 py-3 text-slate-600">
                                                    {formatDate(purchase.created_at)}
                                                </td>
                                                <td className="px-4 py-3 font-semibold text-slate-950">
                                                    {formatPurchaseNumber(purchase)}
                                                </td>
                                                <td className="px-4 py-3 font-semibold text-slate-950">
                                                    {purchase.supplier?.name ?? 'Sin proveedor'}
                                                </td>
                                                <td className="px-4 py-3 text-slate-600">
                                                    {purchase.created_by?.name ?? '-'}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {purchase.paid_from_cash ? (
                                                        <span className="rounded-full border border-indigo-100 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
                                                            Desde caja
                                                        </span>
                                                    ) : (
                                                        <span className="text-slate-400">-</span>
                                                    )}
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-950">
                                                    {formatCurrency(purchase.total, country)}
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <Link
                                                        href={route('purchases.show', purchase.id)}
                                                        className="rounded-lg px-3 py-1.5 text-sm font-semibold text-indigo-600 hover:bg-indigo-50"
                                                    >
                                                        Ver
                                                    </Link>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function formatDate(value: string) {
    return new Intl.DateTimeFormat('es', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}

function formatPurchaseNumber(purchase: Purchase) {
    return `C-${purchase.business_number ?? purchase.id}`;
}
