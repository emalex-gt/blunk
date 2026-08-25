import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

type Batch = {
    id: number;
    status: string;
    prepared_at?: string | null;
    stock_deduction_timing: 'picking' | 'invoice';
    invoicing_mode: 'manual' | 'automatic_all';
    total_pre_sales: number;
    total_items: number;
    total_amount: number;
    branch?: { name: string } | null;
    zone?: { name: string } | null;
    prepared_by?: { name: string } | null;
    work_day?: { work_date?: string | null } | null;
};

type Page<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; from: number | null; to: number | null; total: number };

export default function Index({ batches }: { batches: Page<Batch> }) {
    return (
        <AuthenticatedLayout>
            <Head title="Preparaciones" />
            <div className="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
                <div>
                    <Link href={route('routes.pre-sales.index')} className="text-sm font-semibold text-indigo-600 hover:text-indigo-800">Volver a preventas</Link>
                    <h1 className="mt-2 text-2xl font-semibold text-slate-950">Preparaciones</h1>
                    <p className="mt-1 text-sm text-slate-500">Historial de lotes preparados por jornada.</p>
                </div>

                <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="min-w-[900px] divide-y divide-slate-200 text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
                                <tr><th className="px-4 py-3">Lote</th><th className="px-4 py-3">Jornada</th><th className="px-4 py-3">Sucursal</th><th className="px-4 py-3">Preparado por</th><th className="px-4 py-3">Timing</th><th className="px-4 py-3 text-right">Preventas</th><th className="px-4 py-3 text-right">Items</th><th className="px-4 py-3 text-right">Total</th><th className="px-4 py-3">Acción</th></tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {batches.data.length === 0 ? <tr><td colSpan={9} className="px-4 py-10 text-center text-slate-500">No hay lotes de preparación.</td></tr> : batches.data.map((batch) => (
                                    <tr key={batch.id} className="hover:bg-slate-50/70">
                                        <td className="px-4 py-3 font-semibold text-slate-900">#{batch.id}<div className="text-xs font-normal text-slate-500">{statusLabel(batch.status)}</div></td>
                                        <td className="px-4 py-3">{batch.work_day?.work_date ?? '-'}<div className="text-xs text-slate-500">{formatDate(batch.prepared_at)}</div></td>
                                        <td className="px-4 py-3">{batch.branch?.name ?? '-'}<div className="text-xs text-slate-500">{batch.zone?.name ?? '-'}</div></td>
                                        <td className="px-4 py-3">{batch.prepared_by?.name ?? '-'}</td>
                                        <td className="px-4 py-3">{batch.stock_deduction_timing === 'picking' ? 'Al preparar' : 'Al facturar'}</td>
                                        <td className="px-4 py-3 text-right">{batch.total_pre_sales}</td><td className="px-4 py-3 text-right">{batch.total_items}</td><td className="px-4 py-3 text-right">Q {formatMoney(batch.total_amount)}</td>
                                        <td className="px-4 py-3"><Link href={route('routes.preparation-batches.show', batch.id)} className="rounded-md border border-slate-200 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50">Ver</Link></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 text-sm text-slate-500">
                        <span>{batches.from && batches.to ? `${batches.from}-${batches.to} de ${batches.total}` : `${batches.total} resultados`}</span>
                        <div className="flex gap-1">{batches.links.map((link, index) => <Link key={`${link.label}-${index}`} href={link.url ?? '#'} preserveScroll className={`rounded-md px-3 py-1 ${link.active ? 'bg-indigo-600 text-white' : 'border border-slate-200 text-slate-600'} ${!link.url ? 'pointer-events-none opacity-50' : 'hover:bg-slate-50'}`} dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function statusLabel(status: string) {
    return status === 'completed' ? 'Completado' : status === 'processing' ? 'Procesando' : status === 'failed' ? 'Fallido' : status;
}

function formatDate(value?: string | null) { return value ? new Date(value).toLocaleString() : '-'; }
function formatMoney(value: number) { return Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
