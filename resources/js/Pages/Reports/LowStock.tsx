import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatCurrency } from '@/utils/currency';
import { Head, router, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Product = {
    id: number;
    name: string;
    code: string | null;
    barcode: string | null;
    stock: number;
    min_stock: number;
    location: string | null;
    sale_price: number;
};
type BranchOption = { id: number; name: string; code: string | null };

export default function LowStock({
    filters,
    products,
    branches_enabled = false,
    branches = [],
}: {
    filters: { search: string; branch_id?: number | null };
    products: Product[];
    branches_enabled?: boolean;
    branches?: BranchOption[];
}) {
    const business = usePage().props.business as { country?: string | null } | null;
    const country = business?.country ?? 'GT';
    const [search, setSearch] = useState(filters.search ?? '');
    const [branchId, setBranchId] = useState<string>(filters.branch_id ? String(filters.branch_id) : '');

    function submit(event: FormEvent) {
        event.preventDefault();
        router.get(route('reports.low-stock'), { search, branch_id: branchId || undefined }, { preserveState: true });
    }

    function clear() {
        router.get(route('reports.low-stock'));
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-950">Stock bajo</h2>}>
            <Head title="Reporte de stock bajo" />

            <div className="py-5">
                <div className="mx-auto max-w-[1800px] space-y-5 px-5 sm:px-6">
                    <form onSubmit={submit} className="rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="flex flex-wrap gap-3">
                            <input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Buscar por producto, código o código de barras"
                                className="min-w-80 rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                            />
                            {branches_enabled && (
                                <select
                                    value={branchId}
                                    onChange={(event) => setBranchId(event.target.value)}
                                    className="rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                >
                                    <option value="">Sucursal activa</option>
                                    {branches.map((branch) => (
                                        <option key={branch.id} value={branch.id}>
                                            {branch.name}
                                        </option>
                                    ))}
                                </select>
                            )}
                            <button type="submit" className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                                Filtrar
                            </button>
                            <button type="button" onClick={clear} className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                                Limpiar
                            </button>
                        </div>
                    </form>

                    <section className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white/95 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="p-5">
                            <h3 className="text-xl font-semibold text-slate-950">Productos con alerta de stock</h3>
                            <p className="mt-1 text-sm text-slate-500">
                                Incluye productos sin stock y productos bajo su mínimo.
                            </p>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200 text-sm">
                                <thead>
                                    <tr className="bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        <th className="px-4 py-3">Producto</th>
                                        <th className="px-4 py-3">Código / Código de barras</th>
                                        <th className="px-4 py-3">Stock actual</th>
                                        <th className="px-4 py-3">Stock mínimo</th>
                                        <th className="px-4 py-3">Ubicación</th>
                                        <th className="px-4 py-3 text-right">Precio</th>
                                        <th className="px-4 py-3">Estado</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {products.length === 0 ? (
                                        <tr>
                                            <td colSpan={7} className="px-4 py-12 text-center text-slate-500">
                                                Sin resultados
                                            </td>
                                        </tr>
                                    ) : (
                                        products.map((product) => (
                                            <tr key={product.id} className="transition-colors hover:bg-indigo-50/30">
                                                <td className="px-4 py-3 font-semibold text-slate-950">{product.name}</td>
                                                <td className="px-4 py-3 text-slate-600">{product.barcode || product.code || '-'}</td>
                                                <td className="px-4 py-3 text-slate-700">{product.stock}</td>
                                                <td className="px-4 py-3 text-slate-700">{product.min_stock}</td>
                                                <td className="px-4 py-3 text-slate-600">{product.location || '-'}</td>
                                                <td className="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-950">
                                                    {formatCurrency(product.sale_price, country)}
                                                </td>
                                                <td className="px-4 py-3">{stockBadge(product.stock)}</td>
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

function stockBadge(stock: number) {
    if (stock <= 0) {
        return <span className="rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700">Sin stock</span>;
    }

    return <span className="rounded-full bg-orange-100 px-2.5 py-1 text-xs font-semibold text-orange-700">Stock bajo</span>;
}
