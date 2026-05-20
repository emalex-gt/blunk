import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatCurrency } from '@/utils/currency';
import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect } from 'react';

type Movement = {
    id: number;
    type_label: string;
    description: string | null;
    amount: number;
    created_at: string | null;
    created_by: string | null;
};

type Session = {
    id: number;
    status: string;
    opening_amount: number;
    expected_cash: number;
    counted_cash: number | null;
    difference: number | null;
    opened_at: string | null;
    closed_at: string | null;
    opened_by: string | null;
    closed_by: string | null;
    notes: string | null;
    closing_notes: string | null;
    summary: {
        cash_sales: number;
        cash_sale_cancellations: number;
        expenses: number;
        cash_purchases: number;
    };
    movements: Movement[];
};

export default function Show({ session }: { session: Session }) {
    const page = usePage();
    const business = page.props.business as { country?: string | null } | null;
    const country = business?.country ?? 'GT';
    const flash = page.props.flash as { cash_closing_print_id?: number | null } | undefined;

    useEffect(() => {
        if (flash?.cash_closing_print_id === session.id) {
            window.open(route('cash-register.closings.print', session.id), '_blank');
        }
    }, [flash?.cash_closing_print_id, session.id]);

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-xl font-semibold text-slate-950">Cierre de caja #{session.id}</h2>
                    <div className="flex gap-2">
                        <a href={route('cash-register.closings.print', session.id)} target="_blank" className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">
                            Imprimir cierre
                        </a>
                        <Link href={route('cash-register.closings.index')} className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            Volver
                        </Link>
                    </div>
                </div>
            }
        >
            <Head title={`Cierre de caja #${session.id}`} />

            <div className="py-5">
                <div className="mx-auto max-w-[1800px] space-y-5 px-5 sm:px-6">
                    <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <Summary label="Monto inicial" value={formatCurrency(session.opening_amount, country)} />
                        <Summary label="Efectivo esperado" value={formatCurrency(session.expected_cash, country)} />
                        <Summary label="Efectivo contado" value={formatCurrency(session.counted_cash ?? 0, country)} />
                        <Summary label="Diferencia" value={formatCurrency(session.difference ?? 0, country)} tone={(session.difference ?? 0) < 0 ? 'text-red-600' : (session.difference ?? 0) > 0 ? 'text-amber-600' : 'text-emerald-700'} />
                    </section>

                    <section className="rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="grid gap-4 md:grid-cols-4">
                            <Detail label="Abierta" value={session.opened_at ?? '-'} />
                            <Detail label="Cerrada" value={session.closed_at ?? '-'} />
                            <Detail label="Abierta por" value={session.opened_by ?? '-'} />
                            <Detail label="Cerrada por" value={session.closed_by ?? '-'} />
                        </div>
                        <div className="mt-5 grid gap-4 md:grid-cols-4">
                            <Detail label="Ventas en efectivo" value={formatCurrency(session.summary.cash_sales, country)} />
                            <Detail label="Anulaciones" value={formatCurrency(session.summary.cash_sale_cancellations, country)} />
                            <Detail label="Gastos" value={formatCurrency(session.summary.expenses, country)} />
                            <Detail label="Compras desde caja" value={formatCurrency(session.summary.cash_purchases, country)} />
                        </div>
                        {(session.notes || session.closing_notes) && (
                            <div className="mt-5 grid gap-4 md:grid-cols-2">
                                {session.notes && <Note title="Nota de apertura" value={session.notes} />}
                                {session.closing_notes && <Note title="Nota de cierre" value={session.closing_notes} />}
                            </div>
                        )}
                    </section>

                    <section className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white/95 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="border-b border-slate-100 p-5">
                            <h3 className="text-lg font-semibold text-slate-950">Movimientos</h3>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead>
                                    <tr className="bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        <th className="px-4 py-3">Fecha</th>
                                        <th className="px-4 py-3">Tipo</th>
                                        <th className="px-4 py-3">Descripción</th>
                                        <th className="px-4 py-3">Usuario</th>
                                        <th className="px-4 py-3 text-right">Monto</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {session.movements.map((movement) => (
                                        <tr key={movement.id} className="hover:bg-indigo-50/30">
                                            <td className="px-4 py-3 text-slate-600">{movement.created_at ?? '-'}</td>
                                            <td className="px-4 py-3 font-semibold text-slate-800">{movement.type_label}</td>
                                            <td className="px-4 py-3 text-slate-600">{movement.description ?? '-'}</td>
                                            <td className="px-4 py-3 text-slate-600">{movement.created_by ?? '-'}</td>
                                            <td className={`whitespace-nowrap px-4 py-3 text-right font-semibold ${movement.amount < 0 ? 'text-red-600' : 'text-emerald-700'}`}>
                                                {formatCurrency(movement.amount, country)}
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

function Summary({ label, value, tone = 'text-slate-950' }: { label: string; value: string; tone?: string }) {
    return (
        <div className="rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</div>
            <div className={`mt-2 whitespace-nowrap text-2xl font-bold ${tone}`}>{value}</div>
        </div>
    );
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-1 font-semibold text-slate-950">{value}</div>
        </div>
    );
}

function Note({ title, value }: { title: string; value: string }) {
    return (
        <div className="rounded-2xl bg-slate-50 p-4 text-sm">
            <div className="font-semibold text-slate-700">{title}</div>
            <div className="mt-1 text-slate-600">{value}</div>
        </div>
    );
}
