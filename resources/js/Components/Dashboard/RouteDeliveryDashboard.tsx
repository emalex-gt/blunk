import { Link } from '@inertiajs/react';
import { formatCurrency } from '@/utils/currency';

export type RouteDeliveryDashboardData = {
    has_current_run: boolean;
    message?: string;
    run?: { id: number; status: 'open' | 'draft' };
    progress?: {
        assigned: number;
        pending: number;
        delivered: number;
        not_delivered: number;
        unpaid_delivered_count: number;
        unpaid_delivered_amount: string | number;
    };
    next_stop?: {
        id: number;
        position: number | null;
        customer_name: string | null;
        customer_address: string | null;
        customer_address_reference: string | null;
        customer_phone: string | null;
        sale_number: string | null;
        sale_total: string | number | null;
        href: string;
    } | null;
    primary_action: { label: string; href: string };
};

export default function RouteDeliveryDashboard({ data, country }: { data: RouteDeliveryDashboardData; country: string }) {
    if (!data.has_current_run) {
        return <NoCurrentRun data={data} />;
    }

    const progress = data.progress ?? {
        assigned: 0,
        pending: 0,
        delivered: 0,
        not_delivered: 0,
        unpaid_delivered_count: 0,
        unpaid_delivered_amount: 0,
    };
    const resolved = progress.delivered + progress.not_delivered;
    const completion = progress.assigned > 0 ? Math.min(100, Math.round((resolved / progress.assigned) * 100)) : 0;
    const isOpen = data.run?.status === 'open';
    const statusLabel = isOpen ? 'Jornada abierta' : 'Jornada en borrador';

    return (
        <section data-dashboard-widget="routes.my_delivery_run" className="sm:col-span-2 xl:col-span-3 rounded-2xl border border-slate-200/80 bg-white shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
            <header className="border-b border-slate-100 p-5 sm:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className="text-xs font-bold tracking-[0.14em] text-orange-700">MIS ENTREGAS</p>
                        <h2 className="mt-1 text-2xl font-bold tracking-tight text-slate-950">Jornada #{data.run?.id}</h2>
                        <p className="mt-1 text-sm text-slate-500">{progress.assigned} entrega(s) asignada(s)</p>
                    </div>
                    <span className={`rounded-full px-3 py-1 text-xs font-bold ${isOpen ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800'}`}>
                        {statusLabel}
                    </span>
                </div>

                {isOpen ? (
                    <div className="mt-5">
                        <div className="flex items-baseline justify-between gap-3">
                            <p className="text-sm font-semibold text-slate-800">Progreso de paradas</p>
                            <p className="text-sm font-bold text-slate-900">{resolved} de {progress.assigned}</p>
                        </div>
                        <div
                            className="mt-2 h-3 overflow-hidden rounded-full bg-slate-100"
                            role="progressbar"
                            aria-label="Progreso de entregas"
                            aria-valuemin={0}
                            aria-valuemax={progress.assigned}
                            aria-valuenow={resolved}
                            aria-valuetext={`${completion}% completado`}
                        >
                            <div className="h-full rounded-full bg-orange-600 transition-all" style={{ width: `${completion}%` }} />
                        </div>
                        <p className="mt-2 text-xs font-medium text-slate-500">{completion}% completado</p>
                    </div>
                ) : (
                    <p className="mt-5 rounded-xl bg-amber-50 p-3 text-sm font-medium text-amber-800">
                        La jornada está lista para revisarse antes de iniciar las entregas.
                    </p>
                )}
            </header>

            <div className="space-y-5 p-5 sm:p-6">
                <section aria-label="Indicadores de entrega" className="grid grid-cols-3 gap-3">
                    <MetricTile label="Entregadas" value={progress.delivered} tone="emerald" />
                    <MetricTile label="Pendientes" value={progress.pending} tone="amber" />
                    <MetricTile label="No entregadas" value={progress.not_delivered} tone="slate" />
                </section>

                {isOpen && progress.unpaid_delivered_count > 0 && (
                    <section aria-label="Cobros pendientes" className="rounded-xl border border-amber-200 bg-amber-50 p-4">
                        <p className="text-xs font-bold uppercase tracking-wide text-amber-800">Cobros pendientes</p>
                        <p className="mt-2 text-lg font-bold text-amber-950">{progress.unpaid_delivered_count} entrega(s) realizada(s)</p>
                        <p className="mt-1 text-sm font-medium text-amber-900">{formatCurrency(progress.unpaid_delivered_amount, country)} pendiente(s) de cobro</p>
                    </section>
                )}

                {isOpen && data.next_stop ? (
                    <section aria-label="Próxima entrega" className="rounded-xl border border-orange-200 bg-orange-50/60 p-4">
                        <p className="text-xs font-bold uppercase tracking-wide text-orange-800">Próxima entrega{data.next_stop.position ? ` · #${data.next_stop.position}` : ''}</p>
                        <p className="mt-2 text-lg font-bold text-slate-950">{data.next_stop.customer_name ?? 'Cliente sin nombre'}</p>
                        {data.next_stop.customer_address && <p className="mt-1 text-sm text-slate-600">{data.next_stop.customer_address}</p>}
                        {data.next_stop.customer_address_reference && <p className="mt-1 text-sm text-slate-600">Referencia: {data.next_stop.customer_address_reference}</p>}
                        <p className="mt-2 text-sm font-medium text-slate-700">
                            {data.next_stop.sale_number ? `Comprobante #${data.next_stop.sale_number}` : 'Comprobante'}
                            {data.next_stop.sale_total !== null ? ` · ${formatCurrency(data.next_stop.sale_total, country)}` : ''}
                        </p>
                        <Link href={data.next_stop.href} className="mt-4 block rounded-xl bg-orange-600 px-4 py-3 text-center text-sm font-bold text-white transition hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2">
                            Abrir entrega
                        </Link>
                    </section>
                ) : isOpen ? (
                    <section aria-label="Próxima entrega" className="rounded-xl bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
                        Todas las entregas están resueltas.
                    </section>
                ) : null}

                <Link href={data.primary_action.href} className="block rounded-xl bg-orange-600 px-4 py-3 text-center text-sm font-bold text-white transition hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2">
                    {data.primary_action.label}
                </Link>
            </div>
        </section>
    );
}

function NoCurrentRun({ data }: { data: RouteDeliveryDashboardData }) {
    return (
        <section data-dashboard-widget="routes.my_delivery_run" className="sm:col-span-2 xl:col-span-3 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)] sm:p-6">
            <p className="text-xs font-bold tracking-[0.14em] text-orange-700">MIS ENTREGAS</p>
            <h2 className="mt-2 text-2xl font-bold tracking-tight text-slate-950">No tienes una jornada asignada</h2>
            <p className="mt-2 max-w-xl text-sm text-slate-500">{data.message}</p>
            <Link href={data.primary_action.href} className="mt-5 block rounded-xl bg-orange-600 px-4 py-3 text-center text-sm font-bold text-white transition hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2">
                {data.primary_action.label}
            </Link>
        </section>
    );
}

function MetricTile({ label, value, tone }: { label: string; value: number; tone: 'emerald' | 'amber' | 'slate' }) {
    const color = tone === 'emerald' ? 'text-emerald-700' : tone === 'amber' ? 'text-amber-700' : 'text-slate-950';

    return (
        <div className="rounded-xl bg-slate-50 p-3">
            <p className="text-xs font-medium leading-4 text-slate-500">{label}</p>
            <p className={`mt-2 text-2xl font-bold ${color}`}>{value}</p>
        </div>
    );
}
