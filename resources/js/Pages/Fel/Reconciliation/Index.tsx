import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';

type Reconciliation = {
    id: number;
    internal_reference: string;
    sale_id: number | null;
    sale_number: string | null;
    issued_date: string | null;
    provider: string;
    environment: string;
    status: string;
    last_error: string | null;
    attempts: number;
    branch: string | null;
    checked_at: string | null;
    resolved_at: string | null;
    response?: {
        authNumber?: string | null;
        serial?: string | null;
        batch?: string | null;
        receiverTaxID?: string | null;
        receiverName?: string | null;
    } | null;
};

type PageData = {
    data: Reconciliation[];
    links: { url: string | null; label: string; active: boolean }[];
};

export default function Index({ requests }: { requests: PageData }) {
    const errors = (usePage().props.errors ?? {}) as Record<string, string>;

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-900">Reconciliación FEL</h2>}>
            <Head title="Reconciliación FEL" />
            <div className="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
                {errors.reconciliation && (
                    <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                        {errors.reconciliation}
                    </div>
                )}
                <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm">
                            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                                <tr>
                                    <th className="px-4 py-3">Referencia interna</th>
                                    <th className="px-4 py-3">Venta</th>
                                    <th className="px-4 py-3">Fecha emisión</th>
                                    <th className="px-4 py-3">Estado</th>
                                    <th className="px-4 py-3">Intentos</th>
                                    <th className="px-4 py-3">Respuesta</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {requests.data.map((item) => (
                                    <tr key={item.id}>
                                        <td className="px-4 py-3 font-semibold text-slate-900">
                                            {item.internal_reference}
                                            <div className="text-xs font-normal text-slate-500">{item.branch ?? '-'} · {item.environment}</div>
                                        </td>
                                        <td className="px-4 py-3">{item.sale_number ?? (item.sale_id ? `ID ${item.sale_id}` : 'Venta revertida')}</td>
                                        <td className="px-4 py-3">{item.issued_date ?? '-'}</td>
                                        <td className="px-4 py-3">
                                            <span className="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">{item.status}</span>
                                            {item.last_error && <div className="mt-1 max-w-xs text-xs text-red-600">{item.last_error}</div>}
                                        </td>
                                        <td className="px-4 py-3">{item.attempts}</td>
                                        <td className="px-4 py-3 text-xs text-slate-600">
                                            {item.response?.authNumber ? (
                                                <>
                                                    <div>Autorización: {item.response.authNumber}</div>
                                                    <div>Serie: {item.response.batch ?? '-'}</div>
                                                    <div>Número: {item.response.serial ?? '-'}</div>
                                                </>
                                            ) : '-'}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {item.status !== 'resolved' && (
                                                <button
                                                    type="button"
                                                    onClick={() => router.post(route('fel.reconciliation.check', item.id), {}, { preserveScroll: true })}
                                                    className="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-700"
                                                >
                                                    Consultar Digifact
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {requests.data.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="px-4 py-12 text-center text-slate-500">
                                            No hay conciliaciones FEL pendientes.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                    <div className="flex flex-wrap gap-2 border-t border-slate-200 p-4">
                        {requests.links.map((link, index) =>
                            link.url ? (
                                <Link
                                    key={index}
                                    href={link.url}
                                    preserveState
                                    className={`rounded-lg px-3 py-1.5 text-sm ${link.active ? 'bg-indigo-600 text-white' : 'border border-slate-200 text-slate-700'}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ) : null,
                        )}
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
