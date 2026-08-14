import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ConfirmDialog from '@/Components/ConfirmDialog';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, ReactNode, useState } from 'react';

type Page<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    from: number | null;
    to: number | null;
    total: number;
};

type Option = { id: number; name: string };

type PreSale = {
    id: number;
    status: string;
    total: string;
    created_at: string;
    submitted_at?: string | null;
    picked_at?: string | null;
    reserved_quantity_total?: string | number;
    picked_quantity_total?: string | number;
    items_count: number;
    customer?: { name: string; commercial_name?: string | null; contact_name?: string | null; doc_number: string | null };
    seller?: { name: string };
    zone?: { name: string } | null;
    branch?: { name: string };
    work_day?: { work_date?: string | null; status?: string | null };
};

type Props = {
    preSales: Page<PreSale>;
    filters: Record<string, string>;
    branches: Option[];
    sellers: Option[];
    zones: Option[];
};

const statuses = [
    { value: '', label: 'Enviadas, en preparación y listas' },
    { value: 'submitted', label: 'Enviadas' },
    { value: 'processing', label: 'En preparación' },
    { value: 'picked', label: 'Listas para facturar' },
    { value: 'cancelled', label: 'Canceladas' },
    { value: 'draft', label: 'Borradores' },
];

const cancellationReasons = ['Cliente canceló', 'Producto no disponible', 'Duplicada', 'Error de captura', 'Otro'];

