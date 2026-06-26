import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type ProductLocation = {
    id: number;
    name: string;
    description: string | null;
    is_active: boolean;
    products_count: number;
};

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
};

export default function Index({ locations, filters }: { locations: Paginated<ProductLocation>; filters: { search?: string } }) {
    const form = useForm({ name: '', description: '', is_active: true });
    const [editing, setEditing] = useState<ProductLocation | null>(null);
    const [search, setSearch] = useState(filters.search ?? '');

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (editing) {
            form.put(route('product-locations.update', editing.id), {
                preserveScroll: true,
                onSuccess: () => {
                    setEditing(null);
                    form.reset();
                },
            });
            return;
        }

        form.post(route('product-locations.store'), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const edit = (location: ProductLocation) => {
        setEditing(location);
        form.setData({
            name: location.name,
            description: location.description ?? '',
            is_active: location.is_active,
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-950">Ubicaciones</h2>}>
            <Head title="Ubicaciones" />

            <div className="mx-auto grid max-w-7xl gap-5 px-5 py-5 lg:grid-cols-[360px_1fr] sm:px-6">
                <form onSubmit={submit} className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div>
                        <h3 className="text-base font-semibold text-slate-950">
                            {editing ? 'Editar ubicación' : 'Nueva ubicación'}
                        </h3>
                        <p className="mt-1 text-sm text-slate-500">
                            Catálogo de ubicaciones para productos e importaciones.
                        </p>
                    </div>

                    <label className="block text-sm font-medium text-slate-700">
                        Nombre
                        <input
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            className="mt-1 block w-full rounded-xl border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100"
                        />
                    </label>
                    {form.errors.name && <p className="text-sm text-red-600">{form.errors.name}</p>}

                    <label className="block text-sm font-medium text-slate-700">
                        Descripción
                        <textarea
                            value={form.data.description}
                            onChange={(event) => form.setData('description', event.target.value)}
                            rows={3}
                            className="mt-1 block w-full rounded-xl border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100"
                        />
                    </label>

                    <label className="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input
                            type="checkbox"
                            checked={form.data.is_active}
                            onChange={(event) => form.setData('is_active', event.target.checked)}
                            className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                        />
                        Activa
                    </label>

                    <div className="flex gap-2">
                        <button
                            disabled={form.processing}
                            className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                        >
                            {editing ? 'Actualizar' : 'Crear'}
                        </button>
                        {editing && (
                            <button
                                type="button"
                                onClick={() => {
                                    setEditing(null);
                                    form.reset();
                                }}
                                className="rounded-xl bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-700"
                            >
                                Cancelar
                            </button>
                        )}
                    </div>
                </form>

                <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            router.get(route('product-locations.index'), { search }, { preserveState: true });
                        }}
                        className="mb-4 flex gap-2"
                    >
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Buscar ubicación"
                            className="min-w-0 flex-1 rounded-xl border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100"
                        />
                        <button className="rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white">
                            Buscar
                        </button>
                    </form>

                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-100 text-sm">
                            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                                <tr>
                                    <th className="px-3 py-2">Ubicación</th>
                                    <th className="px-3 py-2">Descripción</th>
                                    <th className="px-3 py-2">Productos</th>
                                    <th className="px-3 py-2">Estado</th>
                                    <th className="px-3 py-2 text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {locations.data.map((location) => (
                                    <tr key={location.id}>
                                        <td className="px-3 py-3 font-semibold text-slate-950">{location.name}</td>
                                        <td className="px-3 py-3 text-slate-600">{location.description ?? '-'}</td>
                                        <td className="px-3 py-3 text-slate-600">{location.products_count}</td>
                                        <td className="px-3 py-3">
                                            <span className={`rounded-full px-2 py-1 text-xs font-semibold ${location.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>
                                                {location.is_active ? 'Activa' : 'Inactiva'}
                                            </span>
                                        </td>
                                        <td className="space-x-2 px-3 py-3 text-right">
                                            <button onClick={() => edit(location)} className="rounded-lg px-2 py-1 font-semibold text-indigo-600 hover:bg-indigo-50">
                                                Editar
                                            </button>
                                            {location.is_active && (
                                                <button onClick={() => router.delete(route('product-locations.destroy', location.id), { preserveScroll: true })} className="rounded-lg px-2 py-1 font-semibold text-red-600 hover:bg-red-50">
                                                    Desactivar
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="mt-4 flex flex-wrap gap-2">
                        {locations.links.map((link) => (
                            <Link
                                key={link.label}
                                href={link.url ?? '#'}
                                preserveScroll
                                className={`rounded-lg px-3 py-2 text-sm ${link.active ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700'} ${!link.url ? 'pointer-events-none opacity-50' : ''}`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
