import SuperAdminLayout from '@/Layouts/SuperAdminLayout';
import { router, useForm } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';

type Business = { id: number; name: string; currency: string };
type Summary = {
    examined: number;
    calculable: number;
    calculable_current_stock: number;
    calculable_historical_zero_stock: number;
    different: number;
    without_history: number;
    insufficient_history: number;
    ambiguous: number;
    invalid: number;
    negative_stock: number;
};
type ProductRow = {
    id: number;
    code: string | null;
    name: string;
    category: string | null;
    current_cost: string;
    proposed_cost: string | null;
    difference: string | null;
    physical_stock: string;
    covered_quantity: string;
    weighted_quantity: string;
    missing_quantity: string;
    acquisition_count: number;
    source: string | null;
    status: 'calculable' | 'stock_negativo' | 'sin_historial' | 'historial_insuficiente' | 'ambiguous' | 'datos_invalidos';
    calculation_method: 'current_stock_fifo_assumption' | 'historical_weighted_average' | null;
    invalid_acquisition_count: number;
    selected_acquisitions: Array<{
        acquired_at: string;
        source: string;
        identifier: string;
        historical_quantity: string;
        taken_quantity: string;
        unit_cost: string;
        partial: boolean;
    }>;
};
type Pagination<T> = { data: T[]; current_page: number; last_page: number; total: number };

const statusLabel: Record<ProductRow['status'], string> = {
    calculable: 'Calculable',
    stock_negativo: 'Stock negativo',
    sin_historial: 'Sin historial',
    historial_insuficiente: 'Historial insuficiente',
    ambiguous: 'Ambiguo',
    datos_invalidos: 'Datos inválidos',
};

