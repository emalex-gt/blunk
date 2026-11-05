import SuperAdminLayout from '@/Layouts/SuperAdminLayout';
import { Link, router } from '@inertiajs/react';
import { FormEvent, ReactNode, useMemo, useState } from 'react';

type Filters = {
    date_from: string;
    date_to: string;
    business_id: number | null;
    category: string;
    result: string;
    event: string;
    quotation_id: number | null;
    sale_id: number | null;
    intent_id: number | null;
    user_id: number | null;
    reason_code: string;
    page: number;
};

type Event = {
    timestamp: string;
    event: string;
    result: 'success' | 'warning' | 'error';
    context: Record<string, string | number | boolean | null>;
};

type Result = {
    events: Event[];
    has_more: boolean;
    partial: boolean;
    files_available: boolean;
    logging_enabled: boolean;
    read_error: boolean;
    summary: Record<string, number>;
};

const eventLabels: Record<string, string> = {
    'pos.quotation.loaded': 'Cotización cargada',
    'pos.identity_lookup.verified': 'Identidad fiscal validada',
    'pos.identity_lookup.rejected': 'Identidad fiscal rechazada',
    'pos.identity_lookup.failed': 'Consulta de identidad no disponible',
    'pos.sale.received': 'Venta recibida',
    'pos.sale.rejected': 'Venta rechazada',
    'pos.sale.operation_completed': 'Operación de venta completada',
    'fel.intent.created': 'Intención FEL creada',
    'fel.certification.started': 'Certificación FEL iniciada',
    'fel.certification.certified': 'Certificación FEL confirmada',
    'fel.certification.rejected': 'Certificación FEL rechazada',
    'fel.certification.unknown': 'Certificación FEL incierta',
    'fel.finalization.completed': 'Finalización local completada',
    'fel.finalization.failed': 'Finalización local fallida',
    'fel.reconciliation.not_found': 'Conciliación FEL sin DTE',
};

const resultLabels = { success: 'Correcto', warning: 'Advertencia', error: 'Error' };
const inputClass = 'mt-1 block h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none focus:border-orange-400 focus:ring-4 focus:ring-orange-100';

function localTime(value: string): string {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '-' : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'medium' }).format(date);
}

function resultClass(result: Event['result']): string {
    return result === 'error'
        ? 'bg-red-100 text-red-800'
        : result === 'warning'
            ? 'bg-amber-100 text-amber-800'
            : 'bg-emerald-100 text-emerald-800';
}

