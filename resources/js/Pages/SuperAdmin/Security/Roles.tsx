import SuperAdminLayout from '@/Layouts/SuperAdminLayout';
import { router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Business = { id: number; name: string };
type Permission = { id: number; key: string; name: string; group: string | null };
type Role = {
    id: number;
    business_id: number | null;
    key: string;
    name: string;
    is_system: boolean;
    is_active: boolean;
    business: Business | null;
    permissions: Permission[];
};

export default function Roles({ roles, permissions, businesses }: { roles: Role[]; permissions: Permission[]; businesses: Business[] }) {
    const [editing, setEditing] = useState<Role | null>(null);
    const form = useForm({
        scope: 'global',
        business_id: '',
        key: '',
        name: '',
        is_active: true,
        permissions: [] as string[],
    });
    const groups = permissions.reduce<Record<string, Permission[]>>((carry, permission) => {
        const group = permission.group ?? 'Otros';
        carry[group] = [...(carry[group] ?? []), permission];
        return carry;
    }, {});

    function edit(role: Role) {
        setEditing(role);
        form.setData({
            scope: role.business_id ? 'tenant' : 'global',
            business_id: role.business_id ? String(role.business_id) : '',
            key: role.key,
            name: role.name,
            is_active: role.is_active,
            permissions: role.permissions.map((permission) => permission.key),
        });
    }

    function resetForm() {
        setEditing(null);
        form.reset();
        form.setData('scope', 'global');
        form.setData('business_id', '');
        form.setData('is_active', true);
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        if (editing) {
            form.put(route('super-admin.security.roles.update', editing.id), {
                preserveScroll: true,
                onSuccess: resetForm,
            });
            return;
        }

        form.post(route('super-admin.security.roles.store'), {
            preserveScroll: true,
            onSuccess: resetForm,
        });
    }

    function togglePermission(key: string) {
        form.setData('permissions', form.data.permissions.includes(key)
            ? form.data.permissions.filter((permission) => permission !== key)
            : [...form.data.permissions, key]);
    }

    return (
        <SuperAdminLayout title="Roles">
            <div className="grid gap-6 lg:grid-cols-[440px_1fr]">
                <form onSubmit={submit} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h2 className="text-lg font-semibold text-slate-900">{editing ? 'Editar rol' : 'Crear rol'}</h2>
                    <div className="mt-4 grid gap-3">
                        <select
                            className={inputClass}
                            value={form.data.scope}
                            disabled={Boolean(editing?.is_system)}
                            onChange={(event) => form.setData('scope', event.target.value)}
                        >
                            <option value="global">Global</option>
                            <option value="tenant">Tenant</option>
                        </select>
                        {form.data.scope === 'tenant' && (
                            <select
                                className={inputClass}
                                value={form.data.business_id}
                                disabled={Boolean(editing?.is_system)}
                                onChange={(event) => form.setData('business_id', event.target.value)}
                            >
                                <option value="">Selecciona tenant</option>
                                {businesses.map((business) => (
                                    <option key={business.id} value={business.id}>{business.name}</option>
                                ))}
                            </select>
                        )}
                        <input className={inputClass} placeholder="Clave" value={form.data.key} disabled={Boolean(editing?.is_system)} onChange={(event) => form.setData('key', event.target.value)} />
                        <input className={inputClass} placeholder="Nombre" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
                        <label className="flex items-center gap-2 text-sm font-semibold text-slate-700">
                            <input
                                type="checkbox"
                                checked={form.data.is_active}
                                disabled={Boolean(editing?.is_system)}
                                onChange={(event) => form.setData('is_active', event.target.checked)}
                            />
                            Rol activo
                        </label>
                    </div>
                    <div className="mt-4 max-h-[520px] space-y-4 overflow-y-auto rounded-lg border border-slate-100 p-3">
                        {Object.entries(groups).map(([group, items]) => (
                            <div key={group}>
                                <h3 className="text-xs font-bold uppercase tracking-wide text-slate-500">{group}</h3>
                                <div className="mt-2 grid gap-2">
                                    {items.map((permission) => (
                                        <label key={permission.key} className="flex items-center gap-2 text-sm text-slate-700">
                                            <input
                                                type="checkbox"
                                                checked={form.data.permissions.includes(permission.key)}
                                                onChange={() => togglePermission(permission.key)}
                                            />
                                            <span>{permission.name}</span>
                                            <code className="text-[11px] text-slate-400">{permission.key}</code>
                                        </label>
                                    ))}
                                </div>
                            </div>
                        ))}
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
                        <a className="rounded-lg bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-700" href={route('super-admin.security.permissions')}>Permisos</a>
                        <a className="rounded-lg bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-700" href={route('super-admin.security.assignments')}>Asignaciones</a>
                    </div>
                    <table className="min-w-full text-sm">
                        <thead className="text-left text-xs uppercase text-slate-500">
                            <tr><th className="py-2">Rol</th><th>Scope</th><th>Estado</th><th>Permisos</th><th className="text-right">Acciones</th></tr>
                        </thead>
                        <tbody>
                            {roles.map((role) => (
                                <tr key={role.id} className="border-t border-slate-100">
                                    <td className="py-3 font-semibold text-slate-900">{role.name}<br /><code className="text-xs text-slate-400">{role.key}</code></td>
                                    <td className="py-3 text-slate-600">{role.business ? role.business.name : 'Global'}</td>
                                    <td className="py-3 text-slate-600">{role.is_active ? 'Activo' : 'Inactivo'}{role.is_system ? ' · Sistema' : ''}</td>
                                    <td className="py-3 text-slate-600">{role.permissions.length}</td>
                                    <td className="py-3 text-right">
                                        <button className="mr-2 rounded-lg border px-3 py-1 text-xs font-semibold" onClick={() => edit(role)}>Editar</button>
                                        {!role.is_system && (
                                            <button className="rounded-lg bg-red-600 px-3 py-1 text-xs font-semibold text-white" onClick={() => router.delete(route('super-admin.security.roles.destroy', role.id))}>
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
