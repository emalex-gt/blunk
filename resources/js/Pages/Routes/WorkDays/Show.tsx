import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ConfirmDialog from '@/Components/ConfirmDialog';
import { makeOperationKey } from '@/lib/idempotency';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, ReactNode, useRef, useState } from 'react';

type Page<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    from: number | null;
    to: number | null;
    total: number;
};

type Related = { id: number; name: string };

type WorkDay = {
    id: number;
    work_date?: string | null;
    status: string;
    started_at?: string | null;
    closed_at?: string | null;
    completed_at?: string | null;
    completed_by?: Related | null;
    branch?: Related | null;
    zone?: Related | null;
    seller?: Related | null;
    summary: {
        total_clients: number;
        visited: number;
        with_pre_sale: number;
        without_sale: number;
        pending: number;
        pre_sales_total: number;
        prepared_total: number;
        converted_total: number;
    };
};

type PreSale = {
    id: number;
    branch_id: number;
    status: string;
    total: string | number;
    created_at: string;
    submitted_at?: string | null;
    picked_at?: string | null;
    converted_sale_id?: number | null;
    reserved_quantity_total?: string | number;
    picked_quantity_total?: string | number;
    items_count: number;
    customer?: { name: string; commercial_name?: string | null; contact_name?: string | null; doc_number: string | null };
    seller?: { name: string };
    zone?: { name: string } | null;
    branch?: { name: string };
};

type Props = {
    workDay: WorkDay;
    preSales: Page<PreSale>;
    canInvoice: boolean;
    activeBranchId: number;
};

const cancellationReasons = ['Cliente canceló', 'Producto no disponible', 'Duplicada', 'Error de captura', 'Otro'];

