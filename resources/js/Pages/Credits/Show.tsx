import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatCurrency } from '@/utils/currency';
import { Head, Link, usePage } from '@inertiajs/react';

type CreditReceipt = {
    id: number;
    receipt_number: number;
    created_at: string;
    status: string;
    total: string | number;
    pending_total: string | number;
    customer_name: string;
    customer_doc_number: string;
    lines: {
        id: number;
        product_name: string;
        quantity: number;
        qty_pending: number;
        unit_price: string | number;
        line_total: string | number;
        status: string;
    }[];
};

export default function Show({ receipt }: { receipt: CreditReceipt }) {
    const country = (usePage().props.business as { country?: string } | null)?.country ?? 'GT';

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Recibo CR-{receipt.receipt_number}</h2>}
        >
            <Head title={`CR-${receipt.receipt_number}`} />

            <div className="py-6">
                <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                    <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div className="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h1 className="text-xl font-semibold text-slate-950">CR-{receipt.receipt_number}</h1>
                                <p className="text-sm text-slate-500">{new Date(receipt.created_at).toLocaleString()}</p>
                                <p className="mt-2 text-sm text-slate-700">{receipt.customer_name} · {receipt.customer_doc_number}</p>
                            </div>
                            <Link href={route('credits.receipts.print', receipt.id)} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">
                                Imprimir
                            </Link>
                        </div>

                        <table className="min-w-full divide-y divide-slate-200 text-sm">
                            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-4 py-3">Producto</th>
                                    <th className="px-4 py-3 text-right">Cantidad</th>
                                    <th className="px-4 py-3 text-right">Pendiente</th>
                                    <th className="px-4 py-3 text-right">Precio</th>
                                    <th className="px-4 py-3 text-right">Total</th>
                                    <th className="px-4 py-3">Estado</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {receipt.lines.map((line) => (
                                    <tr key={line.id}>
                                        <td className="px-4 py-3">{line.product_name}</td>
                                        <td className="px-4 py-3 text-right">{line.quantity}</td>
                                        <td className="px-4 py-3 text-right">{line.qty_pending}</td>
                                        <td className="px-4 py-3 text-right">{formatCurrency(Number(line.unit_price), country)}</td>
                                        <td className="px-4 py-3 text-right">{formatCurrency(Number(line.line_total), country)}</td>
                                        <td className="px-4 py-3">{line.status}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>

                        <div className="mt-5 text-right">
                            <div>Total: <strong>{formatCurrency(Number(receipt.total), country)}</strong></div>
                            <div>Pendiente: <strong>{formatCurrency(Number(receipt.pending_total), country)}</strong></div>
                        </div>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
