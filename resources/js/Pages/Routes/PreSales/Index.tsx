import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { FormEvent } from 'react';

type Page<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[] };
type PreSale = {
    id: number;
    status: string;
    total: string;
    created_at: string;
    items_count: number;
    customer?: { name: string; doc_number: string | null };
    seller?: { name: string };
    zone?: { name: string } | null;
    branch?: { name: string };
};

export default function Index({ preSales, filters, sellers, zones }: { preSales: Page<PreSale>; filters: Record<string, string>; sellers: { id: number; name: string }[]; zones: { id: number; name: string }[] }) {
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const values = Object.fromEntries(new FormData(event.currentTarget).entries());
        router.get(route('routes.pre-sales.index'), values, { preserveState: true });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Preventas de ruta" />
            <div className="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6">
                <div>
                    <h1 className="text-2xl font-semibold text-slate-950">Preventas</h1>
                    <p className="text-sm text-slate-500">Consulta administrativa de preventas generadas en ruta.</p>
                </div>
                <form onSubmit={submit} className="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 md:grid-cols-5">
                    <input name="date" type="date" defaultValue={filters.date ?? ''} className="rounded-lg border-slate-200 text-sm" />
                    <input name="customer" defaultValue={filters.customer ?? ''} placeholder="Cliente / NIT" className="rounded-lg border-slate-200 text-sm" />
                    <select name="seller_id" defaultValue={filters.seller_id ?? ''} className="rounded-lg border-slate-200 text-sm">
                        <option value="">Vendedor</option>
                        {sellers.map((seller) => <option key={seller.id} value={seller.id}>{seller.name}</option>)}
                    </select>
                    <select name="zone_id" defaultValue={filters.zone_id ?? ''} className="rounded-lg border-slate-200 text-sm">
                        <option value="">Zona</option>
                        {zones.map((zone) => <option key={zone.id} value={zone.id}>{zone.name}</option>)}
                    </select>
                    <button className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Filtrar</button>
                </form>
                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
                            <tr>
                                <th className="px-4 py-3">Fecha</th>
                                <th className="px-4 py-3">Cliente</th>
                                <th className="px-4 py-3">Vendedor</th>
                                <th className="px-4 py-3">Zona</th>
                                <th className="px-4 py-3">Total</th>
                                <th className="px-4 py-3">Estado</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {preSales.data.map((preSale) => (
                                <tr key={preSale.id}>
                                    <td className="px-4 py-3">{new Date(preSale.created_at).toLocaleDateString()}</td>
                                    <td className="px-4 py-3 font-medium">{preSale.customer?.name}<div className="text-xs text-slate-500">{preSale.customer?.doc_number ?? '-'}</div></td>
                                    <td className="px-4 py-3">{preSale.seller?.name ?? '-'}</td>
                                    <td className="px-4 py-3">{preSale.zone?.name ?? '-'}</td>
                                    <td className="px-4 py-3">Q {Number(preSale.total).toFixed(2)}</td>
                                    <td className="px-4 py-3">{preSale.status}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
