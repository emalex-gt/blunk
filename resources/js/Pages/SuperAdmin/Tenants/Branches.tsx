import InputError from '@/Components/InputError';
import TextInput from '@/Components/TextInput';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout';
import { Link, router, useForm } from '@inertiajs/react';
import { FormEvent, ReactNode, useMemo, useState } from 'react';

type Tenant = {
    id: number;
    name: string;
    country: string | null;
    branches_module_enabled: boolean;
    use_branches: boolean;
};

type Branch = {
    id: number;
    name: string;
    code: string | null;
    address: string | null;
    phone: string | null;
    logo_url: string | null;
    is_active: boolean;
};

export default function Branches({ tenant, branches }: { tenant: Tenant; branches: Branch[] }) {
    const [editing, setEditing] = useState<Branch | null>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const form = useForm({
        _method: 'post',
        name: '',
        code: '',
        address: '',
        phone: '',
        is_active: true,
        logo: null as File | null,
        remove_logo: false,
    });

    const editingPreview = useMemo(() => preview ?? editing?.logo_url ?? null, [editing, preview]);

    function resetForm() {
        setEditing(null);
        setPreview(null);
        form.reset();
        form.setData({
            _method: 'post',
            name: '',
            code: '',
            address: '',
            phone: '',
            is_active: true,
            logo: null,
            remove_logo: false,
        });
        form.clearErrors();
    }

    function editBranch(branch: Branch) {
        setEditing(branch);
        setPreview(null);
        form.setData({
            _method: 'put',
            name: branch.name,
            code: branch.code ?? '',
            address: branch.address ?? '',
            phone: branch.phone ?? '',
            is_active: branch.is_active,
            logo: null,
            remove_logo: false,
        });
        form.clearErrors();
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        if (editing) {
            form.post(route('super-admin.tenants.branches.update', [tenant.id, editing.id]), {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: resetForm,
            });
            return;
        }

        form.post(route('super-admin.tenants.branches.store', tenant.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: resetForm,
        });
    }

    function deleteBranch(branch: Branch) {
        if (!window.confirm(`¿Eliminar la sucursal ${branch.name}?`)) {
            return;
        }

        router.delete(route('super-admin.tenants.branches.destroy', [tenant.id, branch.id]), {
            preserveScroll: true,
        });
    }

    return (
        <SuperAdminLayout title={`Sucursales - ${tenant.name}`}>
            <div className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold text-slate-950">Sucursales</h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Gestión interna de sucursales para {tenant.name}.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Link
                            href={route('super-admin.tenants.edit', tenant.id)}
                            className="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100"
                        >
                            Volver al tenant
                        </Link>
                    </div>
                </div>

                {(!tenant.branches_module_enabled || !tenant.use_branches) && (
                    <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                        El módulo de sucursales o el uso de sucursales no está activo para este tenant. Puedes preparar sucursales aquí,
                        pero el tenant sólo las usará cuando el módulo y la configuración estén habilitados.
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-[1fr_380px]">
                    <section className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <thead className="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th className="px-4 py-3">Sucursal</th>
                                    <th className="px-4 py-3">Código</th>
                                    <th className="px-4 py-3">Dirección</th>
                                    <th className="px-4 py-3">Teléfono</th>
                                    <th className="px-4 py-3">Estado</th>
                                    <th className="px-4 py-3 text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {branches.length === 0 ? (
                                    <tr>
                                        <td colSpan={6} className="px-4 py-12 text-center text-gray-500">
                                            No hay sucursales creadas.
                                        </td>
                                    </tr>
                                ) : branches.map((branch) => (
                                    <tr key={branch.id} className="hover:bg-gray-50">
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-3">
                                                {branch.logo_url ? (
                                                    <img src={branch.logo_url} alt={branch.name} className="h-10 w-10 rounded-lg border border-gray-200 object-contain p-1" />
                                                ) : (
                                                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-100 text-xs font-bold text-gray-500">
                                                        {branch.name.slice(0, 1).toUpperCase()}
                                                    </div>
                                                )}
                                                <span className="font-semibold text-gray-900">{branch.name}</span>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-gray-600">{branch.code ?? '-'}</td>
                                        <td className="px-4 py-3 text-gray-600">{branch.address ?? '-'}</td>
                                        <td className="px-4 py-3 text-gray-600">{branch.phone ?? '-'}</td>
                                        <td className="px-4 py-3">
                                            <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${branch.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-600'}`}>
                                                {branch.is_active ? 'Activa' : 'Inactiva'}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <button type="button" onClick={() => editBranch(branch)} className="font-semibold text-indigo-600 hover:text-indigo-700">
                                                Editar
                                            </button>
                                            <button type="button" onClick={() => deleteBranch(branch)} className="ml-3 font-semibold text-red-600 hover:text-red-700">
                                                Eliminar
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>

                    <form onSubmit={submit} className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                        <div className="mb-4 flex items-start justify-between gap-3">
                            <div>
                                <h2 className="text-lg font-semibold text-gray-900">
                                    {editing ? 'Editar sucursal' : 'Nueva sucursal'}
                                </h2>
                                <p className="mt-1 text-sm text-gray-500">
                                    Los logos se guardan en Cloudinary.
                                </p>
                            </div>
                            {editing && (
                                <button type="button" onClick={resetForm} className="text-sm font-semibold text-gray-600 hover:text-gray-900">
                                    Limpiar
                                </button>
                            )}
                        </div>

                        <div className="space-y-4">
                            <Field label="Nombre" error={form.errors.name}>
                                <TextInput className={inputClass} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
                            </Field>
                            <Field label="Código" error={form.errors.code}>
                                <TextInput className={inputClass} value={form.data.code} onChange={(event) => form.setData('code', event.target.value)} />
                            </Field>
                            <Field label="Dirección" error={form.errors.address}>
                                <TextInput className={inputClass} value={form.data.address} onChange={(event) => form.setData('address', event.target.value)} />
                            </Field>
                            <Field label="Teléfono" error={form.errors.phone}>
                                <TextInput className={inputClass} value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} />
                            </Field>

                            <label className="flex items-center gap-2 text-sm font-semibold text-gray-700">
                                <input
                                    type="checkbox"
                                    checked={form.data.is_active}
                                    onChange={(event) => form.setData('is_active', event.target.checked)}
                                    className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                />
                                Activa
                            </label>

                            <div className="rounded-lg border border-gray-200 bg-gray-50 p-3">
                                <div className="mb-3 text-sm font-semibold text-gray-700">Logo</div>
                                <div className="flex items-center gap-3">
                                    {editingPreview ? (
                                        <img src={editingPreview} alt="Logo" className="h-16 w-16 rounded-lg border border-gray-200 bg-white object-contain p-1" />
                                    ) : (
                                        <div className="flex h-16 w-16 items-center justify-center rounded-lg border border-dashed border-gray-300 bg-white text-xs font-semibold text-gray-400">
                                            Sin logo
                                        </div>
                                    )}
                                    <div className="space-y-2">
                                        <input
                                            type="file"
                                            accept="image/*"
                                            onChange={(event) => {
                                                const file = event.target.files?.[0] ?? null;
                                                form.setData('logo', file);
                                                form.setData('remove_logo', false);
                                                setPreview(file ? URL.createObjectURL(file) : null);
                                            }}
                                            className="block text-xs text-gray-600 file:mr-2 file:rounded-md file:border-0 file:bg-indigo-600 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-white hover:file:bg-indigo-700"
                                        />
                                        {editingPreview && (
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    form.setData('logo', null);
                                                    form.setData('remove_logo', true);
                                                    setPreview(null);
                                                }}
                                                className="text-xs font-semibold text-red-600 hover:text-red-700"
                                            >
                                                Quitar logo
                                            </button>
                                        )}
                                        <InputError message={form.errors.logo} />
                                    </div>
                                </div>
                            </div>

                            <button
                                type="submit"
                                disabled={form.processing}
                                className="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                            >
                                {editing ? 'Guardar cambios' : 'Crear sucursal'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </SuperAdminLayout>
    );
}

function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
    return (
        <label className="block">
            <span className="text-sm font-medium text-gray-700">{label}</span>
            <div className="mt-1">{children}</div>
            <InputError message={error} className="mt-1" />
        </label>
    );
}

const inputClass = 'w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500';
