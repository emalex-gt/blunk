import SuperAdminLayout from '@/Layouts/SuperAdminLayout';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Incident = {
    id: number;
    business: { id: number; name: string } | null;
    sale_id: number;
    internal_reference: string;
    type: string;
    severity: string;
    status: string;
    message: string;
    created_at: string | null;
};

type Paginated<T> = {
    data: T[];
};

type FiscalIntent = {
    id: number;
    business: { id: number; name: string } | null;
    sale_id: number | null;
    branch_id: number | null;
    internal_reference: string;
    provider: string;
    environment: string;
    status: string;
    negative_reconciliation_count: number;
    last_reconciliation_at: string | null;
    next_reconciliation_at: string | null;
    last_reconciliation_result: Record<string, unknown> | null;
    last_reconciliation_error: string | null;
    last_finalization_error: string | null;
    created_at: string | null;
};

type LegacyReconciliation = {
    id: number;
    business: { id: number; name: string } | null;
    sale_id: number | null;
    internal_reference: string;
    status: string;
    attempts: number;
    last_error: string | null;
    checked_at: string | null;
};

const statusLabels: Record<string, string> = {
    open: 'Abierta',
    reviewed: 'Revisada',
    resolved: 'Alerta cerrada',
};

type ActionFeedback = {
    tone: 'success' | 'warning' | 'error';
    message: string;
};

