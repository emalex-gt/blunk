import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useMemo, useState } from 'react';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout';

type DeployStatus = {
    environment: string;
    branch: string;
    working_tree: string;
    is_clean: boolean;
    local_commit: string;
    local_commit_subject: string;
    remote_commit: string;
    remote_commit_subject: string;
    has_pending_updates: boolean;
};

type DeployRun = {
    id: number;
    status: string;
    branch: string;
    user: { id: number; name: string } | null;
    started_at: string | null;
    finished_at: string | null;
    created_at: string | null;
    local_commit_before: string | null;
    remote_commit_target: string | null;
    local_commit_after: string | null;
    exit_code: number | null;
    error_message: string | null;
    show_url: string;
    log_url: string | null;
};

type Props = {
    status: DeployStatus | null;
    statusError: string | null;
    queueConnection: string;
    queueWorkerEnabled: boolean;
    activeDeploy: DeployRun | null;
    history: DeployRun[];
    selectedRun: DeployRun | null;
};

const statusClass: Record<string, string> = {
    pending: 'bg-amber-50 text-amber-700',
    running: 'bg-blue-50 text-blue-700',
    succeeded: 'bg-emerald-50 text-emerald-700',
    failed: 'bg-rose-50 text-rose-700',
    cancelled: 'bg-slate-100 text-slate-700',
};

function Commit({ label, value, subject }: { label: string; value: string | null; subject?: string | null }) {
    return (
        <div className="border-b border-slate-100 py-3 last:border-b-0">
            <dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</dt>
            <dd className="mt-1 break-all font-mono text-sm text-slate-900">{value || '-'}</dd>
            {subject ? <dd className="mt-1 text-sm text-slate-600">{subject}</dd> : null}
        </div>
    );
}