export default function Operations({ filters, result, event_names, businesses, max_days }: {
    filters: Filters;
    result: Result;
    event_names: string[];
    businesses: Array<{ id: number; name: string }>;
    max_days: number;
}) {
    const [form, setForm] = useState<Filters>(filters);
    const [selected, setSelected] = useState<Event | null>(null);
    const businessNames = useMemo(() => new Map(businesses.map((business) => [business.id, business.name])), [businesses]);
    const sampleLabel = result.partial ? 'Muestra limitada' : 'Eventos analizados';
    const timeline = useMemo(() => {
        if (!selected?.context.intent_id && !selected?.context.sale_id) return [];
        const key = selected.context.intent_id ? 'intent_id' : 'sale_id';
        const value = selected.context[key];

        return result.events
            .filter((item) => item.context[key] === value)
            .slice()
            .reverse();
    }, [result.events, selected]);

    function query(next: Partial<Filters> = {}) {
        router.get(route('super-admin.system.operations.index'), { ...form, ...next, page: next.page ?? 1 }, { preserveState: true, preserveScroll: true });
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        query();
    }

    function reference(item: Event): string {
        if (item.context.sale_id) return `Venta #${item.context.sale_id}`;
        if (item.context.intent_id) return `Intento FEL #${item.context.intent_id}`;
        if (item.context.quotation_id) return `Cotización #${item.context.quotation_id}`;
        return '-';
    }

    function openTimeline(item: Event) {
        if (item.context.intent_id) query({ intent_id: Number(item.context.intent_id), sale_id: null });
        else if (item.context.sale_id) query({ sale_id: Number(item.context.sale_id), intent_id: null });
    }

    return <SuperAdminLayout title="Diagnóstico de operaciones">
        <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 className="text-2xl font-bold tracking-tight text-slate-950">Diagnóstico de operaciones</h1>
                <p className="mt-1 text-sm text-slate-600">Eventos operativos de POS, cotizaciones, identidad fiscal y FEL. El archivo no determina el estado fiscal actual.</p>
            </div>
            <button type="button" onClick={() => query({ page: filters.page })} className="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Actualizar datos</button>
        </div>

        {!result.logging_enabled && <div className="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><strong>Logging desactivado.</strong> Se muestran únicamente registros históricos que aún existan.</div>}
        {result.read_error && <div className="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900">No se pudo leer uno o más archivos del intervalo. No se exponen rutas ni detalles internos.</div>}
        {result.partial && <div className="mb-5 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900">La consulta alcanzó el límite seguro de lectura. Los indicadores corresponden a la muestra analizada, no a un total global.</div>}

        <div className="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            {[
                [sampleLabel, result.summary.examined_events],
                ['Rechazos POS', result.summary.pos_rejections],
                ['Consultas NIT/CUI fallidas', result.summary.identity_failures],
                ['Certificaciones inciertas', result.summary.fel_unknown],
                ['Fallos de finalización', result.summary.finalization_failures],
            ].map(([label, value]) => <div key={String(label)} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p><p className="mt-2 text-2xl font-bold text-slate-950">{value}</p></div>)}
        </div>

        <form onSubmit={submit} className="mb-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
                <Field label="Desde"><input type="date" value={form.date_from} onChange={(e) => setForm({ ...form, date_from: e.target.value })} className={inputClass} /></Field>
                <Field label="Hasta"><input type="date" value={form.date_to} onChange={(e) => setForm({ ...form, date_to: e.target.value })} className={inputClass} /></Field>
                <Field label="Empresa"><select value={form.business_id ?? ''} onChange={(e) => setForm({ ...form, business_id: e.target.value ? Number(e.target.value) : null })} className={inputClass}><option value="">Todas</option>{businesses.map((business) => <option key={business.id} value={business.id}>{business.name}</option>)}</select></Field>
                <Field label="Categoría"><select value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} className={inputClass}><option value="all">Todas</option><option value="pos">POS</option><option value="quotations">Cotizaciones</option><option value="identity">NIT/CUI</option><option value="fel">FEL</option></select></Field>
                <Field label="Resultado"><select value={form.result} onChange={(e) => setForm({ ...form, result: e.target.value })} className={inputClass}><option value="all">Todos</option><option value="success">Correctos</option><option value="warning">Advertencias</option><option value="error">Errores</option></select></Field>
                <Field label="Evento"><select value={form.event} onChange={(e) => setForm({ ...form, event: e.target.value })} className={inputClass}><option value="">Todos</option>{event_names.map((name) => <option key={name} value={name}>{name}</option>)}</select></Field>
                {(['quotation_id', 'sale_id', 'intent_id', 'user_id'] as const).map((key) => <Field key={key} label={key.replace('_id', ' ID')}><input type="number" min="1" value={form[key] ?? ''} onChange={(e) => setForm({ ...form, [key]: e.target.value ? Number(e.target.value) : null })} className={inputClass} /></Field>)}
                <Field label="Código de razón"><input value={form.reason_code} onChange={(e) => setForm({ ...form, reason_code: e.target.value })} className={inputClass} placeholder="Ej. provider_rejected" /></Field>
            </div>
            <div className="mt-4 flex flex-wrap items-center gap-3"><button className="rounded-xl bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-700">Aplicar filtros</button><button type="button" onClick={() => { const reset = { ...filters, business_id: null, category: 'all', result: 'all', event: '', quotation_id: null, sale_id: null, intent_id: null, user_id: null, reason_code: '', page: 1 }; setForm(reset); router.get(route('super-admin.system.operations.index'), reset); }} className="text-sm font-semibold text-slate-600 hover:text-slate-950">Limpiar</button><span className="text-xs text-slate-500">Máximo {max_days} días y 50 eventos por página.</span></div>
        </form>

        {!result.files_available ? <Empty title="No existen archivos para el intervalo consultado" text="El canal puede estar desactivado, no haber escrito eventos o ya haberse aplicado la retención." /> : result.events.length === 0 ? <Empty title="No hay eventos con estos filtros" text="No se encontraron registros legibles dentro de la ventana de lectura." /> : <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
            <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div className="overflow-x-auto"><table className="min-w-full text-sm"><thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500"><tr><th className="px-4 py-3">Fecha y hora</th><th className="px-4 py-3">Empresa</th><th className="px-4 py-3">Evento</th><th className="px-4 py-3">Referencia</th><th className="px-4 py-3">Resultado</th></tr></thead><tbody className="divide-y divide-slate-100">{result.events.map((item, index) => <tr key={`${item.timestamp}-${item.event}-${index}`} onClick={() => setSelected(item)} className="cursor-pointer hover:bg-orange-50"><td className="whitespace-nowrap px-4 py-3 text-slate-600">{localTime(item.timestamp)}</td><td className="px-4 py-3 font-medium text-slate-800">{businessNames.get(Number(item.context.business_id)) ?? `Empresa #${item.context.business_id ?? '-'}`}</td><td className="px-4 py-3"><div className="font-medium text-slate-900">{eventLabels[item.event] ?? item.event}</div><div className="font-mono text-xs text-slate-500">{item.event}</div></td><td className="px-4 py-3 text-slate-700">{reference(item)}</td><td className="px-4 py-3"><span className={`rounded-full px-2 py-1 text-xs font-semibold ${resultClass(item.result)}`}>{resultLabels[item.result]}</span></td></tr>)}</tbody></table></div><div className="flex items-center justify-between border-t border-slate-200 p-3"><button disabled={filters.page <= 1} onClick={() => query({ page: filters.page - 1 })} className="rounded-lg border px-3 py-1.5 text-sm disabled:opacity-40">Anterior</button><span className="text-xs text-slate-500">Página {filters.page}</span><button disabled={!result.has_more} onClick={() => query({ page: filters.page + 1 })} className="rounded-lg border px-3 py-1.5 text-sm disabled:opacity-40">Siguiente</button></div></section>
            <aside className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 className="text-lg font-bold text-slate-950">Detalle de evento</h2>{selected ? <div className="mt-4 space-y-3 text-sm"><Detail label="Fecha" value={localTime(selected.timestamp)} /><Detail label="Código" value={selected.event} /><Detail label="Empresa" value={businessNames.get(Number(selected.context.business_id)) ?? `#${selected.context.business_id ?? '-'}`} /><Detail label="Sucursal" value={selected.context.branch_id ? `#${selected.context.branch_id}` : '-'} /><Detail label="Usuario" value={selected.context.user_id ? `#${selected.context.user_id}` : '-'} /><Detail label="Estado" value={String(selected.context.status ?? selected.context.outcome ?? '-')} /><Detail label="Razón" value={String(selected.context.reason_code ?? '-')} /><Detail label="Duración" value={selected.context.duration_ms ? `${selected.context.duration_ms} ms` : '-'} /><div className="border-t pt-3"><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Contexto técnico seguro</p><pre className="mt-2 overflow-x-auto rounded-lg bg-slate-950 p-3 text-xs text-slate-100">{JSON.stringify(selected.context, null, 2)}</pre></div>{(selected.context.intent_id || selected.context.sale_id) && <><div className="flex flex-wrap gap-2"><button type="button" onClick={() => openTimeline(selected)} className="rounded-lg border border-orange-200 px-3 py-2 text-xs font-semibold text-orange-700 hover:bg-orange-50">Ver cronología</button>{selected.context.intent_id && <Link href={route('super-admin.fel-incidents.index')} className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Abrir Incidencias FEL</Link>}</div>{timeline.length > 0 && <div className="border-t pt-3"><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Cronología disponible</p><ol className="mt-2 space-y-2 border-l border-slate-200 pl-3">{timeline.map((item, index) => <li key={`${item.timestamp}-${index}`} className="text-xs text-slate-600"><span className="font-semibold text-slate-800">{eventLabels[item.event] ?? item.event}</span><br />{localTime(item.timestamp)}</li>)}</ol></div>}</>}</div> : <p className="mt-4 text-sm text-slate-500">Selecciona un evento para ver únicamente su contexto seguro.</p>}</aside>
        </div>}
    </SuperAdminLayout>;
}

function Field({ label, children }: { label: string; children: ReactNode }) { return <label className="block text-xs font-semibold text-slate-600">{label}{children}</label>; }
function Detail({ label, value }: { label: string; value: string }) { return <div><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p><p className="mt-1 break-words text-slate-800">{value}</p></div>; }
function Empty({ title, text }: { title: string; text: string }) { return <div className="rounded-2xl border border-slate-200 bg-white px-6 py-16 text-center shadow-sm"><h2 className="text-lg font-bold text-slate-950">{title}</h2><p className="mx-auto mt-2 max-w-lg text-sm text-slate-600">{text}</p></div>; }
