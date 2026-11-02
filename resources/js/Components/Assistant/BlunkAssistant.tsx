import type { AssistantPricingAlert } from '@/lib/assistantPricing';
import { useEffect, useMemo, useRef, useState } from 'react';

type Props = {
    alerts: AssistantPricingAlert[];
};

function alertIdentity(alert: AssistantPricingAlert): string {
    return `${alert.productId}:${alert.label}:${alert.kind}`;
}

export default function BlunkAssistant({ alerts }: Props) {
    const [open, setOpen] = useState(false);
    const [announcement, setAnnouncement] = useState('');
    const seenAlertIdentities = useRef<Set<string>>(new Set());
    const groupedAlerts = useMemo(() => {
        const groups = new Map<string, AssistantPricingAlert[]>();

        alerts.forEach((alert) => {
            const group = groups.get(String(alert.productId)) ?? [];
            group.push(alert);
            groups.set(String(alert.productId), group);
        });

        return [...groups.values()];
    }, [alerts]);

    useEffect(() => {
        const alertIdentities = new Set(alerts.map(alertIdentity));
        const newAlerts = alerts.filter((alert) => !seenAlertIdentities.current.has(alertIdentity(alert)));

        if (newAlerts.length > 0) {
            setOpen(true);
            setAnnouncement(newAlerts.length === 1
                ? `Nueva sugerencia: ${newAlerts[0].label} para ${newAlerts[0].productName}.`
                : `Hay ${newAlerts.length} sugerencias nuevas del Asistente Blunk.`);
        } else if (alerts.length === 0) {
            setAnnouncement('');
        }

        seenAlertIdentities.current = alertIdentities;
    }, [alerts]);

    return (
        <aside className="fixed bottom-4 right-4 z-[70] sm:bottom-5 sm:right-5" aria-label="Asistente Blunk">
            <span className="sr-only" role="status" aria-live="polite">{announcement}</span>
            {open && (
                <section className="mb-3 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl">
                    <header className="flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-3">
                        <div><h2 className="font-semibold text-slate-950">Asistente Blunk</h2><p className="mt-0.5 text-xs text-slate-500">Sugerencias y avisos para ayudarte mientras trabajas.</p></div>
                        <button type="button" onClick={() => setOpen(false)} className="rounded p-1 text-slate-500 hover:bg-slate-100 hover:text-slate-800" aria-label="Cerrar asistente">×</button>
                    </header>
                    <div className="max-h-72 space-y-3 overflow-y-auto p-4">
                        {groupedAlerts.length === 0 ? <p className="text-sm text-slate-600">¡Todo al día! No tengo sugerencias para mostrarte en este momento.</p> : groupedAlerts.map((group) => <section key={String(group[0].productId)} className="rounded-xl border border-slate-100 bg-slate-50 p-3"><h3 className="text-sm font-semibold text-slate-900">{group[0].productName}</h3><ul className="mt-2 space-y-2">{group.map((alert) => <li key={alert.id} className={`rounded-lg border px-2.5 py-2 text-xs leading-5 ${alert.severity === 'critical' ? 'border-rose-200 bg-rose-50 text-rose-900' : 'border-amber-200 bg-amber-50 text-amber-900'}`}><span className="font-semibold">{alert.label}:</span> {alert.message}</li>)}</ul></section>)}
                    </div>
                </section>
            )}
            <button type="button" onClick={() => setOpen((value) => !value)} aria-expanded={open} aria-label={open ? 'Cerrar asistente' : 'Abrir asistente'} className="flex h-12 w-12 items-center justify-center rounded-full bg-slate-900 text-lg text-white shadow-lg hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2" title={open ? 'Cerrar asistente' : 'Abrir asistente'}>
                <span aria-hidden="true">✦</span><span className="sr-only">{open ? 'Cerrar asistente' : 'Abrir asistente'}</span>
            </button>
        </aside>
    );
}