export default function Index({ preSales, filters, branches, sellers, zones }: Props) {
    const [cancelTarget, setCancelTarget] = useState<PreSale | null>(null);
    const [processingTarget, setProcessingTarget] = useState<PreSale | null>(null);
    const [processingPreSaleId, setProcessingPreSaleId] = useState<number | null>(null);
    const cancelForm = useForm({
        cancellation_reason: '',
        cancellation_note: '',
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const values = Object.fromEntries(new FormData(event.currentTarget).entries());
        router.get(route('routes.pre-sales.index'), values, { preserveState: true, preserveScroll: true });
    };

    const clear = () => router.get(route('routes.pre-sales.index'), {}, { preserveScroll: true });

    const confirmMarkProcessing = () => {
        if (!processingTarget || processingPreSaleId !== null) {
            return;
        }

        setProcessingPreSaleId(processingTarget.id);
        router.post(route('routes.pre-sales.processing', processingTarget.id), {}, {
            preserveScroll: true,
            onFinish: () => setProcessingPreSaleId(null),
            onSuccess: () => setProcessingTarget(null),
        });
    };

    const submitCancellation = (event: FormEvent) => {
        event.preventDefault();
        if (!cancelTarget) {
            return;
        }

        cancelForm.post(route('routes.pre-sales.cancel', cancelTarget.id), {
            preserveScroll: true,
            onSuccess: () => {
                setCancelTarget(null);
                cancelForm.reset();
            },
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Preventas enviadas" />
            <div className="mx-auto max-w-[1800px] space-y-5 px-4 py-6 sm:px-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold text-slate-950">Preventas enviadas</h1>
                        <p className="text-sm text-slate-500">Cola administrativa para revisar pedidos enviados desde rutas.</p>
                    </div>
                </div>

                <form onSubmit={submit} className="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 md:grid-cols-4 xl:grid-cols-8">
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
                    <Field label="Cliente">
                        <input name="customer" defaultValue={filters.customer ?? ''} placeholder="Nombre / NIT" className="h-10 rounded-lg border-slate-200 text-sm" />
                    </Field>
                    <Field label="Producto">
                        <input name="product_search" defaultValue={filters.product_search ?? ''} placeholder="Producto / código" className="h-10 rounded-lg border-slate-200 text-sm" />
                    </Field>

                    <div className="flex items-end gap-2 md:col-span-4 xl:col-span-8">
                        <button className="h-10 rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white">Filtrar</button>
                        <button type="button" onClick={clear} className="h-10 rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">Limpiar</button>
                    </div>
                </form>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
                                <tr>
                                    <th className="px-4 py-3">Fecha</th>
                                    <th className="px-4 py-3">Sucursal</th>
                                    <th className="px-4 py-3">Zona</th>
                                    <th className="px-4 py-3">Vendedor</th>
                                    <th className="px-4 py-3">Cliente</th>
                                    <th className="px-4 py-3">Items</th>
                                    <th className="px-4 py-3">Reservado</th>
                                    <th className="px-4 py-3">Preparado</th>
                                    <th className="px-4 py-3">Total</th>
                                    <th className="px-4 py-3">Estado</th>
                                    <th className="w-[240px] min-w-[240px] px-4 py-3">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {preSales.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={11} className="px-4 py-10 text-center text-slate-500">No hay preventas para los filtros seleccionados.</td>
                                    </tr>
                                ) : preSales.data.map((preSale) => (
                                    <tr key={preSale.id} className="hover:bg-slate-50/70">
                                        <td className="whitespace-nowrap px-4 py-3">
                                            {formatDate(preSale.submitted_at ?? preSale.created_at)}
                                            {preSale.work_day?.work_date && <div className="text-xs text-slate-500">Jornada {preSale.work_day.work_date}</div>}
                                        </td>
                                        <td className="px-4 py-3">{preSale.branch?.name ?? '-'}</td>
                                        <td className="px-4 py-3">{preSale.zone?.name ?? '-'}</td>
                                        <td className="px-4 py-3">{preSale.seller?.name ?? '-'}</td>
                                        <td className="px-4 py-3 font-medium">
                                            {preSale.customer?.commercial_name || preSale.customer?.name || '-'}
                                            {preSale.customer?.commercial_name && preSale.customer?.name && <div className="text-xs text-slate-500">{preSale.customer.name}</div>}
                                            <div className="text-xs text-slate-500">{preSale.customer?.doc_number ?? '-'}</div>
                                            {preSale.customer?.contact_name && <div className="text-xs text-slate-500">Contacto: {preSale.customer.contact_name}</div>}
                                        </td>
                                        <td className="px-4 py-3">{preSale.items_count}</td>
                                        <td className="px-4 py-3">{formatNumber(preSale.reserved_quantity_total)}</td>
                                        <td className="px-4 py-3">
                                            {formatNumber(preSale.picked_quantity_total)}
                                            {preSale.picked_at && <div className="text-xs text-slate-500">{formatDate(preSale.picked_at)}</div>}
                                        </td>
                                        <td className="px-4 py-3">Q {Number(preSale.total).toFixed(2)}</td>
                                        <td className="px-4 py-3"><StatusBadge status={preSale.status} /></td>
                                        <td className="w-[240px] min-w-[240px] px-4 py-3">
                                            <div className="flex items-center gap-1.5 whitespace-nowrap">
                                                <Link href={route('routes.pre-sales.show', preSale.id)} className="rounded-md border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">
                                                    Ver
                                                </Link>
                                                {preSale.status === 'submitted' && (
                                                    <button
                                                        type="button"
                                                        disabled={processingPreSaleId !== null || cancelForm.processing}
                                                        onClick={() => setProcessingTarget(preSale)}
                                                        className="rounded-md bg-indigo-600 px-2 py-1 text-[11px] font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                    >
                                                        Prep.
                                                    </button>
                                                )}
                                                {['submitted', 'processing'].includes(preSale.status) && (
                                                    <Link href={route('routes.pre-sales.pick', preSale.id)} className="rounded-md bg-emerald-600 px-2 py-1 text-[11px] font-semibold text-white hover:bg-emerald-700">
                                                        Pick
                                                    </Link>
                                                )}
                                                {['submitted', 'processing'].includes(preSale.status) && (
                                                    <button
                                                        type="button"
                                                        disabled={processingPreSaleId !== null || cancelForm.processing}
                                                        onClick={() => setCancelTarget(preSale)}
                                                        className="rounded-md bg-red-600 px-2 py-1 text-[11px] font-semibold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                    >
                                                        Cancelar
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 text-sm text-slate-500">
                        <span>{preSales.from && preSales.to ? `${preSales.from}-${preSales.to} de ${preSales.total}` : `${preSales.total} resultados`}</span>
                        <div className="flex flex-wrap gap-1">
                            {preSales.links.map((link, index) => (
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
            </div>

            <ConfirmDialog
                open={processingTarget !== null}
                title="Marcar en preparación"
                message="¿Deseas marcar esta preventa como en preparación?"
                details="Las reservas se mantendrán activas y aún no se generará venta."
                confirmLabel="Sí, marcar"
                processing={processingPreSaleId !== null}
                onCancel={() => {
                    if (processingPreSaleId === null) {
                        setProcessingTarget(null);
                    }
                }}
                onConfirm={confirmMarkProcessing}
            />

            {cancelTarget && (
                <div className="fixed inset-0 z-50 flex items-end bg-slate-950/50 p-4 sm:items-center sm:justify-center">
                    <form onSubmit={submitCancellation} className="w-full rounded-xl bg-white p-5 shadow-xl sm:max-w-lg">
                        <h2 className="text-lg font-semibold text-slate-950">Cancelar preventa</h2>
                        <p className="mt-2 text-sm text-slate-600">¿Seguro que deseas cancelar esta preventa?</p>
                        <p className="mt-2 text-sm font-semibold text-slate-800">Esta acción liberará las reservas de stock asociadas.</p>
                        <label className="mt-4 block">
                            <span className="text-xs font-semibold text-slate-500">Motivo</span>
                            <select value={cancelForm.data.cancellation_reason} onChange={(event) => cancelForm.setData('cancellation_reason', event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-200 text-sm">
                                <option value="">Selecciona</option>
                                {cancellationReasons.map((reason) => <option key={reason} value={reason}>{reason}</option>)}
                            </select>
                            {cancelForm.errors.cancellation_reason && <p className="mt-1 text-xs font-semibold text-red-600">{cancelForm.errors.cancellation_reason}</p>}
                        </label>
                        <label className="mt-3 block">
                            <span className="text-xs font-semibold text-slate-500">Observación</span>
                            <textarea value={cancelForm.data.cancellation_note} onChange={(event) => cancelForm.setData('cancellation_note', event.target.value)} rows={4} className="mt-1 w-full rounded-lg border-slate-200 text-sm" />
                            {cancelForm.errors.cancellation_note && <p className="mt-1 text-xs font-semibold text-red-600">{cancelForm.errors.cancellation_note}</p>}
                        </label>
                        <div className="mt-5 flex justify-end gap-2">
                            <button type="button" onClick={() => setCancelTarget(null)} disabled={cancelForm.processing} className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60">
                                No cancelar
                            </button>
                            <button disabled={cancelForm.processing} className="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-60">
                                {cancelForm.processing ? 'Cancelando...' : 'Sí, cancelar'}
                            </button>
                        </div>
                    </form>
                </div>
            )}
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

function StatusBadge({ status }: { status: string }) {
    const styles: Record<string, string> = {
        submitted: 'bg-sky-50 text-sky-700',
        processing: 'bg-amber-50 text-amber-700',
        picked: 'bg-emerald-50 text-emerald-700',
        cancelled: 'bg-red-50 text-red-700',
        draft: 'bg-slate-100 text-slate-700',
    };

    const labels: Record<string, string> = {
        submitted: 'Enviada',
        processing: 'En preparación',
        picked: 'Listo para facturar',
        cancelled: 'Cancelada',
        draft: 'Borrador',
    };

    return <span className={`rounded-full px-2 py-1 text-xs font-semibold ${styles[status] ?? 'bg-slate-100 text-slate-700'}`}>{labels[status] ?? status}</span>;
}

function formatDate(value?: string | null) {
    return value ? new Date(value).toLocaleString() : '-';
}

function formatNumber(value: unknown) {
    return Number(value ?? 0).toLocaleString('en-US', { maximumFractionDigits: 2 });
}
