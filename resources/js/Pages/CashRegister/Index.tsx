import Toast from '@/Components/Toast';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useToast } from '@/hooks/useToast';
import { formatCurrency } from '@/utils/currency';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEvent, ReactNode, useMemo, useState } from 'react';

type Movement = {
    id: number;
    type: string;
    type_label: string;
    description: string | null;
    amount: number;
    created_at: string | null;
    created_by: string | null;
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
        cash_sale_cancellations: number;
        expenses: number;
        cash_purchases: number;
        expected_cash: number;
    };
    movements?: Movement[];
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
    const country = business?.country ?? 'GT';
    const toast = useToast();
    const [openingModalOpen, setOpeningModalOpen] = useState(false);
    const [expenseModalOpen, setExpenseModalOpen] = useState(false);
    const [expenseCategoryName, setExpenseCategoryName] = useState('');
    const [selectedExpenseCategory, setSelectedExpenseCategory] = useState<ExpenseCategory | null>(null);
    const [closeModalOpen, setCloseModalOpen] = useState(false);
    const [showMovements, setShowMovements] = useState(false);

    const openingForm = useForm({ opening_amount: '0.00', notes: '' });
    const expenseForm = useForm({ category_id: null as number | null, category_name: '', description: '', amount: '' });
    const closeForm = useForm({ counted_cash: openSession ? String(openSession.expected_cash) : '', closing_notes: '' });

    const closeDifference = useMemo(() => {
        if (!openSession) {
            return 0;
        }

        return Number(closeForm.data.counted_cash || 0) - openSession.expected_cash;
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
        openingForm.post(route('cash-register.open'), {
            preserveScroll: true,
            onSuccess: () => {
                setOpeningModalOpen(false);
                openingForm.reset();
                toast.success('Caja abierta correctamente.');
            },
        });
    }

    function submitExpense(event: FormEvent) {
        event.preventDefault();
        const cleanCategoryName = expenseCategoryName.trim();
        const matchingCategory = cleanCategoryName
            ? expenseCategories.find((category) => category.name.trim().toLowerCase() === cleanCategoryName.toLowerCase())
            : null;

        expenseForm.transform((data) => ({
            ...data,
            category_id: selectedExpenseCategory?.id ?? matchingCategory?.id ?? null,
            category_name: selectedExpenseCategory?.name ?? matchingCategory?.name ?? cleanCategoryName,
        }));

        expenseForm.post(route('cash-register.expenses.store'), {
            preserveScroll: true,
            onSuccess: () => {
                setExpenseModalOpen(false);
                expenseForm.reset();
                setExpenseCategoryName('');
                setSelectedExpenseCategory(null);
                toast.success('Gasto registrado correctamente.');
            },
        });
    }

    function submitClose(event: FormEvent) {
        event.preventDefault();
        closeForm.post(route('cash-register.close'), {
            preserveScroll: true,
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
                                {!openSession && (
                                    <button
                                        type="button"
                                        onClick={() => setOpeningModalOpen(true)}
                                        className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
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
                            <p className="mt-2 text-sm text-slate-500">Abre caja para registrar pagos en efectivo, gastos y compras desde caja.</p>
                            <button
                                type="button"
                                onClick={() => setOpeningModalOpen(true)}
                                className="mt-5 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
                            >
                                Abrir caja
                            </button>
                        </section>
                    ) : (
                        <>
                            <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                <Metric label="Estado" value="Abierta" tone="text-emerald-700" />
                                <Metric label="Monto inicial" value={formatCurrency(openSession.opening_amount, country)} />
                                <Metric label="Efectivo esperado" value={formatCurrency(openSession.expected_cash, country)} tone="text-indigo-700" />
                                <Metric label="Abierta por" value={openSession.opened_by ?? '-'} />
                            </section>

                            <section className="rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                                <div className="grid gap-4 md:grid-cols-4">
                                    <Summary label="Ventas en efectivo" value={formatCurrency(openSession.summary.cash_sales, country)} />
                                    <Summary label="Anulaciones en efectivo" value={formatCurrency(openSession.summary.cash_sale_cancellations, country)} />
                                    <Summary label="Gastos" value={formatCurrency(openSession.summary.expenses, country)} />
                                    <Summary label="Compras pagadas de caja" value={formatCurrency(openSession.summary.cash_purchases, country)} />
                                </div>

                                <div className="mt-5 flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setExpenseModalOpen(true)}
                                        disabled={!openSession}
                                        className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
                                    >
                                        Registrar gasto
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            closeForm.setData('counted_cash', String(openSession.expected_cash));
                                            setCloseModalOpen(true);
                                        }}
                                        className="rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-700"
                                    >
                                        Cerrar caja
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setShowMovements((current) => !current)}
                                        className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50"
                                    >
                                        Ver movimientos
                                    </button>
                                </div>
                            </section>

                            {showMovements && (
                                <MovementsTable movements={openSession.movements ?? []} country={country} />
                            )}
                        </>
                    )}
                </div>
            </div>

            {openingModalOpen && (
                <Modal title="Abrir caja" onClose={() => setOpeningModalOpen(false)}>
                    <form onSubmit={submitOpening} className="space-y-4">
                        <Field label="Monto inicial" type="number" step="0.01" value={openingForm.data.opening_amount} onChange={(value) => openingForm.setData('opening_amount', value)} error={openingForm.errors.opening_amount} />
                        <TextArea label="Nota" value={openingForm.data.notes} onChange={(value) => openingForm.setData('notes', value)} error={openingForm.errors.notes} />
                        <ModalActions processing={openingForm.processing} submitLabel="Abrir caja" onCancel={() => setOpeningModalOpen(false)} />
                    </form>
                </Modal>
            )}

            {expenseModalOpen && (
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

            {closeModalOpen && openSession && (
                <Modal title="Cerrar caja" onClose={() => setCloseModalOpen(false)}>
                    <form onSubmit={submitClose} className="space-y-4">
                        <div className="rounded-2xl bg-slate-50 p-4">
                            <div className="flex justify-between gap-4 text-sm">
                                <span className="font-medium text-slate-600">Efectivo esperado</span>
                                <span className="font-bold text-slate-950">{formatCurrency(openSession.expected_cash, country)}</span>
                            </div>
                        </div>
                        <Field label="Efectivo contado" type="number" step="0.01" value={closeForm.data.counted_cash} onChange={(value) => closeForm.setData('counted_cash', value)} error={closeForm.errors.counted_cash} />
                        <div className="flex justify-between rounded-2xl border border-slate-200 p-4 text-sm">
                            <span className="font-medium text-slate-600">Diferencia</span>
                            <span className={`font-bold ${closeDifference < 0 ? 'text-red-600' : closeDifference > 0 ? 'text-amber-600' : 'text-emerald-700'}`}>
                                {formatCurrency(closeDifference, country)}
                            </span>
                        </div>
                        <TextArea label="Nota de cierre" value={closeForm.data.closing_notes} onChange={(value) => closeForm.setData('closing_notes', value)} error={closeForm.errors.closing_notes} />
                        <ModalActions processing={closeForm.processing} submitLabel="Cerrar caja" danger onCancel={() => setCloseModalOpen(false)} />
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

function MovementsTable({ movements, country }: { movements: Movement[]; country: string }) {
    return (
        <section className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white/95 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
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
                        {movements.length === 0 ? (
                            <tr>
                                <td colSpan={5} className="px-4 py-10 text-center text-slate-500">Sin movimientos</td>
                            </tr>
                        ) : movements.map((movement) => (
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
                className="mt-1 h-11 w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
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
                className="mt-1 w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
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
                    className="mt-1 h-11 w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
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
                            className="block w-full px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-indigo-50 hover:text-indigo-700"
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
                className={`rounded-xl px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-60 ${danger ? 'bg-red-600 hover:bg-red-700' : 'bg-indigo-600 hover:bg-indigo-700'}`}
            >
                {submitLabel}
            </button>
        </div>
    );
}
