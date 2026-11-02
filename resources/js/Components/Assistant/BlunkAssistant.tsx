import type { AssistantPricingAlert } from '@/lib/assistantPricing';
import { useMemo, useState } from 'react';

type Props = {
    alerts: AssistantPricingAlert[];
    positionClassName?: string;
};

export default function BlunkAssistant({ alerts, positionClassName = 'bottom-5 right-5' }: Props) {
    const [open, setOpen] = useState(false);
    const groupedAlerts = useMemo(() => {
        const groups = new Map<string, AssistantPricingAlert[]>();

        alerts.forEach((alert) => {
            const group = groups.get(String(alert.productId)) ?? [];
            group.push(alert);
            groups.set(String(alert.productId), group);
        });

        return [...groups.values()];
    }, [alerts]);
    const criticalCount = alerts.filter((alert) => alert.severity === 'critical').length;

    return (
        <aside className={`fixed z-[70] ${positionClassName}`} aria-label="Asistente inteligente">
            {open && (
                <section className="mb-3 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl" aria-live="polite">
                    <header className="flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-3">
                        <div><h2 className="font-semibold text-slate-950">Asistente Blunk</h2><p className="mt-0.5 text-xs text-slate-500">Revisa precios frente al costo registrado.</p></div>
                        <button type="button" onClick={() => setOpen(false)} className="rounded p-1 text-slate-500 hover:bg-slate-100 hover:text-slate-800" aria-label="Cerrar asistente">×</button>
                    </header>
                    <div className="max-h-72 space-y-3 overflow-y-auto p-4">
                        {groupedAlerts.length === 0 ? <p className="text-sm text-slate-600">No se detectaron anomalías con los datos disponibles.</p> : groupedAlerts.map((group) => <section key={String(group[0].productId)} className="rounded-xl border border-slate-100 bg-slate-50 p-3"><h3 className="text-sm font-semibold text-slate-900">{group[0].productName}</h3><ul className="mt-2 space-y-2">{group.map((alert) => <li key={alert.id} className={`rounded-lg border px-2.5 py-2 text-xs leading-5 ${alert.severity === 'critical' ? 'border-rose-200 bg-rose-50 text-rose-900' : 'border-amber-200 bg-amber-50 text-amber-900'}`}><span className="font-semibold">{alert.label}:</span> {alert.message}</li>)}</ul></section>)}
                    </div>
                </section>
            )}
            <button type="button" onClick={() => setOpen((value) => !value)} aria-expanded={open} className="relative flex h-12 w-12 items-center justify-center rounded-full bg-slate-900 text-lg text-white shadow-lg hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2" title="Abrir asistente">
                <span aria-hidden="true">✦</span><span className="sr-only">Abrir asistente inteligente</span>
                {alerts.length > 0 && <span className={`absolute -right-1 -top-1 flex min-h-5 min-w-5 items-center justify-center rounded-full px-1 text-[10px] font-bold ${criticalCount > 0 ? 'bg-rose-600 text-white' : 'bg-amber-400 text-slate-950'}`}>{alerts.length}</span>}
            </button>
        </aside>
    );
}
