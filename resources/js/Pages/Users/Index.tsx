import InputError from '@/Components/InputError';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, ReactNode, useState } from 'react';

type User = {
    id: number;
    name: string;
    email: string;
    role: string;
    is_active: boolean;
};

type Limits = {
    active_users: number;
    max_users: number;
};

const roleLabels: Record<string, string> = {
    owner: 'Propietario',
    admin: 'Administrador',
    cashier: 'Cajero',
    stock_manager: 'Inventario',
};

export default function Index({
    users,
    limits,
    roles,
}: {
    users: User[];
    limits: Limits;
    roles: string[];
}) {
    const [editing, setEditing] = useState<User | null>(null);
    const [resetTarget, setResetTarget] = useState<User | null>(null);
    const reachedLimit = limits.active_users >= limits.max_users;
    const nearLimit = !reachedLimit && limits.max_users > 1 && limits.active_users >= limits.max_users - 1;

    const form = useForm({
        name: '',
        email: '',
        role: 'cashier',
        is_active: true,
        password: '',
        password_confirmation: '',
    });

    const passwordForm = useForm({
        password: '',
        password_confirmation: '',
    });

    function startCreate() {
        setEditing(null);
        form.clearErrors();
        form.reset();
        form.setData('role', 'cashier');
        form.setData('is_active', true);
    }

    function startEdit(user: User) {
        setEditing(user);
        form.clearErrors();
        form.setData({
            name: user.name,
            email: user.email,
            role: user.role,
            is_active: user.is_active,
            password: '',
            password_confirmation: '',
        });
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        if (editing) {
            form.put(route('users.update', editing.id), {
                preserveScroll: true,
                onSuccess: () => {
                    setEditing(null);
                    form.reset();
                },
            });
            return;
        }

        form.post(route('users.store'), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    }

    function toggleActive(user: User) {
        const action = user.is_active ? 'desactivar' : 'activar';

        if (window.confirm(`¿Quieres ${action} este usuario?`)) {
            router.patch(route('users.toggle-active', user.id), {}, { preserveScroll: true });
        }
    }

    function submitPassword(event: FormEvent) {
        event.preventDefault();

        if (!resetTarget) return;

        passwordForm.put(route('users.password', resetTarget.id), {
            preserveScroll: true,
            onSuccess: () => {
                setResetTarget(null);
                passwordForm.reset();
            },
        });
    }

    const availableRoles = editing && !roles.includes(editing.role)
        ? [editing.role, ...roles]
        : roles;

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold text-slate-950">Usuarios</h2>}
        >
            <Head title="Usuarios" />

            <div className="mx-auto max-w-[1800px] space-y-6 px-5 py-5 sm:px-6">
                <section className="grid gap-4 md:grid-cols-[280px_1fr]">
                    <div className="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <p className="text-sm font-medium text-slate-500">Usuarios activos</p>
                        <div className="mt-3 flex items-end gap-2">
                            <span className="text-4xl font-bold text-slate-950">{limits.active_users}</span>
                            <span className="pb-1 text-lg font-semibold text-slate-500">/ {limits.max_users}</span>
                        </div>
                        {reachedLimit && (
                            <p className="mt-4 rounded-xl border border-amber-100 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-700">
                                Límite de usuarios alcanzado.
                            </p>
                        )}
                        {nearLimit && (
                            <p className="mt-4 rounded-xl border border-amber-100 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-700">
                                Estás cerca del límite de usuarios.
                            </p>
                        )}
                        <InputError message={(form.errors as Record<string, string>).users} className="mt-3" />
                    </div>

                    <form onSubmit={submit} className="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h3 className="text-lg font-semibold text-slate-950">
                                    {editing ? 'Editar usuario' : 'Crear usuario'}
                                </h3>
                                <p className="mt-1 text-sm text-slate-500">
                                    Administra los accesos del negocio.
                                </p>
                            </div>
                            {editing && (
                                <button
                                    type="button"
                                    onClick={startCreate}
                                    className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                                >
                                    Crear usuario
                                </button>
                            )}
                        </div>

                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                            <Field label="Nombre" error={form.errors.name}>
                                <TextInput
                                    className={inputClass}
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                />
                            </Field>
                            <Field label="Email" error={form.errors.email}>
                                <TextInput
                                    className={inputClass}
                                    type="email"
                                    value={form.data.email}
                                    disabled={Boolean(editing)}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                />
                            </Field>
                            <Field label="Rol" error={form.errors.role}>
                                <select
                                    className={inputClass}
                                    value={form.data.role}
                                    disabled={editing?.role === 'owner'}
                                    onChange={(e) => form.setData('role', e.target.value)}
                                >
                                    {availableRoles.map((role) => (
                                        <option key={role} value={role}>
                                            {roleLabels[role] ?? role}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            {!editing && (
                                <>
                                    <Field label="Contraseña" error={form.errors.password}>
                                        <TextInput
                                            className={inputClass}
                                            type="password"
                                            value={form.data.password}
                                            onChange={(e) => form.setData('password', e.target.value)}
                                        />
                                    </Field>
                                    <Field label="Confirmar contraseña" error={form.errors.password_confirmation}>
                                        <TextInput
                                            className={inputClass}
                                            type="password"
                                            value={form.data.password_confirmation}
                                            onChange={(e) => form.setData('password_confirmation', e.target.value)}
                                        />
                                    </Field>
                                </>
                            )}
                            {editing && (
                                <label className="mt-7 flex items-center gap-3 rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700">
                                    <input
                                        type="checkbox"
                                        checked={form.data.is_active}
                                        onChange={(e) => form.setData('is_active', e.target.checked)}
                                        className="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                    />
                                    Usuario activo
                                </label>
                            )}
                        </div>

                        <div className="mt-5 flex gap-3">
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:opacity-50"
                            >
                                Guardar
                            </button>
                            {editing && (
                                <button
                                    type="button"
                                    onClick={startCreate}
                                    className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                                >
                                    Cancelar
                                </button>
                            )}
                        </div>
                    </form>
                </section>

                <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                        <h3 className="text-lg font-semibold text-slate-950">Usuarios del negocio</h3>
                        <button
                            type="button"
                            onClick={startCreate}
                            className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700"
                        >
                            Crear usuario
                        </button>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50/80 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-5 py-3">Nombre</th>
                                    <th className="px-5 py-3">Email</th>
                                    <th className="px-5 py-3">Rol</th>
                                    <th className="px-5 py-3">Estado</th>
                                    <th className="px-5 py-3 text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                {users.map((user) => (
                                    <tr key={user.id} className="border-t border-slate-100 transition hover:bg-indigo-50/30">
                                        <td className="px-5 py-3 font-semibold text-slate-950">{user.name}</td>
                                        <td className="px-5 py-3 text-slate-600">{user.email}</td>
                                        <td className="px-5 py-3">
                                            <Badge className="border-indigo-100 bg-indigo-50 text-indigo-700">
                                                {roleLabels[user.role] ?? user.role}
                                            </Badge>
                                        </td>
                                        <td className="px-5 py-3">
                                            <Badge
                                                className={
                                                    user.is_active
                                                        ? 'border-emerald-100 bg-emerald-50 text-emerald-700'
                                                        : 'border-slate-200 bg-slate-100 text-slate-600'
                                                }
                                            >
                                                {user.is_active ? 'Activo' : 'Inactivo'}
                                            </Badge>
                                        </td>
                                        <td className="px-5 py-3 text-right">
                                            <div className="flex flex-wrap justify-end gap-2">
                                                <ActionButton onClick={() => startEdit(user)}>Editar</ActionButton>
                                                <ActionButton onClick={() => setResetTarget(user)}>
                                                    Resetear contraseña
                                                </ActionButton>
                                                <ActionButton onClick={() => toggleActive(user)}>
                                                    {user.is_active ? 'Desactivar' : 'Activar'}
                                                </ActionButton>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                {resetTarget && (
                    <form onSubmit={submitPassword} className="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="mb-4">
                            <h3 className="text-lg font-semibold text-slate-950">
                                Resetear contraseña
                            </h3>
                            <p className="mt-1 text-sm text-slate-500">
                                Usuario: {resetTarget.name}
                            </p>
                        </div>

                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <Field label="Nueva contraseña" error={passwordForm.errors.password}>
                                <TextInput
                                    className={inputClass}
                                    type="password"
                                    value={passwordForm.data.password}
                                    onChange={(e) => passwordForm.setData('password', e.target.value)}
                                />
                            </Field>
                            <Field label="Confirmar contraseña" error={passwordForm.errors.password_confirmation}>
                                <TextInput
                                    className={inputClass}
                                    type="password"
                                    value={passwordForm.data.password_confirmation}
                                    onChange={(e) => passwordForm.setData('password_confirmation', e.target.value)}
                                />
                            </Field>
                        </div>

                        <div className="mt-5 flex gap-3">
                            <button
                                type="submit"
                                disabled={passwordForm.processing}
                                className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:opacity-50"
                            >
                                Guardar contraseña
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    setResetTarget(null);
                                    passwordForm.reset();
                                }}
                                className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                            >
                                Cancelar
                            </button>
                        </div>
                    </form>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

const inputClass =
    'w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100 disabled:bg-slate-100 disabled:text-slate-500';

function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
    return (
        <div>
            <label className="text-sm font-medium text-slate-700">{label}</label>
            <div className="mt-1">{children}</div>
            <InputError message={error} className="mt-1" />
        </div>
    );
}

function Badge({ children, className }: { children: ReactNode; className: string }) {
    return (
        <span className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold ${className}`}>
            {children}
        </span>
    );
}

function ActionButton({ children, onClick }: { children: ReactNode; onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
        >
            {children}
        </button>
    );
}
