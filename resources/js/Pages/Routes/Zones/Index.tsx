import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Branch = { id: number; name: string };
type Seller = { id: number; name: string; current_branch_id: number | null };
type Zone = {
    id: number;
    name: string;
    description: string | null;
    branch_id: number;
    assigned_user_id: number | null;
    is_active: boolean;
    active_customers_count: number;
    branch?: Branch;
    assigned_user?: { id: number; name: string } | null;
};

export default function Index({ zones, branches, sellers }: { zones: Zone[]; branches: Branch[]; sellers: Seller[] }) {
    const form = useForm({
        branch_id: branches[0]?.id ?? '',
        assigned_user_id: '',
        name: '',
        description: '',
        is_active: true,
    });
    const [editing, setEditing] = useState<Record<number, Partial<Zone>>>({});

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('routes.zones.store'), {
            preserveScroll: true,
            onSuccess: () => form.reset('assigned_user_id', 'name', 'description'),
        });
    };

    const updateZone = (zone: Zone) => {
        const values = editing[zone.id] ?? zone;
        router.put(
            route('routes.zones.update', zone.id),
            {
                branch_id: values.branch_id ?? zone.branch_id,
                assigned_user_id: values.assigned_user_id ?? zone.assigned_user_id ?? '',
                name: values.name ?? zone.name,
                description: values.description ?? zone.description ?? '',
                is_active: values.is_active ?? zone.is_active,
            },
            { preserveScroll: true },
        );
    };

    return (
        <AuthenticatedLayout>
            <Head title="Rutas" />
            <div className="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6">
                <div>
                    <h1 className="text-2xl font-semibold text-slate-950">Rutas</h1>
                    <p className="text-sm text-slate-500">Zonas de preventa por sucursal y vendedor.</p>
                </div>

                <form onSubmit={submit} className="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-5">
                    <input
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        placeholder="Nombre de zona"
                        className="rounded-lg border-slate-200 text-sm"
                    />
                    <select
                        value={form.data.branch_id}
                        onChange={(event) => form.setData('branch_id', Number(event.target.value))}
                        className="rounded-lg border-slate-200 text-sm"
                    >
                        {branches.map((branch) => (
                            <option key={branch.id} value={branch.id}>{branch.name}</option>
                        ))}
                    </select>
                    <select
                        value={form.data.assigned_user_id}
                        onChange={(event) => form.setData('assigned_user_id', event.target.value)}
                        className="rounded-lg border-slate-200 text-sm"
                    >
                        <option value="">Sin vendedor</option>
                        {sellers.map((seller) => (
                            <option key={seller.id} value={seller.id}>{seller.name}</option>
                        ))}
                    </select>
                    <input
                        value={form.data.description}
                        onChange={(event) => form.setData('description', event.target.value)}
                        placeholder="Descripción"
                        className="rounded-lg border-slate-200 text-sm"
                    />
                    <button className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60" disabled={form.processing}>
                        Crear zona
                    </button>
                    {(form.errors.name || form.errors.branch_id) && (
                        <div className="md:col-span-5 text-sm text-red-600">{form.errors.name || form.errors.branch_id}</div>
                    )}
                </form>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
                                <tr>
                                    <th className="px-4 py-3">Zona</th>
                                    <th className="px-4 py-3">Sucursal</th>
                                    <th className="px-4 py-3">Vendedor</th>
                                    <th className="px-4 py-3">Clientes</th>
                                    <th className="px-4 py-3">Estado</th>
                                    <th className="px-4 py-3">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {zones.map((zone) => {
                                    const row = editing[zone.id] ?? {};
                                    return (
                                        <tr key={zone.id}>
                                            <td className="px-4 py-3">
                                                <input
                                                    value={row.name ?? zone.name}
                                                    onChange={(event) => setEditing((current) => ({ ...current, [zone.id]: { ...row, name: event.target.value } }))}
                                                    className="w-full rounded-lg border-slate-200 text-sm"
                                                />
                                            </td>
                                            <td className="px-4 py-3">
                                                <select
                                                    value={row.branch_id ?? zone.branch_id}
                                                    onChange={(event) => setEditing((current) => ({ ...current, [zone.id]: { ...row, branch_id: Number(event.target.value) } }))}
                                                    className="rounded-lg border-slate-200 text-sm"
                                                >
                                                    {branches.map((branch) => (
                                                        <option key={branch.id} value={branch.id}>{branch.name}</option>
                                                    ))}
                                                </select>
                                            </td>
                                            <td className="px-4 py-3">
                                                <select
                                                    value={row.assigned_user_id ?? zone.assigned_user_id ?? ''}
                                                    onChange={(event) => setEditing((current) => ({ ...current, [zone.id]: { ...row, assigned_user_id: event.target.value ? Number(event.target.value) : null } }))}
                                                    className="rounded-lg border-slate-200 text-sm"
                                                >
                                                    <option value="">Sin vendedor</option>
                                                    {sellers.map((seller) => (
                                                        <option key={seller.id} value={seller.id}>{seller.name}</option>
                                                    ))}
                                                </select>
                                            </td>
                                            <td className="px-4 py-3">{zone.active_customers_count}</td>
                                            <td className="px-4 py-3">
                                                <label className="inline-flex items-center gap-2">
                                                    <input
                                                        type="checkbox"
                                                        checked={row.is_active ?? zone.is_active}
                                                        onChange={(event) => setEditing((current) => ({ ...current, [zone.id]: { ...row, is_active: event.target.checked } }))}
                                                    />
                                                    Activa
                                                </label>
                                            </td>
                                            <td className="space-x-2 px-4 py-3">
                                                <button onClick={() => updateZone(zone)} className="rounded-lg bg-slate-900 px-3 py-2 font-semibold text-white">Guardar</button>
                                                <Link href={route('routes.zones.customers', zone.id)} className="rounded-lg bg-indigo-50 px-3 py-2 font-semibold text-indigo-700">
                                                    Clientes
                                                </Link>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
