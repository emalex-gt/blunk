import SuperAdminLayout from '@/Layouts/SuperAdminLayout';
import { router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Business = { id: number; name: string };
type Role = { id: number; key: string; name: string };
type Permission = { id: number; key: string; name: string; group: string | null };
type User = { id: number; name: string; email: string; roles: Role[]; direct_permissions: Permission[] };

export default function Assignments({
    businesses,
    selectedBusinessId,
    users,
    roles,
    permissions,
}: {
    businesses: Business[];
    selectedBusinessId: number | null;
    users: User[];
    roles: Role[];
    permissions: Permission[];
}) {
    const [selectedUser, setSelectedUser] = useState<User | null>(null);
    const form = useForm({ roles: [] as string[], permissions: [] as string[] });

    function selectUser(user: User) {
        setSelectedUser(user);
        form.setData({
            roles: user.roles.map((role) => role.key),
            permissions: user.direct_permissions.map((permission) => permission.key),
        });
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        if (!selectedUser) return;

        form.put(route('super-admin.security.assignments.update', selectedUser.id), {
            preserveScroll: true,
        });
    }

    function toggle(key: string, field: 'roles' | 'permissions') {
        form.setData(field, form.data[field].includes(key)
            ? form.data[field].filter((item) => item !== key)
            : [...form.data[field], key]);
    }

    return (
        <SuperAdminLayout title="Asignaciones">
            <div className="mb-4 flex gap-2">
                <a className="rounded-lg bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-700" href={route('super-admin.security.roles')}>Roles</a>
                <a className="rounded-lg bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-700" href={route('super-admin.security.permissions')}>Permisos</a>
            </div>
            <div className="mb-4">
                <select
                    className="rounded-lg border-slate-300 text-sm"
                    value={selectedBusinessId ?? ''}
                    onChange={(event) => router.get(route('super-admin.security.assignments'), { business_id: event.target.value }, { preserveState: true })}
                >
                    {businesses.map((business) => <option key={business.id} value={business.id}>{business.name}</option>)}
                </select>
            </div>
            <div className="grid gap-6 lg:grid-cols-[360px_1fr]">
                <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    {users.map((user) => (
                        <button
                            key={user.id}
                            type="button"
                            onClick={() => selectUser(user)}
                            className={`mb-2 block w-full rounded-lg border px-3 py-2 text-left text-sm ${selectedUser?.id === user.id ? 'border-indigo-300 bg-indigo-50' : 'border-slate-200 bg-white'}`}
                        >
                            <strong>{user.name}</strong><br />
                            <span className="text-slate-500">{user.email}</span>
                        </button>
                    ))}
                </section>
                <form onSubmit={submit} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h2 className="text-lg font-semibold text-slate-900">{selectedUser ? selectedUser.name : 'Selecciona un usuario'}</h2>
                    {selectedUser && (
                        <>
                            <h3 className="mt-4 text-sm font-bold uppercase text-slate-500">Roles</h3>
                            <div className="mt-2 grid gap-2 md:grid-cols-2">
                                {roles.map((role) => (
                                    <label key={role.key} className="flex items-center gap-2 text-sm">
                                        <input type="checkbox" checked={form.data.roles.includes(role.key)} onChange={() => toggle(role.key, 'roles')} />
                                        {role.name}
                                    </label>
                                ))}
                            </div>
                            <h3 className="mt-5 text-sm font-bold uppercase text-slate-500">Permisos directos</h3>
                            <div className="mt-2 grid max-h-[420px] gap-2 overflow-y-auto md:grid-cols-2">
                                {permissions.map((permission) => (
                                    <label key={permission.key} className="flex items-center gap-2 text-sm">
                                        <input type="checkbox" checked={form.data.permissions.includes(permission.key)} onChange={() => toggle(permission.key, 'permissions')} />
                                        {permission.name}
                                    </label>
                                ))}
                            </div>
                            <button className="mt-5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" disabled={form.processing}>
                                Guardar asignación
                            </button>
                        </>
                    )}
                </form>
            </div>
        </SuperAdminLayout>
    );
}
