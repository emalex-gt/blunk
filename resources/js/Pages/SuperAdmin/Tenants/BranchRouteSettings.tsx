import SuperAdminLayout from '@/Layouts/SuperAdminLayout';
import { Link, useForm } from '@inertiajs/react';
import { useEffect } from 'react';

type Tenant = { id: number; name: string };
type Branch = { id: number; name: string };
type Policy = {
    collection_workflow_mode: 'immediate_paid' | 'per_order_collection';
    allowed_payment_methods: string[];
    primary_payment_method: string;
};
type PaymentMethod = { value: string; label: string };

export default function BranchRouteSettings({ tenant, branch, policy, payment_methods }: {
    tenant: Tenant;
    branch: Branch;
    policy: Policy | null;
    payment_methods: PaymentMethod[];
}) {
    const form = useForm({
        collection_workflow_mode: policy?.collection_workflow_mode ?? '',
        allowed_payment_methods: policy?.allowed_payment_methods ?? [] as string[],
        primary_payment_method: policy?.primary_payment_method ?? '',
    });

    useEffect(() => {
        if (form.data.allowed_payment_methods.length === 1 && form.data.primary_payment_method !== form.data.allowed_payment_methods[0]) {
            form.setData('primary_payment_method', form.data.allowed_payment_methods[0]);
        }
        if (form.data.allowed_payment_methods.length === 0 && form.data.primary_payment_method !== '') {
            form.setData('primary_payment_method', '');
        }
        if (form.data.allowed_payment_methods.length > 1 && !form.data.allowed_payment_methods.includes(form.data.primary_payment_method)) {
            form.setData('primary_payment_method', '');
        }
    }, [form.data.allowed_payment_methods, form.data.primary_payment_method]);

    const toggleMethod = (value: string) => {
        form.setData('allowed_payment_methods', form.data.allowed_payment_methods.includes(value)
            ? form.data.allowed_payment_methods.filter(method => method !== value)
            : [...form.data.allowed_payment_methods, value]);
    };
    const canSave = form.data.collection_workflow_mode !== ''
        && form.data.allowed_payment_methods.length > 0
        && form.data.allowed_payment_methods.includes(form.data.primary_payment_method);

    return <SuperAdminLayout title={`Rutas - ${branch.name}`}>
        <div className="mx-auto max-w-3xl space-y-6">
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-semibold text-slate-950">Configuración de rutas</h1>
                    <p className="mt-1 text-sm text-slate-500">Tenant: <strong>{tenant.name}</strong> · Sucursal: <strong>{branch.name}</strong></p>
                </div>
                <Link href={route('super-admin.tenants.branches', tenant.id)} className="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                    Volver a sucursales
                </Link>
            </header>

            {!policy && <section className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                <p className="font-semibold">Configuración heredada</p>
                <p className="mt-1">Esta sucursal todavía utiliza el comportamiento anterior de Rutas. Guardar una nueva política activará la configuración de cobros 3G únicamente para esta sucursal. No modifica ventas ni preventas existentes.</p>
            </section>}

            <form onSubmit={event => { event.preventDefault(); form.put(route('super-admin.tenants.branches.route-settings.update', [tenant.id, branch.id])); }} className="space-y-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <fieldset>
                    <legend className="text-base font-semibold text-slate-950">Modo de cobro</legend>
                    <div className="mt-3 space-y-3">
                        <WorkflowOption value="immediate_paid" checked={form.data.collection_workflow_mode === 'immediate_paid'} onChange={() => form.setData('collection_workflow_mode', 'immediate_paid')} title="Cobro inmediato" description="Al generar las ventas, quedarán registradas como pagadas utilizando el método previsto en cada pedido. Este modo requerirá una caja abierta." />
                        <WorkflowOption value="per_order_collection" checked={form.data.collection_workflow_mode === 'per_order_collection'} onChange={() => form.setData('collection_workflow_mode', 'per_order_collection')} title="Cobro por pedido" description="Las ventas se generarán pendientes de cobro y el pago se registrará posteriormente pedido por pedido." />
                        <label className="block cursor-not-allowed rounded-lg border border-slate-200 bg-slate-50 p-4 opacity-70">
                            <div className="flex items-center gap-3"><input type="radio" disabled /><span className="font-semibold">Documento de reparto + conciliación</span><span className="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold">Próximamente</span></div>
                        </label>
                    </div>
                    {form.errors.collection_workflow_mode && <p className="mt-2 text-sm text-red-600">{form.errors.collection_workflow_mode}</p>}
                </fieldset>

                <fieldset>
                    <legend className="text-base font-semibold text-slate-950">Formas de pago disponibles para los ruteros</legend>
                    <p className="mt-1 text-sm text-slate-500">Esta configuración se aplica a todos los ruteros de esta sucursal.</p>
                    <div className="mt-3 grid gap-2 sm:grid-cols-2">{payment_methods.map(method => <label key={method.value} className="flex items-center gap-2 rounded border border-slate-200 p-3"><input type="checkbox" checked={form.data.allowed_payment_methods.includes(method.value)} onChange={() => toggleMethod(method.value)} /><span>{method.label}</span></label>)}</div>
                    {form.errors.allowed_payment_methods && <p className="mt-2 text-sm text-red-600">{form.errors.allowed_payment_methods}</p>}
                </fieldset>

                <fieldset>
                    <legend className="text-base font-semibold text-slate-950">Método principal</legend>
                    {form.data.allowed_payment_methods.length === 0
                        ? <p className="mt-2 text-sm text-slate-500">Selecciona al menos una forma de pago.</p>
                        : <div className="mt-3 grid gap-2 sm:grid-cols-2">{payment_methods.filter(method => form.data.allowed_payment_methods.includes(method.value)).map(method => <label key={method.value} className="flex items-center gap-2 rounded border border-slate-200 p-3"><input type="radio" name="primary_payment_method" checked={form.data.primary_payment_method === method.value} disabled={form.data.allowed_payment_methods.length === 1} onChange={() => form.setData('primary_payment_method', method.value)} /><span>{method.label}</span></label>)}</div>}
                    {form.errors.primary_payment_method && <p className="mt-2 text-sm text-red-600">{form.errors.primary_payment_method}</p>}
                </fieldset>

                <button type="submit" disabled={!canSave || form.processing} className="rounded-lg bg-indigo-600 px-4 py-2 font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300">{form.processing ? 'Guardando...' : 'Guardar configuración'}</button>
            </form>
        </div>
    </SuperAdminLayout>;
}

function WorkflowOption({ value, checked, onChange, title, description }: { value: string; checked: boolean; onChange: () => void; title: string; description: string }) {
    return <label className="block cursor-pointer rounded-lg border border-slate-200 p-4"><div className="flex items-start gap-3"><input type="radio" name="collection_workflow_mode" value={value} checked={checked} onChange={onChange} /><span><span className="font-semibold">{title}</span><span className="mt-1 block text-sm text-slate-500">{description}</span></span></div></label>;
}
