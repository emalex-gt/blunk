import Toast from '@/Components/Toast';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useToast } from '@/hooks/useToast';
import { formatCurrency } from '@/utils/currency';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, ReactNode, useMemo, useRef, useState } from 'react';

type Movement = {
    id: number;
    type: string;
    type_label: string;
    description: string | null;
    amount: number;
    created_at: string | null;
    created_by: string | null;
    reference_type?: string | null;
    reference_id?: number | null;
    expense_status?: string | null;
    expense_cancelled_at?: string | null;
    expense_cancelled_by?: string | null;
    expense_cancellation_note?: string | null;
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
    by_method: Array<{
        method: string;
        sales: number;
        same_turn_cancellations: number;
        prior_period_corrections: number;
        historical_unattributed: number;
        credit_payments: number;
        purchases: number;
        purchases_outside_cash: number;
        expenses: number;
        net: number;
    }>;
    details: PaymentOperation[];
    detail_total: number;
    has_more_details: boolean;
    incidents: PaymentOperation[];
    filters: { method: string; operation: string };
};

type CashSession = {
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
        opening: number;
        cash_sales: number;
        credit_payments: number;
        credit_payment_cancellations: number;
        cash_refunds: number;
        cash_sale_cancellations: number;
        expenses: number;
        expense_cancellations: number;
        cash_purchases: number;
        expected_cash: number;
    };
    movements?: Movement[];
    payment_operations?: PaymentOperations;
    closing_contract: { commercial: { total: number; gross_total: number; same_period_cancellations: number; credit_sales_total: number; ambiguous_count: number }; corrections: Array<{ id: number; amount: number }> };
};

type ExpenseCategory = {
    id: number;
    name: string;
};

