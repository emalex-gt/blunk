import SuperAdminLayout from '@/Layouts/SuperAdminLayout';
import { router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Permission = {
    id: number;
    key: string;
    name: string;
    group: string | null;
    description: string | null;
    is_system: boolean;
    roles_count: number;
    users_count: number;
};

export default function Permissions({ permissions }: { permissions: Permission[] }) {
    const [editing, setEditing] = useState<Permission | null>(null);
    const form = useForm({ key: '', name: '', group: '', description: '' });

    function edit(permission: Permission) {
        setEditing(permission);
        form.setData({
            key: permission.key,
            name: permission.name,
            group: permission.group ?? '',
            description: permission.description ?? '',
        });
    }

    function resetForm() {
        setEditing(null);
        form.reset();
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        if (editing) {
            form.put(route('super-admin.security.permissions.update', editing.id), {
                preserveScroll: true,
                onSuccess: resetForm,
            });
            return;
        }

        form.post(route('super-admin.security.permissions.store'), {
            preserveScroll: true,
            onSuccess: resetForm,
        });
    }

    return (
        <SuperAdminLayout title="Permisos">
            <div className="grid gap-6 lg:grid-cols-[380px_1fr]">
                <form onSubmit={submit} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h2 className="text-lg font-semibold text-slate-900">{editing ? 'Editar permiso' : 'Crear permiso'}</h2>
                    <div className="mt-4 grid gap-3">
                        <input className={inputClass} placeholder="Clave" value={form.data.key} disabled={Boolean(editing?.is_system)} onChange={(event) => form.setData('key', event.target.value)} />
                        <input className={inputClass} placeholder="Nombre" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
                        <input className={inputClass} placeholder="Grupo" value={form.data.group} onChange={(event) => form.setData('group', event.target.value)} />
                        <textarea className={inputClass} placeholder="Descripción" value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
                    </div>
                    <div className="mt-4 flex gap-2">
                        <button className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" disabled={form.processing}>
                            Guardar
                        </button>
                        {editing && (
                            <button type="button" className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" onClick={resetForm}>
                                Cancelar
                            </button>
                        )}
                    </div>
                </form>

                <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div className="mb-4 flex gap-2">
                        <a className="rounded-lg bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-700" href={route('super-admin.security.roles')}>Roles</a>
                        <a className="rounded-lg bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-700" href={route('super-admin.security.assignments')}>Asignaciones</a>
                    </div>
                    <table className="min-w-full text-sm">
                        <thead className="text-left text-xs uppercase text-slate-500">
                            <tr><th className="py-2">Grupo</th><th>Permiso</th><th>Clave</th><th>Uso</th><th className="text-right">Acciones</th></tr>
                        </thead>
                        <tbody>
                            {permissions.map((permission) => (
                                <tr key={permission.id} className="border-t border-slate-100">
                                    <td className="py-3 text-slate-500">{permission.group ?? 'Otros'}</td>
                                    <td className="py-3 font-semibold text-slate-900">{permission.name}{permission.is_system ? <span className="ml-2 text-xs text-slate-400">Sistema</span> : null}</td>
                                    <td className="py-3"><code className="text-xs text-slate-500">{permission.key}</code></td>
                                    <td className="py-3 text-slate-500">{permission.roles_count + permission.users_count}</td>
                                    <td className="py-3 text-right">
                                        <button className="mr-2 rounded-lg border px-3 py-1 text-xs font-semibold" onClick={() => edit(permission)}>Editar</button>
                                        {!permission.is_system && (
                                            <button className="rounded-lg bg-red-600 px-3 py-1 text-xs font-semibold text-white" onClick={() => router.delete(route('super-admin.security.permissions.destroy', permission.id))}>
                                                Eliminar
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>
            </div>
        </SuperAdminLayout>
    );
}

const inputClass = 'w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
