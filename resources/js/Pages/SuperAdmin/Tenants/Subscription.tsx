import TextInput from '@/Components/TextInput';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout';
import { formatCurrency } from '@/utils/currency';
import { Link, router, useForm } from '@inertiajs/react';
import { FormEvent, ReactNode } from 'react';

type Tenant = { id: number; name: string; country: string | null; currency: string };
type Subscription = {
    id: number;
    plan_name: string;
    status: string;
    price_amount: string;
    currency: string;
    starts_at: string | null;
    ends_at: string | null;
    notes: string | null;
} | null;

const actions = [
    ['active', 'Activar'],
    ['paused', 'Pausar'],
    ['cancelled', 'Cancelar'],
    ['trial', 'Marcar como prueba'],
    ['expired', 'Marcar como expirada'],
];

const statusLabels: Record<string, string> = {
    active: 'Activa',
    paused: 'Pausada',
    cancelled: 'Cancelada',
    trial: 'Prueba',
    expired: 'Expirada',
};

export default function Subscription({
    tenant,
    subscription,
    statuses,
}: {
    tenant: Tenant;
    subscription: Subscription;
    statuses: string[];
}) {
    const { data, setData, put, processing } = useForm({
        plan_name: subscription?.plan_name ?? 'Manual',
        status: subscription?.status ?? 'trial',
        price_amount: subscription?.price_amount ?? '0',
        currency: subscription?.currency ?? currencyCodeForCountry(tenant.country),
        starts_at: subscription?.starts_at?.slice(0, 10) ?? '',
        ends_at: subscription?.ends_at?.slice(0, 10) ?? '',
        notes: subscription?.notes ?? '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        put(route('super-admin.tenants.subscription.update', tenant.id));
    }

    return (
        <SuperAdminLayout title="Suscripción">
            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">{tenant.name}</h2>
                    <p className="mt-1 text-sm text-gray-500">Gestión manual de la suscripción.</p>
                </div>
                <Link
                    href={route('super-admin.tenants.index')}
                    className="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100"
                >
                    Volver
                </Link>
            </div>

            <div className="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3">
                <SummaryCard label="Plan" value={data.plan_name || 'Manual'} />
                <SummaryCard label="Status" value={statusLabels[data.status] ?? data.status} badge={statusBadge(data.status)} />
                <SummaryCard label="Precio" value={formatCurrency(data.price_amount, tenant.country ?? 'GT')} />
            </div>

            <form onSubmit={submit} className="space-y-6 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <div>
                    <h3 className="text-xl font-semibold text-gray-900">Datos de suscripción</h3>
                    <p className="mt-1 text-sm text-gray-500">
                        Actualiza plan, estado, precio y fechas sin proveedor de pagos.
                    </p>
                </div>

                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <Field label="Plan">
                        <TextInput
                            className={inputClass}
                            value={data.plan_name}
                            onChange={(e) => setData('plan_name', e.target.value)}
                        />
                    </Field>
                    <Field label="Estado">
                        <select
                            className={inputClass}
                            value={data.status}
                            onChange={(e) => setData('status', e.target.value)}
                        >
                            {statuses.map((status) => (
                                <option key={status} value={status}>
                                    {statusLabels[status] ?? status}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Precio">
                        <TextInput
                            className={inputClass}
                            type="number"
                            step="0.01"
                            value={data.price_amount}
                            onChange={(e) => setData('price_amount', e.target.value)}
                        />
                    </Field>
                    <input type="hidden" value={data.currency} readOnly />
                    <Field label="Inicio">
                        <TextInput
                            className={inputClass}
                            type="date"
                            value={data.starts_at}
                            onChange={(e) => setData('starts_at', e.target.value)}
                        />
                    </Field>
                    <Field label="Fin">
                        <TextInput
                            className={inputClass}
                            type="date"
                            value={data.ends_at}
                            onChange={(e) => setData('ends_at', e.target.value)}
                        />
                    </Field>
                </div>

                <Field label="Notas">
                    <textarea
                        className={inputClass}
                        rows={4}
                        value={data.notes}
                        onChange={(e) => setData('notes', e.target.value)}
                    />
                </Field>

                <div className="flex flex-wrap gap-2">
                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                    >
                        Guardar
                    </button>
                    {actions.map(([status, label]) => (
                        <button
                            key={status}
                            type="button"
                            onClick={() =>
                                router.post(route('super-admin.tenants.subscription.status', [tenant.id, status]))
                            }
                            className={actionClass(status)}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            </form>
        </SuperAdminLayout>
    );
}

const inputClass =
    'w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500';

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <label className="text-sm font-medium text-gray-700">{label}</label>
            <div className="mt-1">{children}</div>
        </div>
    );
}

function SummaryCard({
    label,
    value,
    badge,
}: {
    label: string;
    value: string;
    badge?: ReactNode;
}) {
    return (
        <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div className="text-sm font-medium text-gray-500">{label}</div>
            <div className="mt-3 text-2xl font-bold text-gray-900">{value}</div>
            {badge && <div className="mt-3">{badge}</div>}
        </div>
    );
}

function statusBadge(status: string) {
    const classes: Record<string, string> = {
        active: 'bg-green-100 text-green-700',
        paused: 'bg-yellow-100 text-yellow-700',
        cancelled: 'bg-red-100 text-red-700',
        trial: 'bg-blue-100 text-blue-700',
        expired: 'bg-red-100 text-red-700',
    };

    return (
        <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${classes[status] ?? 'bg-gray-100 text-gray-700'}`}>
            {statusLabels[status] ?? status}
        </span>
    );
}

function actionClass(status: string) {
    if (status === 'cancelled') {
        return 'rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-700';
    }

    if (status === 'active') {
        return 'rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700';
    }

    return 'rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100';
}

function currencyCodeForCountry(country?: string | null) {
    return country === 'AR' ? 'ARS' : 'GTQ';
}
