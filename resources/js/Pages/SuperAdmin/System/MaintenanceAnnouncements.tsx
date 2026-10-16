import SuperAdminLayout from '@/Layouts/SuperAdminLayout';
import { FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';

type Announcement = {
    id: number;
    title: string;
    message: string;
    visible_from: string;
    starts_at: string;
    ends_at: string;
    expected_downtime: string | null;
    enabled: boolean;
    status: 'scheduled' | 'active' | 'expired' | 'disabled';
    created_by: string | null;
    updated_by: string | null;
    updated_at: string | null;
};

const statusLabel: Record<Announcement['status'], string> = {
    scheduled: 'Programado',
    active: 'Activo',
    expired: 'Expirado',
    disabled: 'Deshabilitado',
};

export default function MaintenanceAnnouncements({ announcement, history }: { announcement: Announcement | null; history: Announcement[] }) {
    const form = useForm({
        title: announcement?.title ?? '',
        message: announcement?.message ?? '',
        visible_from: announcement?.visible_from ?? '',
        starts_at: announcement?.starts_at ?? '',
        ends_at: announcement?.ends_at ?? '',
        expected_downtime: announcement?.expected_downtime ?? '',
        enabled: announcement?.enabled ?? true,
    });
    const editing = Boolean(announcement);
    const errors = form.errors as Record<string, string | undefined>;

    function submit(event: FormEvent) {
        event.preventDefault();

        if (announcement) {
            form.put(route('super-admin.system.maintenance-announcements.update', announcement.id));
            return;
        }

        form.post(route('super-admin.system.maintenance-announcements.store'));
    }

    return (
        <SuperAdminLayout title="Mantenimiento programado">
            <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(20rem,0.7fr)]">
                <form onSubmit={submit} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="mb-5">
                        <h1 className="text-xl font-bold text-slate-950">{editing ? 'Actualizar anuncio global' : 'Crear anuncio global'}</h1>
                        <p className="mt-1 text-sm text-slate-500">Todos los horarios se administran en UTC. El banner se muestra desde la fecha de visibilidad hasta la finalización.</p>
                    </div>

                    {errors.announcement && <p className="mb-4 rounded-xl bg-red-50 px-3 py-2 text-sm font-medium text-red-700">{errors.announcement}</p>}
                    <div className="grid gap-4 md:grid-cols-2">
                        <label className="text-sm font-semibold text-slate-700 md:col-span-2">
                            Título
                            <input value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} className="mt-1 w-full rounded-xl border-slate-200 text-sm" maxLength={160} />
                            {form.errors.title && <span className="mt-1 block text-xs text-red-600">{form.errors.title}</span>}
                        </label>
                        <label className="text-sm font-semibold text-slate-700 md:col-span-2">
                            Mensaje
                            <textarea value={form.data.message} onChange={(event) => form.setData('message', event.target.value)} className="mt-1 w-full rounded-xl border-slate-200 text-sm" rows={4} maxLength={2000} />
                            {form.errors.message && <span className="mt-1 block text-xs text-red-600">{form.errors.message}</span>}
                        </label>
                        <label className="text-sm font-semibold text-slate-700">
                            Visible desde (UTC)
                            <input type="datetime-local" value={form.data.visible_from} onChange={(event) => form.setData('visible_from', event.target.value)} className="mt-1 w-full rounded-xl border-slate-200 text-sm" />
                            {form.errors.visible_from && <span className="mt-1 block text-xs text-red-600">{form.errors.visible_from}</span>}
                        </label>
                        <label className="text-sm font-semibold text-slate-700">
                            Inicio de mantenimiento (UTC)
                            <input type="datetime-local" value={form.data.starts_at} onChange={(event) => form.setData('starts_at', event.target.value)} className="mt-1 w-full rounded-xl border-slate-200 text-sm" />
                            {form.errors.starts_at && <span className="mt-1 block text-xs text-red-600">{form.errors.starts_at}</span>}
                        </label>
                        <label className="text-sm font-semibold text-slate-700">
                            Finalización (UTC)
                            <input type="datetime-local" value={form.data.ends_at} onChange={(event) => form.setData('ends_at', event.target.value)} className="mt-1 w-full rounded-xl border-slate-200 text-sm" />
                            {form.errors.ends_at && <span className="mt-1 block text-xs text-red-600">{form.errors.ends_at}</span>}
                        </label>
                        <label className="text-sm font-semibold text-slate-700">
                            Indisponibilidad estimada (opcional)
                            <input value={form.data.expected_downtime} onChange={(event) => form.setData('expected_downtime', event.target.value)} className="mt-1 w-full rounded-xl border-slate-200 text-sm" maxLength={160} />
                            {form.errors.expected_downtime && <span className="mt-1 block text-xs text-red-600">{form.errors.expected_downtime}</span>}
                        </label>
                        <label className="flex items-center gap-2 text-sm font-semibold text-slate-700 md:col-span-2">
                            <input type="checkbox" checked={form.data.enabled} onChange={(event) => form.setData('enabled', event.target.checked)} className="rounded border-slate-300 text-orange-600 focus:ring-orange-500" />
                            Habilitar anuncio
                        </label>
                    </div>
                    <div className="mt-5 flex flex-wrap justify-end gap-2">
                        {announcement && (
                            <button type="button" onClick={() => router.delete(route('super-admin.system.maintenance-announcements.destroy', announcement.id))} className="rounded-xl border border-red-200 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">
                                Cancelar anuncio
                            </button>
                        )}
                        <button disabled={form.processing} className="rounded-xl bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-700 disabled:opacity-60">
                            {form.processing ? 'Guardando...' : editing ? 'Guardar cambios' : 'Crear anuncio'}
                        </button>
                    </div>
                </form>

                <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 className="text-lg font-bold text-slate-950">Historial reciente</h2>
                    <div className="mt-4 space-y-3">
                        {history.length === 0 ? (
                            <p className="text-sm text-slate-500">No hay anuncios registrados.</p>
                        ) : history.map((item) => (
                            <article key={item.id} className="rounded-xl border border-slate-100 p-3">
                                <div className="flex items-center justify-between gap-3">
                                    <strong className="text-sm text-slate-900">{item.title}</strong>
                                    <span className="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">{statusLabel[item.status]}</span>
                                </div>
                                <p className="mt-1 text-xs text-slate-500">UTC: {item.starts_at} — {item.ends_at}</p>
                                {item.updated_at && <p className="mt-1 text-xs text-slate-400">Actualizado: {item.updated_at} UTC{item.updated_by ? ` por ${item.updated_by}` : ''}</p>}
                            </article>
                        ))}
                    </div>
                </section>
            </div>
        </SuperAdminLayout>
    );
}