export default function Index({
    openSession,
    expenseCategories,
}: {
    openSession: CashSession | null;
    expenseCategories: ExpenseCategory[];
}) {
    const business = usePage().props.business as { country?: string | null } | null;
    const auth = usePage().props.auth as { user?: { is_super_admin?: boolean }; permissions?: string[] };
    const permissions = auth?.permissions ?? [];
    const can = (permission: string) => Boolean(auth?.user?.is_super_admin) || permissions.includes(permission);
    const canOpenCashRegister = can('cash_register.open');
    const canRegisterExpenses = can('cash_register.expenses');
    const canCancelExpenses = can('cash_register.expenses.cancel');
    const canCloseCashRegister = can('cash_register.close');
    const country = business?.country ?? 'GT';
    const toast = useToast();
    const [openingModalOpen, setOpeningModalOpen] = useState(false);
    const [expenseModalOpen, setExpenseModalOpen] = useState(false);
    const [expenseCategoryName, setExpenseCategoryName] = useState('');
    const [selectedExpenseCategory, setSelectedExpenseCategory] = useState<ExpenseCategory | null>(null);
    const [closeModalOpen, setCloseModalOpen] = useState(false);
    const [expenseToCancel, setExpenseToCancel] = useState<Movement | null>(null);
    const [refreshingSession, setRefreshingSession] = useState(false);

    const openingForm = useForm({
        opening_amount: '0.00',
        notes: '',
        idempotency_key: '',
    });
    const expenseForm = useForm({
        category_id: null as number | null,
        category_name: '',
        description: '',
        amount: '',
        idempotency_key: '',
    });
    const closeForm = useForm({ counted_cash: '', closing_notes: '' });
    const closeCashRegisterError = (closeForm.errors as Record<string, string | undefined>).cash_register;
    const cancellationForm = useForm({ cancellation_note: '' });
    const openingOperationKeyRef = useRef<string | null>(null);
    const openingSubmissionLockedRef = useRef(false);
    const expenseOperationKeyRef = useRef<string | null>(null);
    const expenseSubmissionLockedRef = useRef(false);

    const closeDifference = useMemo(() => {
        const countedCash = closeForm.data.counted_cash.trim();

        if (!openSession || countedCash === '') {
            return null;
        }

        const amount = Number(countedCash);

        return Number.isFinite(amount) ? amount - openSession.expected_cash : null;
    }, [closeForm.data.counted_cash, openSession]);

    const categorySuggestions = useMemo(() => {
        const term = expenseCategoryName.trim().toLowerCase();

        if (!term) {
            return expenseCategories.slice(0, 8);
        }

        return expenseCategories
            .filter((category) => category.name.toLowerCase().includes(term))
            .slice(0, 8);
    }, [expenseCategories, expenseCategoryName]);

    function submitOpening(event: FormEvent) {
        event.preventDefault();

        if (openingSubmissionLockedRef.current) {
            return;
        }

        const idempotencyKey = openingOperationKeyRef.current ?? crypto.randomUUID();
        openingOperationKeyRef.current = idempotencyKey;
        openingSubmissionLockedRef.current = true;

        openingForm.transform((data) => ({
            ...data,
            idempotency_key: idempotencyKey,
        }));

        openingForm.post(route('cash-register.open'), {
            preserveScroll: true,
            onSuccess: () => {
                setOpeningModalOpen(false);
                openingForm.reset();
                openingOperationKeyRef.current = null;
                toast.success('Caja abierta correctamente.');
            },
            onFinish: () => {
                openingSubmissionLockedRef.current = false;
            },
        });
    }

    function submitExpense(event: FormEvent) {
        event.preventDefault();

        if (expenseSubmissionLockedRef.current) {
            return;
        }

        const cleanCategoryName = expenseCategoryName.trim();
        const matchingCategory = cleanCategoryName
            ? expenseCategories.find((category) => category.name.trim().toLowerCase() === cleanCategoryName.toLowerCase())
            : null;
        const idempotencyKey = expenseOperationKeyRef.current ?? crypto.randomUUID();
        expenseOperationKeyRef.current = idempotencyKey;
        expenseSubmissionLockedRef.current = true;

        expenseForm.transform((data) => ({
            ...data,
            category_id: selectedExpenseCategory?.id ?? matchingCategory?.id ?? null,
            category_name: selectedExpenseCategory?.name ?? matchingCategory?.name ?? cleanCategoryName,
            idempotency_key: idempotencyKey,
        }));

        expenseForm.post(route('cash-register.expenses.store'), {
            preserveScroll: true,
            onSuccess: () => {
                setExpenseModalOpen(false);
                expenseForm.reset();
                setExpenseCategoryName('');
                setSelectedExpenseCategory(null);
                expenseOperationKeyRef.current = null;
                toast.success('Gasto registrado correctamente.');
            },
            onFinish: () => {
                expenseSubmissionLockedRef.current = false;
            },
        });
    }

    function submitClose(event: FormEvent) {
        event.preventDefault();
        closeForm.post(route('cash-register.close'), {
            preserveScroll: true,
        });
    }

    function submitExpenseCancellation(event: FormEvent) {
        event.preventDefault();

        if (!expenseToCancel?.reference_id) {
            return;
        }

        cancellationForm.post(route('cash-register.expenses.cancel', expenseToCancel.reference_id), {
            preserveScroll: true,
            onSuccess: () => {
                setExpenseToCancel(null);
                cancellationForm.reset();
                toast.success('Gasto anulado correctamente.');
            },
        });
    }

    function updateFilters(method: string, operation: string) {
        if (refreshingSession) return;
        setRefreshingSession(true);
        router.get(route('cash-register.index'), { payment_method: method, operation }, {
            only: ['openSession'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => setRefreshingSession(false),
        });
    }

    function refreshSessionData() {
        if (refreshingSession) {
            return;
        }

        setRefreshingSession(true);
        router.reload({
            only: ['openSession'],
            onFinish: () => setRefreshingSession(false),
        });
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-950">Caja</h2>}>
            <Head title="Caja" />
            <Toast toasts={toast.toasts} onClose={toast.removeToast} />

            <div className="py-5">
                <div className="mx-auto max-w-[1800px] space-y-5 px-5 sm:px-6">
                    <section className="rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h1 className="text-xl font-semibold text-slate-950">
                                    {openSession ? 'Caja abierta' : 'No hay caja abierta'}
                                </h1>
                                <p className="mt-1 text-sm text-slate-500">
                                    Controla ventas en efectivo, gastos y compras pagadas desde caja.
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Link
                                    href={route('cash-register.closings.index')}
                                    className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50"
                                >
                                    Cierres de caja
                                </Link>
                                {openSession && (
                                    <button
                                        type="button"
                                        onClick={refreshSessionData}
                                        disabled={refreshingSession}
                                        className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {refreshingSession ? 'Actualizando…' : 'Actualizar datos'}
                                    </button>
                                )}
                                {!openSession && canOpenCashRegister && (
                                    <button
                                        type="button"
                                        onClick={() => setOpeningModalOpen(true)}
                                        className="rounded-xl bg-orange-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-orange-700"
                                    >
                                        Abrir caja
                                    </button>
                                )}
                            </div>
                        </div>
                    </section>

                    {!openSession ? (
                        <section className="rounded-2xl border border-dashed border-slate-300 bg-white/80 p-10 text-center shadow-sm">
                            <div className="text-lg font-semibold text-slate-950">No hay caja abierta</div>
                            <p className="mt-2 text-sm text-slate-500">
                                {canOpenCashRegister
                                    ? 'Abre caja para registrar pagos en efectivo, gastos y compras desde caja.'
                                    : 'No hay una caja abierta actualmente.'}
                            </p>
                            {canOpenCashRegister && (
                                <button
                                    type="button"
                                    onClick={() => setOpeningModalOpen(true)}
                                    className="mt-5 rounded-xl bg-orange-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-orange-700"
                                >
                                    Abrir caja
                                </button>
                            )}
                        </section>
                    ) : (
                        <>
                            <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                <Metric label="Estado" value="Abierta" tone="text-emerald-700" />
                                <Metric label="Total vendido neto" value={formatCurrency(openSession.closing_contract.commercial.total, country)} tone="text-slate-950" />
                                <Metric label="Monto inicial" value={formatCurrency(openSession.opening_amount, country)} />
                                <Metric label="Efectivo esperado" value={formatCurrency(openSession.expected_cash, country)} tone="text-orange-700" />
                            </section>

                            <section className="rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                                <h3 className="text-lg font-semibold text-slate-950">Total vendido del turno</h3>
                                <div className="mt-4 grid gap-4 sm:grid-cols-4">
                                    <Summary label="Ventas brutas" value={formatCurrency(openSession.closing_contract.commercial.gross_total, country)} />
                                    <Summary label="Anulaciones del turno" value={formatCurrency(openSession.closing_contract.commercial.same_period_cancellations, country)} />
                                    <Summary label="Ventas a crédito" value={formatCurrency(openSession.closing_contract.commercial.credit_sales_total, country)} />
                                    <Summary label="Total vendido neto" value={formatCurrency(openSession.closing_contract.commercial.total, country)} />
                                </div>
                            </section>

                            <section className="rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                                <div className="grid gap-4 md:grid-cols-4">
                                    <Summary label="Efectivo neto de ventas" value={formatCurrency(openSession.summary.cash_sales - openSession.summary.cash_refunds - openSession.summary.cash_sale_cancellations, country)} />
                                    <Summary label="Abonos netos en efectivo" value={formatCurrency(openSession.summary.credit_payments - openSession.summary.credit_payment_cancellations, country)} />
                                    <Summary label="Gastos netos de caja" value={formatCurrency(openSession.summary.expenses - openSession.summary.expense_cancellations, country)} />
                                    <Summary label="Compras pagadas desde caja" value={formatCurrency(openSession.summary.cash_purchases, country)} />
                                </div>

                                <div className="mt-5 flex flex-wrap gap-2">
                                    {canRegisterExpenses && (
                                        <button
                                            type="button"
                                            onClick={() => setExpenseModalOpen(true)}
                                            className="rounded-xl bg-orange-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-orange-700"
                                        >
                                            Registrar gasto
                                        </button>
                                    )}
                                    {canCloseCashRegister && (
                                        <button
                                            type="button"
                                            onClick={() => {
                                                closeForm.setData('counted_cash', '');
                                                closeForm.clearErrors('counted_cash');
                                                setCloseModalOpen(true);
                                            }}
                                            className="rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-700"
                                        >
                                            Cerrar caja
                                        </button>
                                    )}
                                </div>
                            </section>

                            <PaymentOperationsOverview
                                paymentOperations={openSession.payment_operations}
                                country={country}
                                onFiltersChange={updateFilters}
                                refreshing={refreshingSession}
                            />

                            <MovementsTable
                                    movements={(openSession.movements ?? []).filter((movement) => ['expense', 'expense_cancel'].includes(movement.type))}
                                    country={country}
                                    canCancelExpenses={canCancelExpenses}
                                    onCancelExpense={(movement) => {
                                        cancellationForm.reset();
                                        cancellationForm.clearErrors();
                                        setExpenseToCancel(movement);
                                    }}
                                />
                        </>
                    )}
                </div>
            </div>

            {openingModalOpen && canOpenCashRegister && (
                <Modal title="Abrir caja" onClose={() => setOpeningModalOpen(false)}>
                    <form onSubmit={submitOpening} className="space-y-4">
                        <Field label="Monto inicial" type="number" step="0.01" value={openingForm.data.opening_amount} onChange={(value) => openingForm.setData('opening_amount', value)} error={openingForm.errors.opening_amount} />
                        <TextArea label="Nota" value={openingForm.data.notes} onChange={(value) => openingForm.setData('notes', value)} error={openingForm.errors.notes} />
                        <ModalActions processing={openingForm.processing} submitLabel="Abrir caja" onCancel={() => setOpeningModalOpen(false)} />
                    </form>
                </Modal>
            )}

            {expenseModalOpen && canRegisterExpenses && (
                <Modal title="Registrar gasto" onClose={() => setExpenseModalOpen(false)}>
                    <form onSubmit={submitExpense} className="space-y-4">
                        {!openSession && (
                            <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">
                                No hay caja abierta. Abre caja para registrar gastos.
                            </div>
                        )}
                        <CategoryAutocomplete
                            value={expenseCategoryName}
                            selectedCategory={selectedExpenseCategory}
                            suggestions={categorySuggestions}
                            error={expenseForm.errors.category_id || expenseForm.errors.category_name}
                            onChange={(value) => {
                                setExpenseCategoryName(value);
                                if (selectedExpenseCategory && value !== selectedExpenseCategory.name) {
                                    setSelectedExpenseCategory(null);
                                }
                            }}
                            onSelect={(category) => {
                                setSelectedExpenseCategory(category);
                                setExpenseCategoryName(category.name);
                            }}
                        />
                        <Field label="Descripción" value={expenseForm.data.description} onChange={(value) => expenseForm.setData('description', value)} error={expenseForm.errors.description} />
                        <Field label="Monto" type="number" step="0.01" value={expenseForm.data.amount} onChange={(value) => expenseForm.setData('amount', value)} error={expenseForm.errors.amount} />
                        <ModalActions processing={expenseForm.processing || !openSession} submitLabel="Guardar gasto" onCancel={() => setExpenseModalOpen(false)} />
                    </form>
                </Modal>
            )}

            {closeModalOpen && openSession && canCloseCashRegister && (
                <Modal title="Cerrar caja" onClose={() => setCloseModalOpen(false)}>
                    <form onSubmit={submitClose} className="space-y-4">
                        <Field label="Efectivo contado" type="number" step="0.01" value={closeForm.data.counted_cash} onChange={(value) => closeForm.setData('counted_cash', value)} error={closeForm.errors.counted_cash} />
                        {closeCashRegisterError && (
                            <p className="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-700" role="alert">
                                {closeCashRegisterError}
                            </p>
                        )}
                        {closeDifference === null ? (
                            <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                                Ingresa el efectivo contado para calcular la diferencia.
                            </div>
                        ) : (
                            <div className="rounded-2xl border border-slate-200 p-4 text-sm">
                                <div className="flex justify-between gap-4">
                                    <span className="font-medium text-slate-600">Efectivo esperado</span>
                                    <span className="font-bold text-slate-950">{formatCurrency(openSession.expected_cash, country)}</span>
                                </div>
                                <div className="mt-3 flex justify-between gap-4">
                                    <span className="font-medium text-slate-600">Diferencia</span>
                                    <span className={`font-bold ${closeDifference < 0 ? 'text-red-600' : closeDifference > 0 ? 'text-amber-600' : 'text-emerald-700'}`}>
                                        {formatCurrency(closeDifference, country)}
                                    </span>
                                </div>
                            </div>
                        )}
                        <TextArea label="Nota de cierre" value={closeForm.data.closing_notes} onChange={(value) => closeForm.setData('closing_notes', value)} error={closeForm.errors.closing_notes} />
                        <ModalActions processing={closeForm.processing || closeForm.data.counted_cash.trim() === ''} submitLabel="Cerrar caja" danger onCancel={() => setCloseModalOpen(false)} />
                    </form>
                </Modal>
            )}

            {expenseToCancel && openSession && canCancelExpenses && (
                <Modal title="Anular gasto" onClose={() => setExpenseToCancel(null)}>
                    <form onSubmit={submitExpenseCancellation} className="space-y-4">
                        <div className="rounded-2xl bg-slate-50 p-4 text-sm">
                            <div><span className="font-semibold text-slate-700">Descripción:</span> {expenseToCancel.description ?? '-'}</div>
                            <div className="mt-2"><span className="font-semibold text-slate-700">Importe:</span> {formatCurrency(Math.abs(expenseToCancel.amount), country)}</div>
                        </div>
                        <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                            La anulación no elimina el gasto. Se registrará un movimiento positivo por el mismo importe y quedará trazabilidad de ambos movimientos.
                        </div>
                        <TextArea
                            label="Motivo / observación de la anulación"
                            value={cancellationForm.data.cancellation_note}
                            onChange={(value) => cancellationForm.setData('cancellation_note', value)}
                            error={cancellationForm.errors.cancellation_note}
                        />
                        <ModalActions processing={cancellationForm.processing} submitLabel="Confirmar anulación" danger onCancel={() => setExpenseToCancel(null)} />
                    </form>
                </Modal>
            )}
        </AuthenticatedLayout>
    );
}

function Metric({ label, value, tone = 'text-slate-950' }: { label: string; value: string; tone?: string }) {
    return (
        <div className="rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</div>
            <div className={`mt-2 truncate whitespace-nowrap text-2xl font-bold ${tone}`}>{value}</div>
        </div>
    );
}

function Summary({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-2 whitespace-nowrap text-lg font-bold text-slate-950">{value}</div>
        </div>
    );
}

function PaymentOperationsOverview({ paymentOperations, country, onFiltersChange, refreshing }: {
    paymentOperations?: PaymentOperations;
    country: string;
    onFiltersChange: (method: string, operation: string) => void;
    refreshing: boolean;
}) {
    const operations = paymentOperations ?? {
        by_method: [],
        details: [],
        detail_total: 0,
        has_more_details: false,
        incidents: [],
        filters: { method: 'all', operation: 'all' },
    };

    return (
        <>
            <section className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white/95 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                <div className="border-b border-slate-100 p-5">
                    <h3 className="text-lg font-semibold text-slate-950">Resumen por medio de pago</h3>
                    <p className="mt-1 text-sm text-slate-500">Informativo: no modifica el arqueo de efectivo.</p>
                </div>
                <div className="overflow-x-auto">
                    <table className="min-w-[760px] w-full text-sm">
                        <thead>
                            <tr className="bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <th className="px-4 py-3">Medio</th>
                                <th className="px-4 py-3 text-right">Ventas cobradas</th>
                                <th className="px-4 py-3 text-right">Anulaciones turno</th>
                                <th className="px-4 py-3 text-right">Correcciones anteriores</th>
                                <th className="px-4 py-3 text-right">Neto financiero</th>
                                <th className="px-4 py-3 text-right">Abonos netos</th>
                                <th className="px-4 py-3 text-right">Compras desde caja</th>
                                <th className="px-4 py-3 text-right">Compras fuera de caja</th>
                                <th className="px-4 py-3 text-right">Gastos netos</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {operations.by_method.length === 0 ? (
                                <tr>
                                    <td colSpan={9} className="px-4 py-8 text-center text-sm text-slate-500">No hay operaciones de pago atribuibles a esta sesión.</td>
                                </tr>
                            ) : operations.by_method.map((row) => (
                                <tr key={row.method}>
                                    <td className="px-4 py-3 font-semibold text-slate-800">{paymentMethodLabel(row.method)}</td>
                                    <PaymentAmount value={row.sales} country={country} />
                                    <PaymentAmount value={row.same_turn_cancellations} country={country} />
                                    <PaymentAmount value={row.prior_period_corrections} country={country} />
                                    <PaymentAmount value={row.net} country={country} />
                                    <PaymentAmount value={row.credit_payments} country={country} />
                                    {row.method === 'cash'
                                        ? <PaymentAmount value={row.purchases} country={country} />
                                        : <td className="px-4 py-3 text-right text-slate-400">—</td>}
                                    <PaymentAmount value={row.purchases_outside_cash} country={country} />
                                    {row.method === 'cash'
                                        ? <PaymentAmount value={row.expenses} country={country} />
                                        : <td className="px-4 py-3 text-right text-slate-400">—</td>}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                    {operations.by_method.some((row) => row.historical_unattributed !== 0) && (
                        <p className="border-t border-amber-100 bg-amber-50 px-5 py-3 text-sm text-amber-900">Hay anulaciones o reembolsos con origen histórico no determinado; no se asignaron a un turno arbitrariamente.</p>
                    )}
            </section>

            <section className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white/95 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                <div className="border-b border-slate-100 p-5">
                    <h3 className="text-lg font-semibold text-slate-950">Detalle de cobros y pagos</h3>
                    <p className="mt-1 text-sm text-slate-500">Historial de entradas y salidas; las anulaciones se conservan. Las compras fuera de caja son informativas.</p>
                    <div className="mt-4 flex flex-wrap items-end gap-3">
                        <label className="text-sm font-medium text-slate-700">Medio de pago
                            <select className="mt-1 block rounded-lg border border-slate-300 px-3 py-2" value={operations.filters.method} disabled={refreshing} onChange={(e) => onFiltersChange(e.target.value, operations.filters.operation)}>
                                <option value="all">Todos</option><option value="cash">Efectivo</option><option value="card">Tarjeta</option><option value="check">Cheque</option><option value="transfer">Transferencia</option><option value="other">Otros</option>
                            </select>
                        </label>
                        <label className="text-sm font-medium text-slate-700">Operación
                            <select className="mt-1 block rounded-lg border border-slate-300 px-3 py-2" value={operations.filters.operation} disabled={refreshing} onChange={(e) => onFiltersChange(operations.filters.method, e.target.value)}>
                                <option value="all">Todas</option><option value="sale">Ventas cobradas</option><option value="sale_cancellation">Anulaciones de ventas</option><option value="refund">Reembolsos</option><option value="credit_payment">Abonos</option><option value="credit_payment_cancellation">Anulaciones de abonos</option><option value="purchase">Compras pagadas</option><option value="expense">Gastos</option><option value="expense_cancel">Anulaciones de gastos</option>
                            </select>
                        </label>
                        <button type="button" className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 disabled:opacity-50" disabled={refreshing || (operations.filters.method === 'all' && operations.filters.operation === 'all')} onClick={() => onFiltersChange('all', 'all')}>Limpiar filtros</button>
                        <span className="text-sm text-slate-500">{operations.detail_total} operaciones coincidentes</span>
                    </div>
                </div>
                <div className="space-y-2 p-4 md:hidden">
                    {operations.details.map((operation) => <PaymentOperationCard key={operation.id} operation={operation} country={country} />)}
                </div>
                <div className="hidden overflow-x-auto md:block">
                    <table className="min-w-[860px] w-full text-sm">
                        <thead>
                            <tr className="bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <th className="px-4 py-3">Fecha</th>
                                <th className="px-4 py-3">Operación</th>
                                <th className="px-4 py-3">Medio</th>
                                <th className="px-4 py-3">Referencia</th>
                                <th className="px-4 py-3">Cliente / proveedor</th>
                                <th className="px-4 py-3">Origen</th>
                                <th className="px-4 py-3 text-right">Monto</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {operations.details.map((operation) => <PaymentOperationRow key={operation.id} operation={operation} country={country} />)}
                        </tbody>
                    </table>
                </div>
                {operations.has_more_details && (
                    <p className="border-t border-amber-100 bg-amber-50 px-5 py-3 text-sm text-amber-900">
                        Se muestran los primeros 200 de {operations.detail_total} movimientos.
                    </p>
                )}
                {operations.details.length === 0 && <p className="p-5 text-sm text-slate-500">No hay operaciones que coincidan con los filtros seleccionados.</p>}
                {operations.incidents.length > 0 && (
                    <div className="border-t border-red-100 bg-red-50 p-5">
                        <h4 className="font-semibold text-red-900">Movimientos sin asignar con certeza</h4>
                        {operations.incidents.map((operation) => <p key={operation.id} className="mt-2 text-sm text-red-800">{operation.reference}: {operation.incident}</p>)}
                    </div>
                )}
            </section>
        </>
    );
}

function PaymentAmount({ value, country, strong = false }: { value: number; country: string; strong?: boolean }) {
    return <td className={`whitespace-nowrap px-4 py-3 text-right ${strong ? 'font-bold' : ''} ${value < 0 ? 'text-red-600' : 'text-slate-800'}`}>{formatCurrency(value, country)}</td>;
}

function paymentMethodLabel(method: string) {
    return ({ cash: 'Efectivo', card: 'Tarjeta', check: 'Cheque', transfer: 'Transferencia', other: 'Otros' } as Record<string, string>)[method] ?? method;
}

function attributionLabel(attribution: PaymentOperation['attribution']) {
    return attribution === 'direct' ? 'Directo' : attribution === 'reconstructed' ? 'Reconstruido' : '-';
}

function PaymentOperationRow({ operation, country }: { operation: PaymentOperation; country: string }) {
    return (
        <tr className="hover:bg-orange-50/30">
            <td className="px-4 py-3 text-slate-600">{operation.occurred_at ?? '-'}</td>
            <td className="px-4 py-3 font-semibold text-slate-800">{operation.label}</td>
            <td className="px-4 py-3">{paymentMethodLabel(operation.method)}</td>
            <td className="px-4 py-3 text-slate-600">{operation.reference ?? '-'}</td>
            <td className="px-4 py-3 text-slate-600">{operation.party ?? '-'}</td>
            <td className="px-4 py-3 text-slate-600">{attributionLabel(operation.attribution)}</td>
            <td className={`whitespace-nowrap px-4 py-3 text-right font-semibold ${operation.signed_amount < 0 ? 'text-red-600' : 'text-emerald-700'}`}>{formatCurrency(operation.signed_amount, country)}</td>
        </tr>
    );
}

function PaymentOperationCard({ operation, country }: { operation: PaymentOperation; country: string }) {
    return (
        <div className="rounded-xl border border-slate-200 p-3 text-sm">
            <div className="flex items-start justify-between gap-3">
                <div className="font-semibold text-slate-900">{operation.label}</div>
                <div className={operation.signed_amount < 0 ? 'font-bold text-red-600' : 'font-bold text-emerald-700'}>{formatCurrency(operation.signed_amount, country)}</div>
            </div>
            <div className="mt-1 text-slate-600">{paymentMethodLabel(operation.method)} · {operation.occurred_at ?? '-'}</div>
            <div className="mt-1 break-words text-slate-500">{operation.reference ?? '-'}{operation.party ? ` · ${operation.party}` : ''}</div>
            <div className="mt-1 text-xs text-slate-500">{attributionLabel(operation.attribution)}</div>
        </div>
    );
}

function MovementsTable({
    movements,
    country,
    canCancelExpenses,
    onCancelExpense,
}: {
    movements: Movement[];
    country: string;
    canCancelExpenses: boolean;
    onCancelExpense: (movement: Movement) => void;
}) {
    return (
        <section className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white/95 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
            <div className="border-b border-slate-100 p-5">
                <h3 className="text-lg font-semibold text-slate-950">Gastos de caja</h3>
                <p className="mt-1 text-sm text-slate-500">Historial de gastos registrados y anulaciones de la sesión actual.</p>
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
                            <th className="px-4 py-3 text-right">Acción</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {movements.length === 0 ? (
                            <tr>
                                <td colSpan={6} className="px-4 py-10 text-center text-slate-500">Sin movimientos</td>
                            </tr>
                        ) : movements.map((movement) => (
                            <tr key={movement.id} className="hover:bg-orange-50/30">
                                <td className="px-4 py-3 text-slate-600">{movement.created_at ?? '-'}</td>
                                <td className="px-4 py-3 font-semibold text-slate-800">
                                    {movement.type_label}
                                    {movement.type === 'expense' && movement.expense_status === 'cancelled' && (
                                        <span className="ml-2 rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-bold tracking-wide text-red-700">ANULADO</span>
                                    )}
                                </td>
                                <td className="px-4 py-3 text-slate-600">
                                    <div>{movement.description ?? '-'}</div>
                                    {movement.type === 'expense' && movement.expense_status === 'cancelled' && (
                                        <div className="mt-1 text-xs text-slate-500">
                                            {movement.expense_cancelled_by && <>Anulado por: {movement.expense_cancelled_by}. </>}
                                            {movement.expense_cancellation_note && <>Motivo: {movement.expense_cancellation_note}</>}
                                        </div>
                                    )}
                                </td>
                                <td className="px-4 py-3 text-slate-600">{movement.created_by ?? '-'}</td>
                                <td className={`whitespace-nowrap px-4 py-3 text-right font-semibold ${movement.amount < 0 ? 'text-red-600' : 'text-emerald-700'}`}>
                                    {formatCurrency(movement.amount, country)}
                                </td>
                                <td className="px-4 py-3 text-right">
                                    {movement.type === 'expense' && movement.expense_status === 'active' && canCancelExpenses && (
                                        <button
                                            type="button"
                                            onClick={() => onCancelExpense(movement)}
                                            className="rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100"
                                        >
                                            Anular
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

function Modal({ title, children, onClose }: { title: string; children: ReactNode; onClose: () => void }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 p-4 backdrop-blur-sm">
            <section className="w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
                <div className="mb-4 flex items-center justify-between">
                    <h2 className="text-lg font-semibold text-slate-950">{title}</h2>
                    <button type="button" onClick={onClose} className="rounded-lg px-2 py-1 text-slate-500 hover:bg-slate-100">×</button>
                </div>
                {children}
            </section>
        </div>
    );
}

function Field({ label, value, onChange, error, type = 'text', step }: { label: string; value: string; onChange: (value: string) => void; error?: string; type?: string; step?: string }) {
    return (
        <label className="block">
            <span className="text-sm font-medium text-slate-700">{label}</span>
            <input
                type={type}
                step={step}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="mt-1 h-11 w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-orange-400 focus:ring-4 focus:ring-orange-100"
            />
            {error && <span className="mt-1 block text-xs font-medium text-red-600">{error}</span>}
        </label>
    );
}

function TextArea({ label, value, onChange, error }: { label: string; value: string; onChange: (value: string) => void; error?: string }) {
    return (
        <label className="block">
            <span className="text-sm font-medium text-slate-700">{label}</span>
            <textarea
                value={value}
                onChange={(event) => onChange(event.target.value)}
                rows={3}
                className="mt-1 w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-orange-400 focus:ring-4 focus:ring-orange-100"
            />
            {error && <span className="mt-1 block text-xs font-medium text-red-600">{error}</span>}
        </label>
    );
}

function CategoryAutocomplete({
    value,
    selectedCategory,
    suggestions,
    error,
    onChange,
    onSelect,
}: {
    value: string;
    selectedCategory: ExpenseCategory | null;
    suggestions: ExpenseCategory[];
    error?: string;
    onChange: (value: string) => void;
    onSelect: (category: ExpenseCategory) => void;
}) {
    return (
        <div className="relative">
            <label className="block">
                <span className="text-sm font-medium text-slate-700">Categoría</span>
                <input
                    type="text"
                    name="cash_expense_category_input"
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    autoComplete="off"
                    autoCorrect="off"
                    spellCheck={false}
                    placeholder="Escribe o selecciona una categoría"
                    className="mt-1 h-11 w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-orange-400 focus:ring-4 focus:ring-orange-100"
                />
            </label>
            <p className="mt-1 text-xs text-slate-500">
                Se creará una nueva categoría si no existe
            </p>
            {error && <span className="mt-1 block text-xs font-medium text-red-600">{error}</span>}
            {value && !selectedCategory && suggestions.length > 0 && (
                <div className="absolute z-20 mt-1 max-h-48 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg">
                    {suggestions.map((category) => (
                        <button
                            key={category.id}
                            type="button"
                            onClick={() => onSelect(category)}
                            className="block w-full px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-orange-50 hover:text-orange-700"
                        >
                            {category.name}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

function ModalActions({ processing, submitLabel, danger = false, onCancel }: { processing: boolean; submitLabel: string; danger?: boolean; onCancel: () => void }) {
    return (
        <div className="flex justify-end gap-2">
            <button type="button" onClick={onCancel} className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Cancelar
            </button>
            <button
                type="submit"
                disabled={processing}
                className={`rounded-xl px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-60 ${danger ? 'bg-red-600 hover:bg-red-700' : 'bg-orange-600 hover:bg-orange-700'}`}
            >
                {submitLabel}
            </button>
        </div>
    );
}
