import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Quotation = {
    id: number;
    number: string;
    status: string;
    issued_at: string;
    valid_until: string;
    expired: boolean;
    total: number;
    customer: { name?: string; commercial_name?: string; doc_number?: string };
    branch?: { name: string };
    created_by?: { name: string };
};

type Filters = { search?: string; status?: string; branch_id?: string; issued_from?: string; issued_to?: string; valid_from?: string; valid_to?: string };

export default function Index({ quotations, filters, branches }: { quotations: { data: Quotation[]; links: unknown[] }; filters: Filters; branches: { id: number; name: string }[] }) {
    const [form, setForm] = useState<Filters>(filters);
    const set = (key: keyof Filters, value: string) => setForm((current) => ({ ...current, [key]: value }));
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        router.get(route('quotations.index'), form, { preserveState: true, preserveScroll: true });
    };
    const label = (quotation: Quotation) => quotation.status === 'cancelled'
        ? 'Anulada'
        : quotation.status === 'converted'
            ? 'Convertida'
            : quotation.expired
                ? 'Vencida'
                : 'Vigente';

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-950">Cotizaciones</h2>}>
            <Head title="Cotizaciones" />
            <main className="mx-auto max-w-7xl space-y-5 px-4 py-5">
                <form onSubmit={submit} className="grid gap-3 rounded-2xl border bg-white p-4 md:grid-cols-4">
                    <input value={form.search ?? ''} onChange={(event) => set('search', event.target.value)} placeholder="Número, cliente o documento" className="rounded-xl border-slate-200 md:col-span-2" />
                    <select value={form.status ?? ''} onChange={(event) => set('status', event.target.value)} className="rounded-xl border-slate-200"><option value="">Todos los estados</option><option value="open">Abiertas</option><option value="conversion_pending">Conversión pendiente</option><option value="converted">Convertidas</option><option value="cancelled">Anuladas</option></select>
                    <select value={form.branch_id ?? ''} onChange={(event) => set('branch_id', event.target.value)} className="rounded-xl border-slate-200"><option value="">Todas las sucursales</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select>
                    <label className="text-xs font-medium text-slate-600">Emitida desde<input type="date" value={form.issued_from ?? ''} onChange={(event) => set('issued_from', event.target.value)} className="mt-1 block w-full rounded-xl border-slate-200 text-sm" /></label>
                    <label className="text-xs font-medium text-slate-600">Emitida hasta<input type="date" value={form.issued_to ?? ''} onChange={(event) => set('issued_to', event.target.value)} className="mt-1 block w-full rounded-xl border-slate-200 text-sm" /></label>
                    <label className="text-xs font-medium text-slate-600">Vigencia desde<input type="date" value={form.valid_from ?? ''} onChange={(event) => set('valid_from', event.target.value)} className="mt-1 block w-full rounded-xl border-slate-200 text-sm" /></label>
                    <div className="flex items-end gap-2"><label className="flex-1 text-xs font-medium text-slate-600">Vigencia hasta<input type="date" value={form.valid_to ?? ''} onChange={(event) => set('valid_to', event.target.value)} className="mt-1 block w-full rounded-xl border-slate-200 text-sm" /></label><button className="rounded-xl bg-orange-600 px-4 py-2 text-sm font-semibold text-white">Buscar</button></div>
                </form>
                <section className="overflow-hidden rounded-2xl border bg-white"><table className="min-w-full text-sm"><thead><tr className="bg-slate-50 text-left"><th className="p-3">Número</th><th>Cliente</th><th>Fecha</th><th>Válida hasta</th><th>Total</th><th>Sucursal</th><th>Usuario</th><th>Estado</th></tr></thead><tbody>{quotations.data.map((quotation) => <tr key={quotation.id} className="border-t"><td className="p-3"><Link className="font-semibold text-orange-700" href={route('quotations.show', quotation.id)}>{quotation.number}</Link></td><td>{quotation.customer.commercial_name || quotation.customer.name || 'Cliente no especificado'}<div className="text-xs text-slate-500">{quotation.customer.doc_number}</div></td><td>{quotation.issued_at}</td><td>{quotation.valid_until}</td><td>Q {Number(quotation.total).toFixed(2)}</td><td>{quotation.branch?.name || '-'}</td><td>{quotation.created_by?.name || '-'}</td><td>{label(quotation)}</td></tr>)}</tbody></table></section>
            </main>
        </AuthenticatedLayout>
    );
}
