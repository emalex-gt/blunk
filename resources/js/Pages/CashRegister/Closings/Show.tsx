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

type PaymentOperation = {
    id: string;
    kind: string;
    label: string;
    method: string;
    amount: number;
    signed_amount: number;
    occurred_at: string | null;
    reference: string | null;
    party: string | null;
    attribution: 'direct' | 'reconstructed' | 'ambiguous' | null;
    incident?: string;
};

type PaymentOperations = {
    by_method: Array<{ method: string; sales: number; credit_payments: number; purchases: number; purchases_outside_cash: number; expenses: number; net: number }>;
    details: PaymentOperation[];
    detail_total: number;
    has_more_details: boolean;
    incidents: PaymentOperation[];
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
        credit_payments: number;
        credit_payment_cancellations: number;
        cash_refunds: number;
        cash_sale_cancellations: number;
        expenses: number;
        expense_cancellations: number;
        cash_purchases: number;
    };
    payment_operations: PaymentOperations;
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
                        <a href={route('cash-register.closings.print', session.id)} target="_blank" className="rounded-xl bg-orange-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-orange-700">
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
                        <div className="mt-5 grid gap-4 md:grid-cols-6">
                            <Detail label="Ventas netas en efectivo" value={formatCurrency(session.summary.cash_sales - session.summary.cash_refunds - session.summary.cash_sale_cancellations, country)} />
                            <Detail label="Abonos netos en efectivo" value={formatCurrency(session.summary.credit_payments - session.summary.credit_payment_cancellations, country)} />
                            <Detail label="Gastos netos" value={formatCurrency(session.summary.expenses - session.summary.expense_cancellations, country)} />
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
                            <h3 className="text-lg font-semibold text-slate-950">Resumen por medio de pago</h3>
                            <p className="mt-1 text-sm text-slate-500">Informativo: no modifica el arqueo de efectivo.</p>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-[760px] w-full text-sm">
                                <thead><tr className="bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    <th className="px-4 py-3">Medio</th><th className="px-4 py-3 text-right">Ventas netas</th><th className="px-4 py-3 text-right">Abonos netos</th><th className="px-4 py-3 text-right">Compras desde caja</th><th className="px-4 py-3 text-right">Compras fuera de caja</th><th className="px-4 py-3 text-right">Gastos netos</th>
                                </tr></thead>
                                <tbody className="divide-y divide-slate-100">
                                    {session.payment_operations.by_method.map((row) => <tr key={row.method}>
                                        <td className="px-4 py-3 font-semibold text-slate-800">{paymentMethodLabel(row.method)}</td>
                                        <Amount value={row.sales} country={country} /><Amount value={row.credit_payments} country={country} />{row.method === 'cash' ? <Amount value={row.purchases} country={country} /> : <td className="px-4 py-3 text-right text-slate-400">—</td>}<Amount value={row.purchases_outside_cash} country={country} />{row.method === 'cash' ? <Amount value={row.expenses} country={country} /> : <td className="px-4 py-3 text-right text-slate-400">—</td>}
                                    </tr>)}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white/95 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="border-b border-slate-100 p-5"><h3 className="text-lg font-semibold text-slate-950">Detalle de cobros y pagos</h3><p className="mt-1 text-sm text-slate-500">Vínculo directo o reconstrucción segura por horario de la sesión.</p></div>
                        <div className="space-y-2 p-4 md:hidden">{session.payment_operations.details.map((operation) => <OperationCard key={operation.id} operation={operation} country={country} />)}</div>
                        <div className="hidden overflow-x-auto md:block"><table className="min-w-full text-sm"><thead><tr className="bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wide text-slate-500"><th className="px-4 py-3">Fecha</th><th className="px-4 py-3">Operación</th><th className="px-4 py-3">Medio</th><th className="px-4 py-3">Referencia</th><th className="px-4 py-3">Cliente / proveedor</th><th className="px-4 py-3">Origen</th><th className="px-4 py-3 text-right">Monto</th></tr></thead><tbody className="divide-y divide-slate-100">{session.payment_operations.details.map((operation) => <OperationRow key={operation.id} operation={operation} country={country} />)}</tbody></table></div>
                        {session.payment_operations.has_more_details && <p className="border-t border-amber-100 bg-amber-50 px-5 py-3 text-sm text-amber-900">Se muestran los primeros 200 de {session.payment_operations.detail_total} movimientos.</p>}
                        {session.payment_operations.details.length === 0 && <p className="p-5 text-sm text-slate-500">No hay cobros, pagos, devoluciones ni anulaciones atribuibles a esta sesión.</p>}
                        {session.payment_operations.incidents.length > 0 && <div className="border-t border-red-100 bg-red-50 p-5"><h4 className="font-semibold text-red-900">Movimientos sin asignar con certeza</h4>{session.payment_operations.incidents.map((operation) => <p key={operation.id} className="mt-2 text-sm text-red-800">{operation.reference}: {operation.incident}</p>)}</div>}
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
                                        <tr key={movement.id} className="hover:bg-orange-50/30">
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

function Amount({ value, country, strong = false }: { value: number; country: string; strong?: boolean }) { return <td className={`whitespace-nowrap px-4 py-3 text-right ${strong ? 'font-bold' : ''} ${value < 0 ? 'text-red-600' : 'text-slate-800'}`}>{formatCurrency(value, country)}</td>; }
function paymentMethodLabel(method: string) { return ({ cash: 'Efectivo', card: 'Tarjeta', check: 'Cheque', transfer: 'Transferencia', other: 'Otros' } as Record<string, string>)[method] ?? method; }
function attributionLabel(attribution: PaymentOperation['attribution']) { return attribution === 'direct' ? 'Directo' : attribution === 'reconstructed' ? 'Reconstruido' : '-'; }
function OperationRow({ operation, country }: { operation: PaymentOperation; country: string }) { return <tr className="hover:bg-orange-50/30"><td className="px-4 py-3 text-slate-600">{operation.occurred_at ?? '-'}</td><td className="px-4 py-3 font-semibold text-slate-800">{operation.label}</td><td className="px-4 py-3">{paymentMethodLabel(operation.method)}</td><td className="px-4 py-3 text-slate-600">{operation.reference ?? '-'}</td><td className="px-4 py-3 text-slate-600">{operation.party ?? '-'}</td><td className="px-4 py-3 text-slate-600">{attributionLabel(operation.attribution)}</td><td className={`whitespace-nowrap px-4 py-3 text-right font-semibold ${operation.signed_amount < 0 ? 'text-red-600' : 'text-emerald-700'}`}>{formatCurrency(operation.signed_amount, country)}</td></tr>; }
function OperationCard({ operation, country }: { operation: PaymentOperation; country: string }) { return <div className="rounded-xl border border-slate-200 p-3 text-sm"><div className="flex items-start justify-between gap-3"><div className="font-semibold text-slate-900">{operation.label}</div><div className={operation.signed_amount < 0 ? 'font-bold text-red-600' : 'font-bold text-emerald-700'}>{formatCurrency(operation.signed_amount, country)}</div></div><div className="mt-1 text-slate-600">{paymentMethodLabel(operation.method)} · {operation.occurred_at ?? '-'}</div><div className="mt-1 break-words text-slate-500">{operation.reference ?? '-'}{operation.party ? ` · ${operation.party}` : ''}</div><div className="mt-1 text-xs text-slate-500">{attributionLabel(operation.attribution)}</div></div>; }