export default function Deploy({ status, statusError, queueConnection, queueWorkerEnabled, activeDeploy, history, selectedRun }: Props) {
    const [showConfirmation, setShowConfirmation] = useState(false);
    const form = useForm({ confirmation: '' });
    const canDeploy = useMemo(
        () => Boolean(queueWorkerEnabled && status?.branch === 'main' && status.is_clean && !activeDeploy),
        [activeDeploy, queueWorkerEnabled, status],
    );
    const deployError = (form.errors as Record<string, string | undefined>).deploy;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('super-admin.system.deploy.run'), {
            preserveScroll: true,
            onSuccess: () => {
                setShowConfirmation(false);
                form.reset();
            },
        });
    };

    return (
        <SuperAdminLayout
            title="Actualizaciones"
            actions={
                <button
                    type="button"
                    onClick={() => router.post(route('super-admin.system.deploy.check'), {}, { preserveScroll: true })}
                    className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                >
                    Buscar actualizaciones
                </button>
            }
        >
            <Head title="Actualizaciones" />

            <div className="space-y-5">
                <section className="border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h1 className="text-xl font-bold text-slate-950">Actualizaciones de producción</h1>
                            <p className="mt-1 text-sm text-slate-600">La ejecución solo está disponible mediante la cola interna de despliegues.</p>
                        </div>
                        <button
                            type="button"
                            disabled={!canDeploy}
                            onClick={() => setShowConfirmation(true)}
                            className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-300"
                        >
                            Actualizar producción
                        </button>
                    </div>

                    {!queueWorkerEnabled ? (
                        <div className="mt-4 border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                            El deploy está bloqueado. Configura y verifica Supervisor para la cola <code>deploys</code> antes de habilitar <code>DEPLOY_QUEUE_WORKER_ENABLED</code>.
                        </div>
                    ) : null}
                    {activeDeploy ? (
                        <div className="mt-4 border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900">
                            Hay un deploy {activeDeploy.status} desde {activeDeploy.created_at || 'fecha no disponible'}.
                        </div>
                    ) : null}
                    {statusError ? <div className="mt-4 border border-rose-200 bg-rose-50 p-3 text-sm text-rose-900">{statusError}</div> : null}
                    {!status?.is_clean ? <div className="mt-4 border border-rose-200 bg-rose-50 p-3 text-sm text-rose-900">El working tree está sucio. Corrige los cambios locales antes de desplegar.</div> : null}
                    {status && status.branch !== 'main' ? <div className="mt-4 border border-rose-200 bg-rose-50 p-3 text-sm text-rose-900">El checkout actual no está en la rama main.</div> : null}
                </section>

                <section className="grid gap-4 lg:grid-cols-3">
                    <div className="border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="text-sm font-bold text-slate-900">Ejecución</h2>
                        <dl className="mt-3">
                            <Commit label="Ambiente" value={status?.environment || '-'} />
                            <Commit label="QUEUE_CONNECTION" value={queueConnection} />
                            <Commit label="DEPLOY_QUEUE_WORKER_ENABLED" value={queueWorkerEnabled ? 'true' : 'false'} />
                        </dl>
                    </div>
                    <div className="border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="text-sm font-bold text-slate-900">Checkout actual</h2>
                        <dl className="mt-3">
                            <Commit label="Rama" value={status?.branch || '-'} />
                            <Commit label="Working tree" value={status ? (status.is_clean ? 'Limpio' : 'Sucio') : '-'} />
                            <Commit label="Commit local" value={status?.local_commit || null} subject={status?.local_commit_subject} />
                        </dl>
                    </div>
                    <div className="border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="text-sm font-bold text-slate-900">Origen remoto</h2>
                        <dl className="mt-3">
                            <Commit label="origin/main" value={status?.remote_commit || null} subject={status?.remote_commit_subject} />
                            <Commit label="Actualizaciones pendientes" value={status ? (status.has_pending_updates ? 'Sí' : 'No') : '-'} />
                        </dl>
                    </div>
                </section>

                <section className="border border-slate-200 bg-white shadow-sm">
                    <div className="border-b border-slate-200 px-5 py-4">
                        <h2 className="font-bold text-slate-900">Historial de deploys</h2>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-[900px] w-full text-left text-sm">
                            <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-4 py-3">Fecha</th>
                                    <th className="px-4 py-3">Usuario</th>
                                    <th className="px-4 py-3">Antes / objetivo / después</th>
                                    <th className="px-4 py-3">Estado</th>
                                    <th className="px-4 py-3">Salida</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {history.length === 0 ? (
                                    <tr><td className="px-4 py-6 text-slate-500" colSpan={5}>No hay deploys registrados.</td></tr>
                                ) : history.map((run) => (
                                    <tr key={run.id}>
                                        <td className="px-4 py-3 text-slate-600">{run.created_at}</td>
                                        <td className="px-4 py-3 text-slate-700">{run.user?.name || '-'}</td>
                                        <td className="px-4 py-3 font-mono text-xs text-slate-600">{run.local_commit_before || '-'} / {run.remote_commit_target || '-'} / {run.local_commit_after || '-'}</td>
                                        <td className="px-4 py-3"><span className={`rounded-full px-2 py-1 text-xs font-semibold ${statusClass[run.status] || statusClass.cancelled}`}>{run.status}</span></td>
                                        <td className="px-4 py-3"><div className="flex gap-3"><Link href={run.show_url} className="font-semibold text-indigo-700">Ver</Link>{run.log_url ? <a href={run.log_url} className="font-semibold text-indigo-700" target="_blank" rel="noreferrer">Log</a> : null}</div></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                {selectedRun ? (
                    <section className="border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="font-bold text-slate-900">Deploy #{selectedRun.id}</h2>
                        {selectedRun.error_message ? <p className="mt-3 whitespace-pre-wrap font-mono text-sm text-rose-700">{selectedRun.error_message}</p> : <p className="mt-3 text-sm text-slate-600">Sin error registrado.</p>}
                        {selectedRun.log_url ? <a href={selectedRun.log_url} target="_blank" rel="noreferrer" className="mt-3 inline-block font-semibold text-indigo-700">Abrir log completo</a> : null}
                    </section>
                ) : null}
            </div>

            {showConfirmation ? (
                <div className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-950/45 p-4">
                    <form onSubmit={submit} className="w-full max-w-md border border-slate-200 bg-white p-6 shadow-xl">
                        <h2 className="text-lg font-bold text-slate-950">Confirmar actualización</h2>
                        <p className="mt-2 text-sm text-slate-600">Esta acción actualizará producción, ejecutará migraciones y reconstruirá assets. Úsala solo cuando el negocio no esté usando el sistema.</p>
                        <label className="mt-4 block text-sm font-semibold text-slate-700">Escribe ACTUALIZAR</label>
                        <input value={form.data.confirmation} onChange={(event) => form.setData('confirmation', event.target.value)} className="mt-1 w-full border border-slate-300 px-3 py-2" autoFocus />
                        {form.errors.confirmation || deployError ? <p className="mt-2 text-sm text-rose-700">{form.errors.confirmation || deployError}</p> : null}
                        <div className="mt-5 flex justify-end gap-3"><button type="button" onClick={() => setShowConfirmation(false)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700">Cancelar</button><button type="submit" disabled={form.processing || form.data.confirmation !== 'ACTUALIZAR'} className="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white disabled:bg-slate-300">Actualizar</button></div>
                    </form>
                </div>
            ) : null}
        </SuperAdminLayout>
    );
}
