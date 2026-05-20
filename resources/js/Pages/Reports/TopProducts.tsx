import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatCurrency } from '@/utils/currency';
import { Head, router, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type ProductRow = {
    product_id: number | null;
    product_name: string;
    stock: number | null;
    quantity_sold: string | number;
    total_sold: string | number;
    estimated_margin: string | number;
};
type BranchOption = { id: number; name: string; code: string | null };

export default function TopProducts({
    filters,
    products,
    branches_enabled = false,
    branches = [],
}: {
    filters: { start_date: string; end_date: string; branch_id?: number | null };
    products: ProductRow[];
    branches_enabled?: boolean;
    branches?: BranchOption[];
}) {
    const business = usePage().props.business as { country?: string | null } | null;
    const country = business?.country ?? 'GT';
    const [startDate, setStartDate] = useState(filters.start_date);
    const [endDate, setEndDate] = useState(filters.end_date);
    const [branchId, setBranchId] = useState<string>(filters.branch_id ? String(filters.branch_id) : '');

    function submit(event: FormEvent) {
        event.preventDefault();
        router.get(route('reports.top-products'), { start_date: startDate, end_date: endDate, branch_id: branchId || undefined }, { preserveState: true });
    }

    function clear() {
        router.get(route('reports.top-products'));
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-950">Top productos</h2>}>
            <Head title="Top productos" />

            <div className="py-5">
                <div className="mx-auto max-w-[1800px] space-y-5 px-5 sm:px-6">
                    <form onSubmit={submit} className="rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="grid gap-4 md:grid-cols-[1fr_1fr_1fr_auto]">
                            <DateField label="Desde" value={startDate} onChange={setStartDate} />
                            <DateField label="Hasta" value={endDate} onChange={setEndDate} />
                            {branches_enabled && (
                                <label className="block">
                                    <span className="text-sm font-medium text-slate-700">Sucursal</span>
                                    <select value={branchId} onChange={(e) => setBranchId(e.target.value)} className="mt-1 w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100">
                                        <option value="">Todas</option>
                                        {branches.map((branch) => (
                                            <option key={branch.id} value={branch.id}>{branch.name}</option>
                                        ))}
                                    </select>
                                </label>
                            )}
                            <div className="flex items-end gap-2">
                                <button type="submit" className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                                    Filtrar
                                </button>
                                <button type="button" onClick={clear} className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                                    Limpiar
                                </button>
                            </div>
                        </div>
                    </form>

                    <section className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white/95 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="p-5">
                            <h3 className="text-xl font-semibold text-slate-950">Top 50 productos vendidos</h3>
                            <p className="mt-1 text-sm text-slate-500">
                                Ordenado por cantidad vendida en el rango seleccionado.
                            </p>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200 text-sm">
                                <thead>
                                    <tr className="bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        <th className="px-4 py-3">Producto</th>
                                        <th className="px-4 py-3 text-right">Cantidad vendida</th>
                                        <th className="px-4 py-3 text-right">Total vendido</th>
                                        <th className="px-4 py-3 text-right">Margen estimado</th>
                                        <th className="px-4 py-3 text-right">Stock actual</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {products.length === 0 ? (
                                        <tr>
                                            <td colSpan={5} className="px-4 py-12 text-center text-slate-500">
                                                Sin resultados
                                            </td>
                                        </tr>
                                    ) : (
                                        products.map((product) => (
                                            <tr key={`${product.product_id}-${product.product_name}`} className="transition-colors hover:bg-indigo-50/30">
                                                <td className="px-4 py-3 font-semibold text-slate-950">{product.product_name}</td>
                                                <td className="px-4 py-3 text-right text-slate-700">{Number(product.quantity_sold)}</td>
                                                <td className="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-950">
                                                    {formatCurrency(Number(product.total_sold), country)}
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-3 text-right text-slate-700">
                                                    {formatCurrency(Number(product.estimated_margin), country)}
                                                </td>
                                                <td className="px-4 py-3 text-right text-slate-700">
                                                    {product.stock ?? '-'}
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

function DateField({ label, value, onChange }: { label: string; value: string; onChange: (value: string) => void }) {
    return (
        <label className="block">
            <span className="text-sm font-medium text-slate-700">{label}</span>
            <input type="date" value={value} onChange={(e) => onChange(e.target.value)} className="mt-1 w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100" />
        </label>
    );
}
