import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, ReactNode } from 'react';

type Page<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    from: number | null;
    to: number | null;
    total: number;
};

type Option = { id: number; name: string };

type WorkDay = {
    id: number;
    work_date: string;
    status: string;
    closed_at?: string | null;
    completed_at?: string | null;
    total_clients_count: number;
    visited_clients_count: number;
    pre_sales_count: number;
    without_sale_count: number;
    pre_sales_total: string | number;
    branch?: Option | null;
    zone?: Option | null;
    seller?: Option | null;
};

type Props = {
    workDays: Page<WorkDay>;
    filters: Record<string, string>;
    branches: Option[];
    sellers: Option[];
    zones: Option[];
};

const statuses = [
    { value: '', label: 'Todas cerradas' },
    { value: 'closed', label: 'Pendientes de finalizar' },
    { value: 'completed', label: 'Finalizadas' },
];

export default function ClosedIndex({ workDays, filters, branches, sellers, zones }: Props) {
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const values = Object.fromEntries(new FormData(event.currentTarget).entries());
        router.get(route('routes.work-days.closed'), values, { preserveState: true, preserveScroll: true });
    };

    const clear = () => router.get(route('routes.work-days.closed'), {}, { preserveScroll: true });

    return (
        <AuthenticatedLayout>
            <Head title="Jornadas cerradas" />
            <div className="mx-auto max-w-[1800px] space-y-5 px-4 py-6 sm:px-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold text-slate-950">Jornadas cerradas</h1>
                        <p className="text-sm text-slate-500">Revisión administrativa de jornadas de ruta cerradas y su avance de preventas.</p>
                    </div>
                    <Link href={route('routes.pre-sales.index')} className="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Ver preventas
                    </Link>
                </div>

                <form onSubmit={submit} className="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 md:grid-cols-3 xl:grid-cols-7">
                    <Field label="Desde">
                        <input name="date_from" type="date" defaultValue={filters.date_from ?? ''} className="h-10 rounded-lg border-slate-200 text-sm" />
                    </Field>
                    <Field label="Hasta">
                        <input name="date_to" type="date" defaultValue={filters.date_to ?? ''} className="h-10 rounded-lg border-slate-200 text-sm" />
                    </Field>
                    <Field label="Sucursal">
                        <select name="branch_id" defaultValue={filters.branch_id ?? ''} className="h-10 rounded-lg border-slate-200 text-sm">
                            <option value="">Todas</option>
                            {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                        </select>
                    </Field>
                    <Field label="Zona">
                        <select name="zone_id" defaultValue={filters.zone_id ?? ''} className="h-10 rounded-lg border-slate-200 text-sm">
                            <option value="">Todas</option>
                            {zones.map((zone) => <option key={zone.id} value={zone.id}>{zone.name}</option>)}
                        </select>
                    </Field>
                    <Field label="Vendedor">
                        <select name="seller_id" defaultValue={filters.seller_id ?? ''} className="h-10 rounded-lg border-slate-200 text-sm">
                            <option value="">Todos</option>
                            {sellers.map((seller) => <option key={seller.id} value={seller.id}>{seller.name}</option>)}
                        </select>
                    </Field>
                    <Field label="Estado">
                        <select name="status" defaultValue={filters.status ?? ''} className="h-10 rounded-lg border-slate-200 text-sm">
                            {statuses.map((status) => <option key={status.value} value={status.value}>{status.label}</option>)}
                        </select>
                    </Field>
                    <Field label="Buscar">
                        <input name="search" defaultValue={filters.search ?? ''} placeholder="Vendedor o zona" className="h-10 rounded-lg border-slate-200 text-sm" />
                    </Field>

                    <div className="flex items-end gap-2 md:col-span-3 xl:col-span-7">
                        <button className="h-10 rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white">Filtrar</button>
                        <button type="button" onClick={clear} className="h-10 rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">Limpiar</button>
                    </div>
                </form>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="min-w-[1120px] divide-y divide-slate-200 text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
                                <tr>
                                    <th className="px-4 py-3">Fecha</th>
                                    <th className="px-4 py-3">Sucursal</th>
                                    <th className="px-4 py-3">Zona</th>
                                    <th className="px-4 py-3">Vendedor</th>
                                    <th className="px-4 py-3">Clientes visitados</th>
                                    <th className="px-4 py-3">Preventas</th>
                                    <th className="px-4 py-3">Sin venta</th>
                                    <th className="px-4 py-3">Total preventas</th>
                                    <th className="px-4 py-3">Estado</th>
                                    <th className="px-4 py-3">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {workDays.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={10} className="px-4 py-10 text-center text-slate-500">No hay jornadas cerradas para los filtros seleccionados.</td>
                                    </tr>
                                ) : workDays.data.map((workDay) => (
                                    <tr key={workDay.id} className="hover:bg-slate-50/70">
                                        <td className="whitespace-nowrap px-4 py-3">
                                            {workDay.work_date}
                                            {workDay.closed_at && <div className="text-xs text-slate-500">Cierre {formatDate(workDay.closed_at)}</div>}
                                        </td>
                                        <td className="px-4 py-3">{workDay.branch?.name ?? '-'}</td>
                                        <td className="px-4 py-3">{workDay.zone?.name ?? '-'}</td>
                                        <td className="px-4 py-3">{workDay.seller?.name ?? '-'}</td>
                                        <td className="px-4 py-3">{workDay.visited_clients_count} / {workDay.total_clients_count}</td>
                                        <td className="px-4 py-3">{workDay.pre_sales_count}</td>
                                        <td className="px-4 py-3">{workDay.without_sale_count}</td>
                                        <td className="px-4 py-3">Q {formatMoney(workDay.pre_sales_total)}</td>
                                        <td className="px-4 py-3"><WorkDayStatus completed={Boolean(workDay.completed_at)} /></td>
                                        <td className="px-4 py-3">
                                            <Link href={route('routes.work-days.show', workDay.id)} className="rounded-md border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                                Ver detalle
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <Pagination page={workDays} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <label className="flex flex-col gap-1">
            <span className="text-xs font-semibold text-slate-500">{label}</span>
            {children}
        </label>
    );
}

function WorkDayStatus({ completed }: { completed: boolean }) {
    return completed
        ? <span className="rounded-full bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700">Finalizada</span>
        : <span className="rounded-full bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-700">Cerrada</span>;
}

function Pagination<T>({ page }: { page: Page<T> }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 text-sm text-slate-500">
            <span>{page.from && page.to ? `${page.from}-${page.to} de ${page.total}` : `${page.total} resultados`}</span>
            <div className="flex flex-wrap gap-1">
                {page.links.map((link, index) => (
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
    );
}

function formatDate(value?: string | null) {
    return value ? new Date(value).toLocaleString() : '-';
}

function formatMoney(value: unknown) {
    return Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
