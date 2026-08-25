import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

type Batch = {
    id: number; status: string; prepared_at?: string | null; stock_deduction_timing: 'picking' | 'invoice'; invoicing_mode: 'manual' | 'automatic_all'; total_pre_sales: number; total_items: number; total_amount: number;
    branch?: { name: string } | null; zone?: { name: string } | null; prepared_by?: { name: string } | null; work_day?: { work_date?: string | null } | null;
    pre_sales: { id: number; status: string; total_items: number; total_amount: number; pre_sale?: { id: number; status: string; customer?: { name: string; commercial_name?: string | null; doc_number?: string | null } | null; converted_sale?: { id: number; business_number?: number | null; total: number } | null } | null }[];
};

export default function Show({ batch }: { batch: Batch }) {
    return (
        <AuthenticatedLayout>
            <Head title={`Preparación #${batch.id}`} />
            <div className="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div><Link href={route('routes.preparation-batches.index')} className="text-sm font-semibold text-indigo-600 hover:text-indigo-800">Volver a preparaciones</Link><h1 className="mt-2 text-2xl font-semibold text-slate-950">Lote de preparación #{batch.id}</h1><p className="mt-1 text-sm text-slate-500">Jornada {batch.work_day?.work_date ?? '-'} · {batch.branch?.name ?? '-'}</p></div>
                    <div className="flex flex-wrap gap-2">
                        <a href={route('routes.preparation-batches.documents.consolidated', batch.id)} className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Consolidado</a>
                        <a href={route('routes.preparation-batches.documents.receipts', batch.id)} className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Recibos</a>
                        <a href={route('routes.preparation-batches.documents.products', batch.id)} className="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Productos</a>
                    </div>
                </div>
                {batch.invoicing_mode === 'automatic_all' && <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">La facturación automática está configurada, pero requiere definir documento y método de pago predeterminados antes de emitir documentos masivos.</div>}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"><Card label="Preventas" value={batch.total_pre_sales} /><Card label="Items" value={batch.total_items} /><Card label="Total" value={`Q ${formatMoney(batch.total_amount)}`} /><Card label="Stock" value={batch.stock_deduction_timing === 'picking' ? 'Descontado al preparar' : 'Pendiente de facturar'} /></div>
                <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm"><div className="overflow-x-auto"><table className="min-w-[760px] divide-y divide-slate-200 text-sm"><thead className="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th className="px-4 py-3">Preventa</th><th className="px-4 py-3">Cliente</th><th className="px-4 py-3">Estado</th><th className="px-4 py-3 text-right">Items</th><th className="px-4 py-3 text-right">Total</th><th className="px-4 py-3">Venta</th></tr></thead><tbody className="divide-y divide-slate-100">{batch.pre_sales.map((entry) => <tr key={entry.id}><td className="px-4 py-3"><Link className="font-semibold text-indigo-600 hover:text-indigo-800" href={route('routes.pre-sales.show', entry.pre_sale?.id)}>#{entry.pre_sale?.id ?? '-'}</Link></td><td className="px-4 py-3">{entry.pre_sale?.customer?.commercial_name || entry.pre_sale?.customer?.name || '-'}</td><td className="px-4 py-3">{entry.pre_sale?.status ?? entry.status}</td><td className="px-4 py-3 text-right">{entry.total_items}</td><td className="px-4 py-3 text-right">Q {formatMoney(entry.total_amount)}</td><td className="px-4 py-3">{entry.pre_sale?.converted_sale ? <Link href={route('sales.show', entry.pre_sale.converted_sale.id)} className="text-sm font-semibold text-violet-600 hover:text-violet-800">V-{entry.pre_sale.converted_sale.business_number ?? entry.pre_sale.converted_sale.id}</Link> : '-'}</td></tr>)}</tbody></table></div></section>
            </div>
        </AuthenticatedLayout>
    );
}

function Card({ label, value }: { label: string; value: string | number }) { return <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm"><div className="text-xs font-semibold uppercase text-slate-500">{label}</div><div className="mt-1 text-lg font-semibold text-slate-950">{value}</div></div>; }
function formatMoney(value: number) { return Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
