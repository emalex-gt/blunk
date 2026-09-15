import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Seller = {
    seller: { id: number; name: string | null };
    work_day_ids: number[];
    pre_sales: { id: number; total: number }[];
    total: number;
    total_pre_sales: number;
    total_amount: number;
    prepared_count: number;
    converted_count: number;
    preparation_eligible_count: number;
    sales_eligible_count: number;
    preparation_blocked_count: number;
    sales_blocked_count: number;
};

type Preview = {
    summary: {
        sellers: number;
        work_days: number;
        pre_sales: number;
        total: number;
        prepared_count: number;
        converted_count: number;
        preparation_eligible_count: number;
        sales_eligible_count: number;
        preparation_blocked_count: number;
        sales_blocked_count: number;
    };
    sellers: Seller[];
    blocked: { work_day_id: number; pre_sale_id: number; seller_id: number; reason_code: string }[];
};

type Result = {
    processed: { batch_id: number; seller_name?: string | null; work_day_id: number }[];
    blocked: unknown[];
    failed: { message?: string }[];
};

export default function Index({ preparation_preview, sales_preview, stock_deduction_timing, fel_enabled }: {
    preparation_preview: Preview;
    sales_preview: Preview;
    stock_deduction_timing: 'picking' | 'invoice';
    fel_enabled: boolean;
}) {
    const [confirming, setConfirming] = useState<'prepare' | 'sales' | null>(null);
    const flash = (usePage().props as { flash?: { global_preparation_result?: Result; global_sales_result?: Result } }).flash ?? {};
    const preparationEligible = preparation_preview.summary.preparation_eligible_count;
    const salesEligible = sales_preview.summary.sales_eligible_count;
    const preparationAvailabilityCopy = preparationEligible === 0
        ? 'No hay pedidos pendientes de preparar.'
        : preparationEligible === 1 ? '1 pedido pendiente de preparar.' : `${preparationEligible} pedidos pendientes de preparar.`;
    const batchIds = flash.global_preparation_result?.processed.map(row => row.batch_id) ?? [];
    const submit = (url: string) => router.post(url, { idempotency_key: `global-${crypto.randomUUID()}` }, { preserveScroll: true });
    const documentUrl = (name: 'consolidated' | 'products' | 'receipts') => `${route(`routes.global-operations.documents.${name}`)}?${batchIds.map(id => `batch_ids[]=${id}`).join('&')}`;
    const activePreview = confirming === 'prepare' ? preparation_preview : sales_preview;
    const readyCount = confirming === 'prepare' ? preparationEligible : salesEligible;
    const notReadyCount = confirming === 'prepare'
        ? preparation_preview.summary.preparation_blocked_count
        : sales_preview.summary.sales_blocked_count;
    const operationName = confirming === 'prepare' ? 'preparar' : 'generar ventas';

    return <AuthenticatedLayout><Head title="Operación global de rutas" />
        <main className="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <header className="flex flex-wrap items-end justify-between gap-4">
                <div><h1 className="text-2xl font-semibold text-slate-950">Operación global de rutas</h1><p className="mt-1 text-sm text-slate-500">Prepare y genere ventas de toda la sucursal sin entrar jornada por jornada.</p></div>
                <div className="flex flex-wrap items-start gap-2">
                    <div><button disabled={preparationEligible === 0} title={preparationAvailabilityCopy} onClick={() => setConfirming('prepare')} className="rounded-lg bg-indigo-600 px-4 py-2 font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300">PREPARAR TODO</button><p className="mt-1 text-xs text-slate-500">{preparationAvailabilityCopy}</p></div>
                    <div><button disabled={salesEligible === 0} title={salesEligible === 0 ? 'No hay pedidos preparados pendientes de generar ventas.' : undefined} onClick={() => setConfirming('sales')} className="rounded-lg bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-slate-300">GENERAR VENTAS</button>{salesEligible === 0 && <p className="mt-1 text-xs text-slate-500">No hay pedidos preparados pendientes de generar ventas.</p>}</div>
                </div>
            </header>

            <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                <Metric label="Vendedores" value={preparation_preview.summary.sellers} />
                <Metric label="Jornadas" value={preparation_preview.summary.work_days} />
                <Metric label="Pedidos" value={preparation_preview.summary.pre_sales} />
                <Metric label="Total" value={`Q ${money(preparation_preview.summary.total)}`} />
                <Metric label="Preparados" value={`${preparation_preview.summary.prepared_count}/${preparation_preview.summary.pre_sales}`} />
                <Metric label="Ventas" value={`${preparation_preview.summary.converted_count}/${preparation_preview.summary.pre_sales}`} />
            </section>

            <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <h2 className="font-semibold text-slate-950">Por vendedor</h2>
                <div className="mt-4 grid gap-3 md:grid-cols-2">{preparation_preview.sellers.map(group => <article key={group.seller.id} className="rounded-lg border border-slate-200 p-4"><h3 className="font-semibold">{group.seller.name ?? `Vendedor #${group.seller.id}`}</h3><p className="mt-2 text-sm text-slate-600">{group.total_pre_sales} pedidos · Q {money(group.total_amount)}</p><p className="text-sm text-slate-600">Preparados {group.prepared_count}/{group.total_pre_sales}</p><p className="text-sm text-slate-600">Ventas {group.converted_count}/{group.total_pre_sales}</p></article>)}</div>
            </section>

            <section className="rounded-lg border border-slate-200 bg-white p-5 text-sm shadow-sm"><p><strong>Inventario:</strong> {stock_deduction_timing === 'picking' ? 'El inventario ya fue descontado durante preparación.' : 'Se descontará al generar las ventas.'}</p><p className="mt-1"><strong>Facturación electrónica:</strong> {fel_enabled ? 'según la configuración existente.' : 'desactivada.'}</p></section>
            {flash.global_preparation_result && <ResultPanel title="Resultado de preparación" result={flash.global_preparation_result} links={batchIds.length ? <div className="mt-3 flex flex-wrap gap-2"><a className="rounded border px-3 py-2" href={documentUrl('consolidated')}>Consolidado</a><a className="rounded border px-3 py-2" href={documentUrl('products')}>Resumen de productos</a><a className="rounded border px-3 py-2" href={documentUrl('receipts')}>Recibos</a></div> : null} />}
            {flash.global_sales_result && <ResultPanel title="Resultado de ventas" result={flash.global_sales_result} />}

            {confirming && <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 p-4"><section role="dialog" aria-modal="true" className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl"><h2 className="text-lg font-semibold">{confirming === 'prepare' ? 'Preparar todo' : 'Generar ventas'}</h2><p className="mt-2 text-sm text-slate-600">{activePreview.summary.sellers} vendedores · {activePreview.summary.work_days} jornadas · {activePreview.summary.pre_sales} pedidos.</p><p className="mt-1 text-sm text-slate-600">{readyCount} listos para {operationName} · {notReadyCount} no listos para {operationName}.</p>{activePreview.blocked.length > 0 && <ul className="mt-3 list-disc space-y-1 pl-5 text-sm text-slate-600">{activePreview.blocked.map(row => <li key={`${row.work_day_id}-${row.pre_sale_id}`}>{blockReason(row.reason_code)}</li>)}</ul>}<p className="mt-3 text-sm text-slate-600">{confirming === 'sales' && (stock_deduction_timing === 'picking' ? 'El inventario ya fue descontado durante preparación. ' : 'El inventario se descontará al generar las ventas. ')}Facturación electrónica: {fel_enabled ? 'según configuración existente.' : 'desactivada.'}</p><div className="mt-5 flex justify-end gap-2"><button onClick={() => setConfirming(null)} className="rounded-lg border px-3 py-2 text-sm font-semibold">Cancelar</button><button onClick={() => { submit(route(confirming === 'prepare' ? 'routes.global-operations.prepare' : 'routes.global-operations.generate-sales')); setConfirming(null); }} className="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white">Confirmar</button></div></section></div>}
        </main></AuthenticatedLayout>;
}

function Metric({ label, value }: { label: string; value: string | number }) { return <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm"><div className="text-xs font-semibold uppercase text-slate-500">{label}</div><div className="mt-1 text-xl font-semibold text-slate-950">{value}</div></div>; }
function ResultPanel({ title, result, links }: { title: string; result: Result; links?: React.ReactNode }) { return <section className="rounded-lg border border-indigo-200 bg-indigo-50 p-5"><h2 className="font-semibold text-indigo-950">{title}</h2><p className="mt-1 text-sm text-indigo-900">Procesadas: {result.processed.length} · Bloqueadas: {result.blocked.length} · Fallidas: {result.failed.length}</p>{links}</section>; }
function blockReason(reasonCode: string) { return reasonCode === 'pre_sale_already_converted' ? 'Venta ya generada.' : 'No está en la etapa requerida para esta operación.'; }
function money(value: number) { return Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