export default function Index({
    businesses,
    selectedBusiness,
    summary,
    products,
    simulationToken,
}: {
    businesses: Business[];
    selectedBusiness: Business | null;
    summary: Summary | null;
    products: Pagination<ProductRow> | null;
    simulationToken: string | null;
}) {
    const [businessId, setBusinessId] = useState(String(selectedBusiness?.id ?? ''));
    const [confirming, setConfirming] = useState(false);
    const form = useForm({ simulation_token: simulationToken ?? '', confirmed: false });
    const money = new Intl.NumberFormat('es-GT', { minimumFractionDigits: 5, maximumFractionDigits: 5 });

    useEffect(() => {
        form.setData({ simulation_token: simulationToken ?? '', confirmed: false });
        form.clearErrors();
    }, [simulationToken]);

    function simulate(event: FormEvent) {
        event.preventDefault();
        if (!businessId) return;
        router.get(route('super-admin.tools.product-cost-recalculation.index'), { business_id: businessId }, { preserveState: false });
    }

    function apply() {
        form.transform(() => ({ simulation_token: simulationToken ?? '', confirmed: true }));
        form.post(route('super-admin.tools.product-cost-recalculation.apply'), {
            preserveScroll: true,
            onSuccess: () => setConfirming(false),
        });
    }

    return (
        <SuperAdminLayout title="Recalcular costo promedio de existencias">
            <div className="space-y-6">
                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h1 className="text-xl font-bold text-slate-950">Recalcular costo promedio de existencias</h1>
                    <p className="mt-2 max-w-3xl text-sm text-slate-600">Simula el costo de las existencias físicas actuales antes de actualizar exclusivamente el costo vigente de los productos.</p>
                    <p className="mt-3 max-w-4xl rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Para productos con existencias, el costo se calcula utilizando las adquisiciones más recientes hasta cubrir el stock físico total. Para productos sin existencias, se utiliza el promedio ponderado de todas las adquisiciones históricas válidas. Ninguno de los métodos reconstruye necesariamente los lotes reales del inventario.</p>
                    <form onSubmit={simulate} className="mt-5 flex flex-wrap items-end gap-3">
                        <label className="grid gap-1 text-sm font-semibold text-slate-700">
                            Tenant
                            <select value={businessId} onChange={(event) => setBusinessId(event.target.value)} className="min-w-72 rounded-xl border-slate-300 text-sm focus:border-orange-500 focus:ring-orange-500">
                                <option value="">Selecciona un tenant</option>
                                {businesses.map((business) => <option key={business.id} value={business.id}>{business.name}</option>)}
                            </select>
                        </label>
                        <button type="submit" disabled={!businessId} className="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Generar simulación</button>
                    </form>
                </section>

                {summary && selectedBusiness && products && (
                    <>
                        <section className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                            La aplicación recalcula en el servidor y sólo actualizará <strong>{summary.different}</strong> productos de <strong>{selectedBusiness.name}</strong> si la simulación sigue vigente. Productos ambiguos, con historial insuficiente o stock negativo quedan excluidos.
                        </section>
                        <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                            {[
                                ['Examinados', summary.examined], ['Calculados por existencias', summary.calculable_current_stock], ['Calculados por historial sin stock', summary.calculable_historical_zero_stock], ['Costos que cambiarán', summary.different],
                                ['Sin historial', summary.without_history], ['Historial insuficiente', summary.insufficient_history], ['Ambiguos', summary.ambiguous], ['Datos inválidos', summary.invalid], ['Stock negativo', summary.negative_stock],
                            ].map(([label, value]) => <div key={String(label)} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div><div className="mt-2 text-2xl font-bold text-slate-950">{value}</div></div>)}
                        </section>
                        <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                            <div className="border-b border-slate-200 p-4"><p className="text-sm text-slate-600">La tabla muestra la página actual. Los cambios se aplican al conjunto completo confirmado por el servidor. Abre el desglose para identificar adquisiciones tomadas total o parcialmente.</p></div>
                            <div className="overflow-x-auto"><table className="min-w-full text-left text-sm"><thead className="bg-slate-50 text-xs uppercase text-slate-600"><tr><th className="px-4 py-3">Código</th><th className="px-4 py-3">Producto</th><th className="px-4 py-3">Método</th><th className="px-4 py-3">Stock físico</th><th className="px-4 py-3">Actual</th><th className="px-4 py-3">Nuevo costo</th><th className="px-4 py-3">Diferencia</th><th className="px-4 py-3">Cubiertas / faltantes</th><th className="px-4 py-3">Adquisiciones</th><th className="px-4 py-3">Estado</th></tr></thead><tbody className="divide-y divide-slate-100">{products.data.map((product) => <tr key={product.id}><td className="px-4 py-3 text-slate-600">{product.code ?? '-'}</td><td className="px-4 py-3"><div className="font-semibold text-slate-900">{product.name}</div><div className="text-xs text-slate-500">{product.category ?? 'Sin categoría'}{product.source ? ` · ${product.source}` : ''}</div></td><td className="px-4 py-3 text-xs text-slate-600">{product.calculation_method === 'current_stock_fifo_assumption' ? 'Existencias actuales (FIFO estimado)' : product.calculation_method === 'historical_weighted_average' ? 'Promedio histórico (sin stock)' : '-'}</td><td className="px-4 py-3">{product.physical_stock}</td><td className="px-4 py-3">{money.format(Number(product.current_cost))}</td><td className="px-4 py-3">{product.proposed_cost === null ? '-' : money.format(Number(product.proposed_cost))}</td><td className="px-4 py-3">{product.difference === null ? '-' : money.format(Number(product.difference))}</td><td className="px-4 py-3">{product.calculation_method === 'historical_weighted_average' ? `${product.weighted_quantity} históricas` : `${product.covered_quantity} / ${product.missing_quantity}`}</td><td className="px-4 py-3"><details><summary className="cursor-pointer font-medium text-orange-700">{product.acquisition_count} utilizadas</summary><div className="mt-2 min-w-80 space-y-1 text-xs text-slate-600">{product.selected_acquisitions.map((item) => <div key={`${item.source}-${item.identifier}`}><strong>{item.partial ? 'Parcial' : 'Total'}:</strong> {item.taken_quantity} de {item.historical_quantity} × {money.format(Number(item.unit_cost))} · {item.acquired_at} · {item.source} #{item.identifier}</div>)}</div></details></td><td className="px-4 py-3"><span className={product.status === 'calculable' ? 'font-semibold text-emerald-700' : product.status === 'ambiguous' || product.status === 'historial_insuficiente' || product.status === 'datos_invalidos' ? 'font-semibold text-amber-700' : 'text-slate-500'}>{statusLabel[product.status]}{product.invalid_acquisition_count > 0 ? ' · datos inválidos' : ''}</span></td></tr>)}</tbody></table></div>
                            {products.last_page > 1 && <div className="flex items-center justify-between border-t border-slate-200 p-4 text-sm text-slate-600"><span>Página {products.current_page} de {products.last_page} · {products.total} productos</span><div className="flex gap-2"><button type="button" disabled={products.current_page === 1} onClick={() => router.get(route('super-admin.tools.product-cost-recalculation.index'), { business_id: selectedBusiness.id, page: products.current_page - 1 })} className="rounded-lg border px-3 py-1.5 disabled:opacity-50">Anterior</button><button type="button" disabled={products.current_page === products.last_page} onClick={() => router.get(route('super-admin.tools.product-cost-recalculation.index'), { business_id: selectedBusiness.id, page: products.current_page + 1 })} className="rounded-lg border px-3 py-1.5 disabled:opacity-50">Siguiente</button></div></div>}
                        </section>
                        <button type="button" onClick={() => setConfirming(true)} disabled={summary.different === 0 || form.processing} className="rounded-xl bg-orange-600 px-5 py-3 text-sm font-bold text-white shadow-sm hover:bg-orange-700 disabled:cursor-not-allowed disabled:opacity-50">Aplicar {summary.different} costos calculados</button>
                    </>
                )}
            </div>
            {confirming && selectedBusiness && <div role="dialog" aria-modal="true" aria-labelledby="confirm-title" className="fixed inset-0 z-[70] grid place-items-center bg-slate-950/50 p-4"><div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl"><h2 id="confirm-title" className="text-lg font-bold text-slate-950">Confirmar recálculo de costo promedio de existencias</h2><p className="mt-3 text-sm text-slate-600">Actualizarás el costo vigente de <strong>{summary?.different}</strong> productos del tenant <strong>{selectedBusiness.name}</strong>. Se recalculará antes de escribir; si cambió la simulación, no se aplicará nada.</p><p className="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Para productos con existencias, el costo se calcula utilizando las adquisiciones más recientes hasta cubrir el stock físico total. Para productos sin existencias, se utiliza el promedio ponderado de todas las adquisiciones históricas válidas. Ninguno de los métodos reconstruye necesariamente los lotes reales del inventario.</p>{form.errors.simulation_token && <p className="mt-3 text-sm font-medium text-red-700">{form.errors.simulation_token}</p>}<div className="mt-6 flex justify-end gap-3"><button type="button" onClick={() => setConfirming(false)} className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Cancelar</button><button type="button" onClick={apply} disabled={form.processing} className="rounded-xl bg-orange-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{form.processing ? 'Aplicando…' : 'Confirmar y aplicar'}</button></div></div></div>}
        </SuperAdminLayout>
    );
}
