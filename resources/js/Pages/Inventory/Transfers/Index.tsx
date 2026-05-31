import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, ReactNode, useState } from 'react';

type Transfer = {
    id: number;
    status: string;
    created_at: string;
    notes: string | null;
    from_branch: { id: number; name: string } | null;
    to_branch: { id: number; name: string } | null;
    created_by: { id: number; name: string } | null;
    lines_count?: number;
    lines_sum_quantity?: number | null;
};

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
};

type BranchOption = { id: number; name: string };

export default function Index({ transfers, filters = {}, branches = [] }: { transfers: Paginated<Transfer>; filters?: Record<string, string | null>; branches?: BranchOption[] }) {
    const [form, setForm] = useState<Record<string, string>>(() => Object.fromEntries(Object.entries(filters).map(([key, value]) => [key, value ?? ''])));

    function setField(key: string, value: string) {
        setForm((current) => ({ ...current, [key]: value }));
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        router.get(route('inventory.transfers.index'), cleanForm(form), { preserveScroll: true, preserveState: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Traslados" />
            <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6">
                <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold text-slate-950">Traslados</h1>
                        <p className="mt-1 text-sm text-slate-500">Movimientos de inventario entre sucursales.</p>
                    </div>
                    <Link href={route('inventory.transfers.create')} className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        Nuevo traslado
                    </Link>
                </div>

                <div className="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <h2 className="text-sm font-semibold text-slate-900">Filtros</h2>
                        <div className="flex gap-2">
                            <a href={route('inventory.transfers.export', { format: 'excel', ...cleanForm(form) })} className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Exportar Excel</a>
                            <a href={route('inventory.transfers.export', { format: 'pdf', ...cleanForm(form) })} className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Exportar PDF</a>
                        </div>
                    </div>
                    <form onSubmit={submit} className="grid gap-3 md:grid-cols-4">
                        <Field label="Desde" type="date" value={form.date_from ?? ''} onChange={(value) => setField('date_from', value)} />
                        <Field label="Hasta" type="date" value={form.date_to ?? ''} onChange={(value) => setField('date_to', value)} />
                        <Field label="No. traslado" value={form.transfer_number ?? ''} onChange={(value) => setField('transfer_number', value)} />
                        <Field label="Producto" value={form.product_search ?? ''} onChange={(value) => setField('product_search', value)} />
                        {branches.length > 0 && (
                            <Select label="Origen" value={form.origin_branch_id ?? ''} onChange={(value) => setField('origin_branch_id', value)}>
                                <option value="">Sucursal activa</option>
                                {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                            </Select>
                        )}
                        {branches.length > 0 && (
                            <Select label="Destino" value={form.destination_branch_id ?? ''} onChange={(value) => setField('destination_branch_id', value)}>
                                <option value="">Sucursal activa</option>
                                {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                            </Select>
                        )}
                        <Select label="Estado" value={form.status ?? 'all'} onChange={(value) => setField('status', value)}>
                            <option value="all">Todos</option>
                            <option value="pending">Pendiente</option>
                            <option value="completed">Completado</option>
                            <option value="cancelled">Anulado</option>
                        </Select>
                        <div className="flex items-end gap-2">
                            <button type="submit" className="h-10 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Aplicar</button>
                            <Link href={route('inventory.transfers.index')} className="flex h-10 items-center rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">Limpiar</Link>
                        </div>
                    </form>
                </div>

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-3">Fecha</th>
                                <th className="px-4 py-3">Origen</th>
                                <th className="px-4 py-3">Destino</th>
                                <th className="px-4 py-3">Usuario</th>
                                <th className="px-4 py-3">Productos / cantidad</th>
                                <th className="px-4 py-3">Estado</th>
                                <th className="px-4 py-3 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {transfers.data.map((transfer) => (
                                <tr key={transfer.id}>
                                    <td className="px-4 py-3 text-slate-600">{transfer.created_at}</td>
                                    <td className="px-4 py-3 font-semibold text-slate-900">{transfer.from_branch?.name ?? '-'}</td>
                                    <td className="px-4 py-3 font-semibold text-slate-900">{transfer.to_branch?.name ?? '-'}</td>
                                    <td className="px-4 py-3 text-slate-600">{transfer.created_by?.name ?? '-'}</td>
                                    <td className="px-4 py-3 text-slate-600">{transfer.lines_count ?? 0} / {transfer.lines_sum_quantity ?? 0}</td>
                                    <td className="px-4 py-3">
                                        <span className="rounded-full bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700">
                                            {transfer.status === 'completed' ? 'Completado' : transfer.status}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <Link href={route('inventory.transfers.show', transfer.id)} className="font-semibold text-indigo-600 hover:text-indigo-700">
                                            Ver
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                            {transfers.data.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="px-4 py-8 text-center text-slate-500">
                                        No hay traslados registrados.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                    <div className="flex flex-wrap justify-end gap-1 border-t border-slate-100 px-4 py-3">
                        {transfers.links?.map((link, index) => (
                            <Link
                                key={`${link.label}-${index}`}
                                href={link.url ?? '#'}
                                preserveScroll
                                preserveState
                                className={[
                                    'rounded-md px-3 py-1 text-sm',
                                    link.active ? 'bg-indigo-600 text-white' : 'border border-slate-200 text-slate-600',
                                    !link.url ? 'pointer-events-none opacity-50' : 'hover:bg-slate-50',
                                ].join(' ')}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function cleanForm(form: Record<string, string>): Record<string, string> {
    return Object.fromEntries(Object.entries(form).filter(([, value]) => value !== '' && value !== 'all'));
}

function Field({ label, value, onChange, type = 'text' }: { label: string; value: string; onChange: (value: string) => void; type?: string }) {
    return (
        <label className="text-xs font-semibold text-slate-600">
            {label}
            <input type={type} value={value} onChange={(event) => onChange(event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
        </label>
    );
}

function Select({ label, value, onChange, children }: { label: string; value: string; onChange: (value: string) => void; children: ReactNode }) {
    return (
        <label className="text-xs font-semibold text-slate-600">
            {label}
            <select value={value} onChange={(event) => onChange(event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                {children}
            </select>
        </label>
    );
}
