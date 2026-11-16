import { Link } from '@inertiajs/react';

export type Choice = { id: number; name: string };
export type Options = Record<string, Record<string, string>>;
export type Task = {
    id: number; code: string; title: string; priority: string; work_status: string;
    implementation_status: string; production_status: string; task_type: string; module: string;
    client_name: string | null; business_id: number | null; assigned_to: number | null;
    assignee?: Choice | null; sort_order: number; version: number; archived_at: string | null;
    description: string | null; acceptance_criteria: string | null; technical_notes: string | null;
    blocked_reason: string | null; github_branch: string | null; github_commit: string | null; github_url: string | null;
    source_reference: string | null; source_key: string | null;
    completed_at: string | null; deployed_at: string | null; production_verified_at: string | null;
    checklist: { id?: number; title: string; is_completed: boolean }[];
};
export type Page<T> = { data: T[]; total: number; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null };
export type Common = { options: Options; users: Choice[]; businesses: Choice[]; clients: string[] };
export const base = 'super-admin.development.tasks.';
export const input = 'mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-orange-500 focus:ring-orange-500';
export const button = 'inline-flex min-h-11 items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50';
export const labels: Record<string, string> = { title: 'Título', priority: 'Prioridad', work_status: 'Trabajo', implementation_status: 'Implementación', production_status: 'Producción', task_type: 'Tipo', module: 'Módulo', client_name: 'Cliente', assigned_to: 'Responsable', business_id: 'Tenant vinculado', sort_order: 'Orden manual', description: 'Descripción', acceptance_criteria: 'Criterios de aceptación', technical_notes: 'Notas técnicas y observaciones', blocked_reason: 'Motivo del bloqueo', github_branch: 'Rama GitHub', github_commit: 'Commit', github_url: 'Enlace GitHub' };
export function Pagination({ page }: { page: Page<unknown> }) {
    return <div className="mt-4 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-600"><span>{page.total} resultados · {page.current_page}/{page.last_page}</span><div className="flex gap-3">{page.prev_page_url && <Link preserveScroll href={page.prev_page_url}>← Anterior</Link>}{page.next_page_url && <Link preserveScroll href={page.next_page_url}>Siguiente →</Link>}</div></div>;
}
export function Card({ task, options }: { task: Task; options: Options }) {
    return <Link href={route(base + 'show', task.id)} className="block min-w-0 rounded-xl border border-slate-200 bg-white p-4 shadow-sm hover:border-orange-400"><div className="flex justify-between gap-2 text-xs"><span className="font-mono text-slate-500">{task.code}</span><span className={`rounded px-2 py-1 font-bold ${task.priority === 'P0' ? 'bg-red-100 text-red-800' : task.priority === 'P1' ? 'bg-orange-100 text-orange-800' : 'bg-slate-100'}`}>{task.priority}</span></div><h3 className="mt-2 break-words font-semibold">{task.title}</h3><p className="mt-2 text-xs text-slate-500">{task.client_name || 'Blunk Core / General'} · {task.module}</p><div className="mt-3 flex flex-wrap gap-2 text-xs">{['work_status', 'implementation_status', 'production_status'].map(key => <span key={key} className="rounded bg-slate-100 px-2 py-1">{labels[key]}: {options[key][String(task[key as keyof Task])]}</span>)}</div><p className="mt-3 text-xs text-slate-500">{task.assignee?.name || 'Sin responsable'} · Orden {task.sort_order}{task.archived_at ? ' · Archivada' : ''}</p></Link>;
}
