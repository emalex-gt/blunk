import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatCurrency } from '@/utils/currency';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Customer = {
    id: number;
    name: string;
    doc_number: string | null;
    address: string | null;
};

type ResolvedTransferCustomer = {
    id: number;
    name: string;
    doc_number: string;
};

type CreditLine = {
    id: number;
    credit_receipt_id: number;
    product_name: string;
    qty_pending: number;
    unit_price: string | number;
    pending_total: string | number;
    status: string;
};

type CreditReceipt = {
    id: number;
    receipt_number: number;
    created_at: string;
    total: string | number;
    pending_total: string | number;
    status: string;
    lines: CreditLine[];
};

export default function CustomerCredit({
    customer,
    receipts,
    pending_total,
    pending_lines,
}: {
    customer: Customer;
    receipts: CreditReceipt[];
    pending_total: number;
    pending_lines: CreditLine[];
}) {
    const page = usePage().props as {
        business?: { country?: string } | null;
        auth?: { permissions?: string[]; user?: { is_super_admin?: boolean } | null };
    };
    const country = page.business?.country ?? 'GT';
    const permissions = page.auth?.permissions ?? [];
    const isSuperAdmin = Boolean(page.auth?.user?.is_super_admin);
    const canInvoice = isSuperAdmin || permissions.includes('credits.invoice');
    const canCancel = isSuperAdmin || permissions.includes('credits.cancel_lines');
    const canTransfer = isSuperAdmin || permissions.includes('credits.transfer_customer');
    const [selected, setSelected] = useState<number[]>([]);
    const [transferNit, setTransferNit] = useState('');
    const [transferReason, setTransferReason] = useState('');
    const [resolvedTransferCustomer, setResolvedTransferCustomer] = useState<ResolvedTransferCustomer | null>(null);
    const [transferLookupLoading, setTransferLookupLoading] = useState(false);
    const [transferLookupError, setTransferLookupError] = useState('');

    function toggleLine(id: number, checked: boolean) {
        setSelected((items) => checked ? Array.from(new Set([...items, id])) : items.filter((item) => item !== id));
    }

    function invoiceSelection() {
        if (selected.length === 0) {
            return;
        }

        router.post(route('credits.invoice-selection'), { line_ids: selected });
    }

    function cancelLine(line: CreditLine) {
        const reason = window.prompt('Motivo de cancelación');

        if (!reason) {
            return;
        }

        router.delete(route('credits.lines.cancel', line.id), { data: { reason } });
    }

    async function lookupTransferNit() {
        const nit = transferNit.trim();
        setResolvedTransferCustomer(null);
        setTransferLookupError('');

        if (!nit) {
            return;
        }

        setTransferLookupLoading(true);

        try {
            const response = await fetch(`${route('credits.resolve-nit')}?nit=${encodeURIComponent(nit)}`, {
                headers: { Accept: 'application/json' },
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(payload?.message || 'No se pudo validar el NIT. Verifica el número e inténtalo nuevamente.');
            }

            setResolvedTransferCustomer(payload.customer);
        } catch (error) {
            setTransferLookupError(error instanceof Error ? error.message : 'No se pudo validar el NIT. Verifica el número e inténtalo nuevamente.');
        } finally {
            setTransferLookupLoading(false);
        }
    }

    function transferDebt() {
        if (!resolvedTransferCustomer || !transferReason.trim()) {
            return;
        }

        router.post(route('credits.customers.transfer', customer.id), {
            to_customer_doc_number: transferNit.trim(),
            reason: transferReason,
        });
    }

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Crédito de cliente</h2>}
        >
            <Head title={`Crédito - ${customer.name}`} />

            <div className="py-6">
                <div className="mx-auto grid max-w-7xl gap-5 px-4 sm:px-6 lg:px-8">
                    <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h1 className="text-xl font-semibold text-slate-950">{customer.name}</h1>
                                <p className="text-sm text-slate-500">NIT: {customer.doc_number ?? '-'}</p>
                                {customer.address && <p className="text-sm text-slate-500">Dirección: {customer.address}</p>}
                            </div>
                            <div className="rounded-lg bg-indigo-50 px-4 py-3 text-right">
                                <div className="text-xs font-semibold uppercase text-indigo-600">Saldo pendiente</div>
                                <div className="text-2xl font-bold text-indigo-900">{formatCurrency(Number(pending_total), country)}</div>
                            </div>
                        </div>
                    </section>

                    <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div className="mb-4 flex items-center justify-between">
                            <h2 className="text-lg font-semibold text-slate-950">Recibos pendientes</h2>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-sm">
                                <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th className="px-4 py-3">Recibo</th>
                                        <th className="px-4 py-3">Fecha</th>
                                        <th className="px-4 py-3 text-right">Total</th>
                                        <th className="px-4 py-3 text-right">Pendiente</th>
                                        <th className="px-4 py-3">Estado</th>
                                        <th className="px-4 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {receipts.map((receipt) => (
                                        <tr key={receipt.id}>
                                            <td className="px-4 py-3 font-semibold">CR-{receipt.receipt_number}</td>
                                            <td className="px-4 py-3">{new Date(receipt.created_at).toLocaleString()}</td>
                                            <td className="px-4 py-3 text-right">{formatCurrency(Number(receipt.total), country)}</td>
                                            <td className="px-4 py-3 text-right font-semibold">{formatCurrency(Number(receipt.pending_total), country)}</td>
                                            <td className="px-4 py-3">{receipt.status}</td>
                                            <td className="px-4 py-3 text-right">
                                                <Link href={route('credits.receipts.print', receipt.id)} className="font-semibold text-indigo-600">
                                                    Imprimir
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <h2 className="text-lg font-semibold text-slate-950">Productos pendientes</h2>
                            <div className="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    onClick={() => setSelected(pending_lines.map((line) => line.id))}
                                    className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700"
                                >
                                    Seleccionar todo
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setSelected([])}
                                    className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700"
                                >
                                    Limpiar selección
                                </button>
                                {canInvoice && (
                                    <button
                                        type="button"
                                        onClick={invoiceSelection}
                                        disabled={selected.length === 0}
                                        className="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white disabled:bg-slate-300"
                                    >
                                        Facturar selección
                                    </button>
                                )}
                            </div>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-sm">
                                <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th className="px-4 py-3"></th>
                                        <th className="px-4 py-3">Producto</th>
                                        <th className="px-4 py-3 text-right">Pendiente</th>
                                        <th className="px-4 py-3 text-right">Precio</th>
                                        <th className="px-4 py-3 text-right">Total</th>
                                        <th className="px-4 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {pending_lines.map((line) => (
                                        <tr key={line.id}>
                                            <td className="px-4 py-3">
                                                <input
                                                    type="checkbox"
                                                    checked={selected.includes(line.id)}
                                                    onChange={(event) => toggleLine(line.id, event.target.checked)}
                                                />
                                            </td>
                                            <td className="px-4 py-3">{line.product_name}</td>
                                            <td className="px-4 py-3 text-right">{line.qty_pending}</td>
                                            <td className="px-4 py-3 text-right">{formatCurrency(Number(line.unit_price), country)}</td>
                                            <td className="px-4 py-3 text-right font-semibold">{formatCurrency(Number(line.pending_total), country)}</td>
                                            <td className="px-4 py-3 text-right">
                                                {canCancel && (
                                                    <button
                                                        type="button"
                                                        onClick={() => cancelLine(line)}
                                                        className="font-semibold text-red-600"
                                                    >
                                                        Cancelar línea
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    {canTransfer && (
                        <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h2 className="text-lg font-semibold text-slate-950">Asignar deuda a otro NIT</h2>
                            <div className="mt-4 grid gap-3 md:grid-cols-[220px_1fr_1fr_auto]">
                                <div>
                                    <input
                                        value={transferNit}
                                        onChange={(event) => {
                                            setTransferNit(event.target.value);
                                            setResolvedTransferCustomer(null);
                                            setTransferLookupError('');
                                        }}
                                        onBlur={lookupTransferNit}
                                        placeholder="NIT destino"
                                        className="w-full rounded-lg border-slate-300 text-sm"
                                    />
                                    {transferLookupLoading && (
                                        <p className="mt-1 text-xs font-semibold text-indigo-600">Consultando NIT...</p>
                                    )}
                                    {transferLookupError && (
                                        <p className="mt-1 text-xs font-semibold text-red-600">{transferLookupError}</p>
                                    )}
                                </div>
                                <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                                    {resolvedTransferCustomer ? (
                                        <>
                                            <div className="font-semibold text-slate-900">{resolvedTransferCustomer.name}</div>
                                            <div className="text-xs text-slate-500">NIT {resolvedTransferCustomer.doc_number}</div>
                                        </>
                                    ) : (
                                        <span className="text-slate-500">Valida el NIT para continuar.</span>
                                    )}
                                </div>
                                <input
                                    value={transferReason}
                                    onChange={(event) => setTransferReason(event.target.value)}
                                    placeholder="Motivo"
                                    className="rounded-lg border-slate-300 text-sm"
                                />
                                <button
                                    type="button"
                                    onClick={transferDebt}
                                    disabled={!resolvedTransferCustomer || !transferReason.trim() || transferLookupLoading}
                                    className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white"
                                >
                                    Transferir
                                </button>
                            </div>
                        </section>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