function formatLocalDateTime(value: string | null): string {
    if (!value) {
        return '-';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

export default function Index({ incidents, intents, reconciliations }: { incidents: Paginated<Incident>; intents: Paginated<FiscalIntent>; reconciliations: Paginated<LegacyReconciliation> }) {
    const [activeIntentAction, setActiveIntentAction] = useState<number | null>(null);
    const [actionFeedback, setActionFeedback] = useState<ActionFeedback | null>(null);

    function markReviewed(id: number) {
        router.post(route('super-admin.fel-incidents.review', id), {}, { preserveScroll: true });
    }

    function resolve(id: number) {
        router.post(route('super-admin.fel-incidents.resolve', id), {}, { preserveScroll: true });
    }

    function reconcileIntent(id: number) {
        if (activeIntentAction !== null) {
            return;
        }

        setActionFeedback(null);
        setActiveIntentAction(id);
        router.post(route('super-admin.fel-incidents.intents.reconcile', id), {}, {
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = page.props.flash as {
                    success?: string | null;
                    warning?: string | null;
                } | undefined;
                const message = flash?.warning ?? flash?.success;

                setActionFeedback({
                    tone: flash?.warning ? 'warning' : 'success',
                    message: message ?? 'La consulta de Digifact terminó. Revisa el estado fiscal actualizado.',
                });
            },
            onError: (errors) => {
                setActionFeedback({
                    tone: 'error',
                    message: Object.values(errors)[0] ?? 'No se pudo consultar Digifact. Inténtalo nuevamente.',
                });
            },
            onFinish: () => setActiveIntentAction(null),
        });
    }

    function retryIntent(id: number) {
        router.post(route('super-admin.fel-incidents.intents.retry', id), {}, { preserveScroll: true });
    }

    function finalizeIntent(id: number) {
        router.post(route('super-admin.fel-incidents.intents.finalize', id), {}, { preserveScroll: true });
    }

    function reconcileLegacy(id: number) {
        router.post(route('super-admin.fel-incidents.reconciliations.check', id), {}, { preserveScroll: true });
    }

    return (
        <SuperAdminLayout title="Incidencias FEL">
            <div className="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4">
                <h2 className="font-semibold text-amber-900">Conciliación fiscal centralizada</h2>
                <p className="mt-1 text-sm text-amber-800">Las intenciones pendientes, desconocidas y reintentables se administran únicamente desde Super Admin.</p>
            </div>
            {actionFeedback && (
                <div className={`mb-6 rounded-xl border p-4 text-sm font-medium ${
                    actionFeedback.tone === 'success'
                        ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                        : actionFeedback.tone === 'warning'
                            ? 'border-amber-200 bg-amber-50 text-amber-900'
                            : 'border-red-200 bg-red-50 text-red-800'
                }`}>
                    {actionFeedback.message}
                </div>
            )}
            <div className="mb-6 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <h2 className="mb-3 text-lg font-semibold text-gray-900">Intenciones fiscales</h2>
                {intents.data.length === 0 ? (
                    <p className="py-6 text-sm text-gray-500">No hay intenciones fiscales pendientes de revisión.</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <thead><tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500"><th className="px-3 py-2">Empresa / sucursal</th><th className="px-3 py-2">Intent / referencia</th><th className="px-3 py-2">Estado fiscal / causa</th><th className="px-3 py-2">Consulta Digifact</th><th className="px-3 py-2 text-right">Acciones</th></tr></thead>
                            <tbody className="divide-y divide-gray-100">
                                {intents.data.map((intent) => (
                                    <tr key={intent.id} className="align-top">
                                        <td className="px-3 py-3 font-semibold text-gray-900">{intent.business?.name ?? '-'}<div className="text-xs font-normal text-gray-500">Sucursal #{intent.branch_id ?? '-'}</div></td>
                                        <td className="px-3 py-3 font-mono text-xs text-gray-700">#{intent.id}<br />{intent.internal_reference}<div className="mt-1 font-sans text-gray-500">Venta final: {intent.sale_id ?? '-'}</div><div className="font-sans text-gray-500">{intent.provider} / {intent.environment}</div></td>
                                        <td className="px-3 py-3"><div className="font-medium text-gray-800">{intent.status}</div><div className="mt-1 max-w-md text-xs text-gray-500">{intent.last_reconciliation_error ?? '-'}</div>{intent.last_finalization_error && <div className="mt-1 max-w-md text-xs text-red-700">Finalización: {intent.last_finalization_error}</div>}</td>
                                        <td className="px-3 py-3 text-xs text-gray-600"><div>Negativas válidas: {intent.negative_reconciliation_count}</div><div>Última: {formatLocalDateTime(intent.last_reconciliation_at)}</div><div>Siguiente: {formatLocalDateTime(intent.next_reconciliation_at)}</div><div className="mt-1 break-all">Resultado: {intent.last_reconciliation_result ? JSON.stringify(intent.last_reconciliation_result) : '-'}</div></td>
                                        <td className="px-3 py-3"><div className="flex justify-end gap-2">{intent.status === 'unknown' && <button type="button" onClick={() => reconcileIntent(intent.id)} disabled={activeIntentAction !== null} className="rounded-md border border-orange-200 px-3 py-1.5 text-xs font-semibold text-orange-700 hover:bg-orange-50 disabled:cursor-not-allowed disabled:opacity-60">{activeIntentAction === intent.id ? 'Consultando Digifact...' : 'Consultar Digifact ahora'}</button>}{intent.status === 'certified' && <button type="button" onClick={() => finalizeIntent(intent.id)} className="rounded-md border border-orange-200 px-3 py-1.5 text-xs font-semibold text-orange-700 hover:bg-orange-50">Reintentar finalización local</button>}{intent.status === 'retryable' && <button type="button" onClick={() => retryIntent(intent.id)} className="rounded-md bg-orange-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-orange-700">Reintentar certificación</button>}</div></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
            {reconciliations.data.length > 0 && (
                <div className="mb-6 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <h2 className="mb-3 text-lg font-semibold text-gray-900">Conciliaciones legacy</h2>
                    <div className="overflow-x-auto"><table className="min-w-full divide-y divide-gray-200 text-sm"><thead><tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500"><th className="px-3 py-2">Empresa</th><th className="px-3 py-2">Referencia</th><th className="px-3 py-2">Estado</th><th className="px-3 py-2">Resultado</th><th className="px-3 py-2" /></tr></thead><tbody className="divide-y divide-gray-100">{reconciliations.data.map((item) => <tr key={item.id}><td className="px-3 py-3">{item.business?.name ?? '-'}</td><td className="px-3 py-3 font-mono text-xs">{item.internal_reference}</td><td className="px-3 py-3">{item.status}</td><td className="px-3 py-3 text-xs text-gray-500">{item.last_error ?? `Consultas: ${item.attempts}`}</td><td className="px-3 py-3 text-right"><button type="button" onClick={() => reconcileLegacy(item.id)} className="rounded-md border border-orange-200 px-3 py-1.5 text-xs font-semibold text-orange-700 hover:bg-orange-50">Consultar Digifact</button></td></tr>)}</tbody></table></div>
                </div>
            )}
            <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                {incidents.data.length === 0 ? (
                    <div className="py-16 text-center">
                        <h2 className="text-xl font-semibold text-gray-900">No hay incidencias FEL</h2>
                        <p className="mt-2 text-sm text-gray-500">Las certificaciones pendientes de revisar aparecerán aquí.</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    <th className="px-4 py-3">Empresa</th>
                                    <th className="px-4 py-3">Venta</th>
                                    <th className="px-4 py-3">Referencia interna</th>
                                    <th className="px-4 py-3">Tipo</th>
                                    <th className="px-4 py-3">Estado</th>
                                    <th className="px-4 py-3">Fecha</th>
                                    <th className="px-4 py-3 text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {incidents.data.map((incident) => (
                                    <tr key={incident.id} className="align-top hover:bg-gray-50">
                                        <td className="px-4 py-3 font-semibold text-gray-900">
                                            {incident.business?.name ?? '-'}
                                            <div className="text-xs font-normal text-gray-500">{incident.message}</div>
                                        </td>
                                        <td className="px-4 py-3 text-gray-600">#{incident.sale_id}</td>
                                        <td className="px-4 py-3 font-mono text-xs text-gray-700">{incident.internal_reference}</td>
                                        <td className="px-4 py-3 text-gray-600">{incident.type}</td>
                                        <td className="px-4 py-3">
                                            <span className="rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-700">
                                                {statusLabels[incident.status] ?? incident.status}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-gray-600">{formatLocalDateTime(incident.created_at)}</td>
                                        <td className="px-4 py-3">
                                            <div className="flex justify-end gap-2">
                                                <Link
                                                    href={route('sales.show', incident.sale_id)}
                                                    className="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-100"
                                                >
                                                    Ver venta
                                                </Link>
                                                {incident.status === 'open' && (
                                                    <button
                                                        type="button"
                                                        onClick={() => markReviewed(incident.id)}
                                                        className="rounded-md border border-orange-200 px-3 py-1.5 text-xs font-semibold text-orange-700 hover:bg-orange-50"
                                                    >
                                                        Marcar revisada
                                                    </button>
                                                )}
                                                {incident.status !== 'resolved' && (
                                                    <button
                                                        type="button"
                                                        onClick={() => resolve(incident.id)}
                                                        className="rounded-md bg-orange-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-orange-700"
                                                    >
                                                        Cerrar alerta administrativa
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </SuperAdminLayout>
    );
}
