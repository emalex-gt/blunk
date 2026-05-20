import TextInput from '@/Components/TextInput';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout';
import { Link, router, useForm } from '@inertiajs/react';
import { FormEvent, ReactNode, useState } from 'react';

type Tenant = { id: number; name: string };
type User = { id: number; name: string; email: string; role: string };

const roles = ['owner', 'admin', 'cashier', 'stock_manager'];

export default function Users({ tenant, users }: { tenant: Tenant; users: User[] }) {
    const [editing, setEditing] = useState<User | null>(null);
    const { data, setData, post, put, processing, reset } = useForm({
        name: '',
        email: '',
        password: '',
        role: 'cashier',
    });

    function edit(user: User) {
        setEditing(user);
        setData({ name: user.name, email: user.email, password: '', role: user.role });
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        if (editing) {
            put(route('super-admin.tenants.users.update', [tenant.id, editing.id]), {
                onSuccess: () => {
                    setEditing(null);
                    reset();
                },
            });
            return;
        }

        post(route('super-admin.tenants.users.store', tenant.id), { onSuccess: () => reset() });
    }

    function destroy(user: User) {
        if (window.confirm('¿Eliminar usuario?')) {
            router.delete(route('super-admin.tenants.users.destroy', [tenant.id, user.id]));
        }
    }

    return (
        <SuperAdminLayout title="Usuarios">
            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">{tenant.name}</h2>
                    <p className="mt-1 text-sm text-gray-500">Administra los usuarios de este negocio.</p>
                </div>
                <Link
                    href={route('super-admin.tenants.index')}
                    className="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100"
                >
                    Volver
                </Link>
            </div>

            <div className="grid gap-6 lg:grid-cols-[360px_1fr]">
                <form onSubmit={submit} className="space-y-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div>
                        <h3 className="text-xl font-semibold text-gray-900">
                            {editing ? 'Editar usuario' : 'Crear usuario'}
                        </h3>
                        <p className="mt-1 text-sm text-gray-500">
                            Define el acceso básico para este negocio.
                        </p>
                    </div>

                    <Field label="Nombre">
                        <TextInput
                            className={inputClass}
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                        />
                    </Field>
                    <Field label="Email">
                        <TextInput
                            className={inputClass}
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                        />
                    </Field>
                    <Field label="Contraseña">
                        <TextInput
                            className={inputClass}
                            type="password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                        />
                    </Field>
                    <Field label="Rol">
                        <select
                            className={inputClass}
                            value={data.role}
                            onChange={(e) => setData('role', e.target.value)}
                        >
                            {roles.map((role) => (
                                <option key={role} value={role}>
                                    {role}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <div className="flex gap-2">
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                        >
                            Guardar
                        </button>
                        {editing && (
                            <button
                                type="button"
                                onClick={() => {
                                    setEditing(null);
                                    reset();
                                }}
                                className="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100"
                            >
                                Cancelar
                            </button>
                        )}
                    </div>
                </form>

                <section className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div className="mb-4 flex items-center justify-between">
                        <h3 className="text-xl font-semibold text-gray-900">Usuarios</h3>
                        <span className="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-700">
                            {users.length}
                        </span>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    <th className="px-4 py-3">Nombre</th>
                                    <th className="px-4 py-3">Email</th>
                                    <th className="px-4 py-3">Rol</th>
                                    <th className="px-4 py-3 text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {users.map((user) => (
                                    <tr key={user.id} className="hover:bg-gray-50">
                                        <td className="px-4 py-3 font-medium text-gray-900">{user.name}</td>
                                        <td className="px-4 py-3 text-gray-600">{user.email}</td>
                                        <td className="px-4 py-3">
                                            <span className="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-700">
                                                {user.role}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="flex justify-end gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => edit(user)}
                                                    className="rounded-md border border-gray-300 px-3 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-100"
                                                >
                                                    Editar
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => destroy(user)}
                                                    className="rounded-md bg-red-600 px-3 py-1 text-xs font-semibold text-white hover:bg-red-700"
                                                >
                                                    Eliminar
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </SuperAdminLayout>
    );
}

const inputClass =
    'w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500';

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <label className="text-sm font-medium text-gray-700">{label}</label>
            <div className="mt-1">{children}</div>
        </div>
    );
}
