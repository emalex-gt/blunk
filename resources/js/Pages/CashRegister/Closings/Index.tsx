import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatCurrency } from '@/utils/currency';
import { Head, Link, usePage } from '@inertiajs/react';

type Session = {
    id: number;
    opened_at: string | null;
    closed_at: string | null;
    opened_by: string | null;
    closed_by: string | null;
    opening_amount: number;
    expected_cash: number;
    counted_cash: number | null;
    difference: number | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type Paginated<T> = {
    data: T[];
    links: PaginationLink[];
};

export default function Index({ sessions }: { sessions: Paginated<Session> }) {
    const business = usePage().props.business as { country?: string | null } | null;
    const country = business?.country ?? 'GT';

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-950">Cierres de caja</h2>}>
            <Head title="Cierres de caja" />

            <div className="py-5">
                <div className="mx-auto max-w-[1800px] space-y-5 px-5 sm:px-6">
                    <section className="rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h1 className="text-xl font-semibold text-slate-950">Cierres de caja</h1>
                                <p className="mt-1 text-sm text-slate-500">Historial de cajas cerradas del negocio.</p>
                            </div>
                            <Link href={route('cash-register.index')} className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                Volver a caja
                            </Link>
                        </div>
                    </section>

                    <section className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white/95 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead>
                                    <tr className="bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        <th className="px-4 py-3">Fecha apertura</th>
                                        <th className="px-4 py-3">Fecha cierre</th>
                                        <th className="px-4 py-3">Usuario apertura</th>
                                        <th className="px-4 py-3">Usuario cierre</th>
                                        <th className="px-4 py-3 text-right">Monto inicial</th>
                                        <th className="px-4 py-3 text-right">Efectivo esperado</th>
                                        <th className="px-4 py-3 text-right">Efectivo contado</th>
                                        <th className="px-4 py-3 text-right">Diferencia</th>
                                        <th className="px-4 py-3 text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {sessions.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={9} className="px-4 py-12 text-center text-slate-500">Sin cierres de caja</td>
                                        </tr>
                                    ) : sessions.data.map((session) => (
                                        <tr key={session.id} className="hover:bg-indigo-50/30">
                                            <td className="px-4 py-3 text-slate-600">{session.opened_at ?? '-'}</td>
                                            <td className="px-4 py-3 text-slate-600">{session.closed_at ?? '-'}</td>
                                            <td className="px-4 py-3 text-slate-600">{session.opened_by ?? '-'}</td>
                                            <td className="px-4 py-3 text-slate-600">{session.closed_by ?? '-'}</td>
                                            <td className="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-950">{formatCurrency(session.opening_amount, country)}</td>
                                            <td className="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-950">{formatCurrency(session.expected_cash, country)}</td>
                                            <td className="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-950">{formatCurrency(session.counted_cash ?? 0, country)}</td>
                                            <td className={`whitespace-nowrap px-4 py-3 text-right font-semibold ${(session.difference ?? 0) < 0 ? 'text-red-600' : (session.difference ?? 0) > 0 ? 'text-amber-600' : 'text-emerald-700'}`}>
                                                {formatCurrency(session.difference ?? 0, country)}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex justify-end gap-2">
                                                    <Link href={route('cash-register.closings.show', session.id)} className="rounded-lg px-3 py-1.5 text-sm font-semibold text-indigo-600 hover:bg-indigo-50">Ver</Link>
                                                    <a href={route('cash-register.closings.print', session.id)} target="_blank" className="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Imprimir</a>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
