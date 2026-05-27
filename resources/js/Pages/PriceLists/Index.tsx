import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

type PriceType = {
    id: number;
    name: string;
    is_default: boolean;
    is_active: boolean;
    products_with_price_count: number;
};

export default function Index({
    priceTypes,
    activeCount,
}: {
    priceTypes: PriceType[];
    activeCount: number;
}) {
    function destroy(priceType: PriceType) {
        if (!confirm(`¿Eliminar la lista "${priceType.name}"?`)) {
            return;
        }

        router.delete(route('price-lists.destroy', priceType.id), { preserveScroll: true });
    }

    function toggle(priceType: PriceType) {
        router.patch(route('price-lists.update', priceType.id), {
            name: priceType.name,
            is_default: priceType.is_default,
            is_active: !priceType.is_active,
        }, { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-950">Listas de precios</h2>}>
            <Head title="Listas de precios" />

            <div className="mx-auto max-w-6xl px-5 py-6 sm:px-6">
                <div className="mb-4 flex items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-950">Listas de precios</h1>
                        <p className="mt-1 text-sm text-slate-500">Gestiona listas, predeterminados y precios por producto.</p>
                    </div>
                    <Link href={route('price-lists.create')} className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">
                        Nueva lista
                    </Link>
                </div>

                <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-100 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-3">Nombre</th>
                                <th className="px-4 py-3">Predeterminada</th>
                                <th className="px-4 py-3">Activa</th>
                                <th className="px-4 py-3">Productos con precio</th>
                                <th className="px-4 py-3 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {priceTypes.map((priceType) => (
                                <tr key={priceType.id} className="hover:bg-indigo-50/30">
                                    <td className="px-4 py-3 font-semibold text-slate-950">{priceType.name}</td>
                                    <td className="px-4 py-3">
                                        {priceType.is_default ? (
                                            <span className="rounded-full bg-indigo-50 px-2 py-1 text-xs font-semibold text-indigo-700">Sí</span>
                                        ) : '-'}
                                    </td>
                                    <td className="px-4 py-3">
                                        <span className={priceType.is_active ? 'text-emerald-700' : 'text-slate-400'}>
                                            {priceType.is_active ? 'Activa' : 'Inactiva'}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-slate-700">{priceType.products_with_price_count}</td>
                                    <td className="px-4 py-3 text-right">
                                        <div className="flex flex-wrap justify-end gap-2">
                                            <Link className="rounded-lg px-2 py-1 font-semibold text-indigo-600 hover:bg-indigo-50" href={route('price-lists.edit', priceType.id)}>
                                                Editar
                                            </Link>
                                            <Link className="rounded-lg px-2 py-1 font-semibold text-indigo-600 hover:bg-indigo-50" href={route('price-lists.prices', priceType.id)}>
                                                Asignar precios
                                            </Link>
                                            {!priceType.is_default && priceType.is_active && (
                                                <button type="button" onClick={() => router.post(route('price-lists.set-default', priceType.id), {}, { preserveScroll: true })} className="rounded-lg px-2 py-1 font-semibold text-indigo-600 hover:bg-indigo-50">
                                                    Marcar predeterminada
                                                </button>
                                            )}
                                            <button type="button" disabled={priceType.is_active && activeCount <= 1} onClick={() => toggle(priceType)} className="rounded-lg px-2 py-1 font-semibold text-slate-600 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40">
                                                {priceType.is_active ? 'Desactivar' : 'Activar'}
                                            </button>
                                            <button type="button" disabled={priceType.is_active && activeCount <= 1} onClick={() => destroy(priceType)} className="rounded-lg px-2 py-1 font-semibold text-rose-600 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-40">
                                                Eliminar
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