export default function Show({ workDay, preSales, canInvoice, activeBranchId }: Props) {
    const [cancelTarget, setCancelTarget] = useState<PreSale | null>(null);
    const [processingTarget, setProcessingTarget] = useState<PreSale | null>(null);
    const [processingPreSaleId, setProcessingPreSaleId] = useState<number | null>(null);
    const processingLockedRef = useRef(false);
    const cancelForm = useForm({
        idempotency_key: makeOperationKey('pre-sale-cancel'),
        cancellation_reason: '',
        cancellation_note: '',
    });

    const confirmMarkProcessing = () => {
        if (!processingTarget || processingPreSaleId !== null || processingLockedRef.current) {
            return;
        }

        processingLockedRef.current = true;
        setProcessingPreSaleId(processingTarget.id);
        router.post(route('routes.pre-sales.processing', processingTarget.id), {
            idempotency_key: makeOperationKey('pre-sale-processing'),
        }, {
            preserveScroll: true,
            onFinish: () => {
                processingLockedRef.current = false;
                setProcessingPreSaleId(null);
            },
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
                cancelForm.setData({
                    idempotency_key: makeOperationKey('pre-sale-cancel'),
                    cancellation_reason: '',
                    cancellation_note: '',
                });
            },
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title={`Jornada ${workDay.work_date ?? `#${workDay.id}`}`} />
            <div className="mx-auto max-w-[1800px] space-y-5 px-4 py-6 sm:px-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <Link href={route('routes.work-days.closed')} className="text-sm font-semibold text-indigo-600 hover:text-indigo-800">Volver a jornadas cerradas</Link>
                        <h1 className="mt-2 text-2xl font-semibold text-slate-950">Jornada {workDay.work_date ?? `#${workDay.id}`}</h1>
                        <p className="text-sm text-slate-500">Detalle administrativo de visitas y preventas de la jornada.</p>
                    </div>
                    <Link href={route('routes.pre-sales.index')} className="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Cola de preventas
                    </Link>
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <InfoCard title="Ruta">
                        <Info label="Sucursal" value={workDay.branch?.name} />
                        <Info label="Zona" value={workDay.zone?.name} />
                        <Info label="Vendedor" value={workDay.seller?.name} />
                        <Info label="Inicio" value={formatDate(workDay.started_at)} />
                        <Info label="Cierre" value={formatDate(workDay.closed_at)} />
                    </InfoCard>
                    <InfoCard title="Estado">
                        <Info label="Estado" value={workDay.completed_at ? 'Finalizada' : 'Cerrada'} />
                        <Info label="Finalizada" value={formatDate(workDay.completed_at)} />
                        <Info label="Finalizada por" value={workDay.completed_by?.name} />
                    </InfoCard>
                    <InfoCard title="Resumen monetario">
                        <Info label="Total preventas" value={`Q ${formatMoney(workDay.summary.pre_sales_total)}`} />
                        <Info label="Total preparado" value={`Q ${formatMoney(workDay.summary.prepared_total)}`} />
                        <Info label="Total facturado" value={`Q ${formatMoney(workDay.summary.converted_total)}`} />
                    </InfoCard>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <SummaryCard label="Clientes" value={workDay.summary.total_clients} />
                    <SummaryCard label="Visitados" value={workDay.summary.visited} />
                    <SummaryCard label="Con preventa" value={workDay.summary.with_pre_sale} />
                    <SummaryCard label="Sin venta" value={workDay.summary.without_sale} />
                    <SummaryCard label="Pendientes" value={workDay.summary.pending} />
                </div>

                <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
                        <div>
                            <h2 className="text-sm font-semibold text-slate-900">Preventas de la jornada</h2>
                            <p className="text-xs text-slate-500">Solo se muestran pedidos asociados a esta jornada.</p>
                        </div>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-[1180px] divide-y divide-slate-200 text-sm">
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
                                        <td colSpan={11} className="px-4 py-10 text-center text-slate-500">Esta jornada no tiene preventas.</td>
                                    </tr>
                                ) : preSales.data.map((preSale) => (
                                    <tr key={preSale.id} className="hover:bg-slate-50/70">
                                        <td className="whitespace-nowrap px-4 py-3">{formatDate(preSale.submitted_at ?? preSale.created_at)}</td>
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
                                        <td className="px-4 py-3">Q {formatMoney(preSale.total)}</td>
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
                                                {preSale.status === 'picked' && canInvoice && preSale.branch_id === activeBranchId && (
                                                    <Link href={route('routes.pre-sales.show', preSale.id)} className="rounded-md bg-violet-600 px-2 py-1 text-[11px] font-semibold text-white hover:bg-violet-700">
                                                        Facturar
                                                    </Link>
                                                )}
                                                {preSale.status === 'converted' && preSale.converted_sale_id && (
                                                    <Link href={route('sales.show', preSale.converted_sale_id)} className="rounded-md bg-violet-600 px-2 py-1 text-[11px] font-semibold text-white hover:bg-violet-700">
                                                        Venta
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
                    <Pagination page={preSales} />
                </section>
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

function InfoCard({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <h2 className="text-sm font-semibold text-slate-900">{title}</h2>
            <div className="mt-3 space-y-2">{children}</div>
        </section>
    );
}

function Info({ label, value }: { label: string; value?: string | number | null }) {
    return (
        <div>
            <div className="text-xs font-semibold uppercase text-slate-500">{label}</div>
            <div className="text-sm text-slate-800">{value || '-'}</div>
        </div>
    );
}

function SummaryCard({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div className="text-xs font-semibold uppercase text-slate-500">{label}</div>
            <div className="mt-1 text-2xl font-semibold text-slate-950">{value}</div>
        </div>
    );
}

function StatusBadge({ status }: { status: string }) {
    const styles: Record<string, string> = {
        submitted: 'bg-sky-50 text-sky-700',
        processing: 'bg-amber-50 text-amber-700',
        picked: 'bg-emerald-50 text-emerald-700',
        cancelled: 'bg-red-50 text-red-700',
        converted: 'bg-violet-50 text-violet-700',
        draft: 'bg-slate-100 text-slate-700',
    };

    const labels: Record<string, string> = {
        submitted: 'Enviada',
        processing: 'En preparación',
        picked: 'Lista para facturar',
        cancelled: 'Cancelada',
        converted: 'Facturada',
        draft: 'Borrador',
    };

    return <span className={`rounded-full px-2 py-1 text-xs font-semibold ${styles[status] ?? 'bg-slate-100 text-slate-700'}`}>{labels[status] ?? status}</span>;
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

function formatNumber(value: unknown) {
    return Number(value ?? 0).toLocaleString('en-US', { maximumFractionDigits: 2 });
}
