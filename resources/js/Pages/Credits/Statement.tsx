import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatCurrency } from '@/utils/currency';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

type Movement = { id: number; created_at: string; description: string | null; direction: 'debit' | 'credit'; amount: string | number; balance_after: string | number; sale?: { business_number: number } | null; payment?: { payment_number: number } | null; created_by?: { name: string } | null; branch?: { name: string } | null };
type PageData = { data: Movement[]; links: { url: string | null; label: string; active: boolean }[] };

export default function Statement({ customer, account, movements, filters, branch }: { customer: { id: number; name: string; doc_number: string | null }; account: { current_balance: string | number; credit_limit: string | number | null; is_blocked: boolean; notes?: string | null }; movements: PageData; filters: { date_from: string; date_to: string }; branch: { name: string } }) {
    const page = usePage().props as { business?: { country?: string } | null; auth?: { permissions?: string[] } };
    const country = page.business?.country ?? 'GT';
    const permissions = page.auth?.permissions ?? [];
    const limit = account.credit_limit === null ? null : Number(account.credit_limit);
    const balance = Number(account.current_balance);
    const settings = useForm({ credit_limit: account.credit_limit?.toString() ?? '', is_blocked: account.is_blocked, notes: account.notes ?? '' });

    function filter(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        router.get(route('credits.accounts.statement', customer.id), { date_from: form.get('date_from'), date_to: form.get('date_to') }, { preserveState: true, replace: true });
    }

    return <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-900">Estado de cuenta</h2>}>
        <Head title={`Estado de cuenta - ${customer.name}`} />
        <div className="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
            <div className="flex flex-wrap gap-2"><Link href={route('credits.accounts.index')} className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700">Cuentas por cobrar</Link>{(permissions.includes('credits.payments.view') || permissions.includes('credits.payments.create')) && <Link href={route('credits.payments.index')} className="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white">Registrar abono</Link>}</div>
            <section className="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm md:grid-cols-4"><div className="md:col-span-2"><h1 className="text-xl font-semibold text-slate-950">{customer.name}</h1><p className="text-sm text-slate-500">NIT: {customer.doc_number ?? '-'}</p><p className="text-sm text-slate-500">Movimientos de sucursal: {branch.name}</p></div><Metric label="Saldo pendiente" value={formatCurrency(balance, country)} /><Metric label="Crédito disponible" value={limit === null ? 'Sin límite' : formatCurrency(Math.max(0, limit - balance), country)} /></section>
            {permissions.includes('credits.limits.manage') && <form onSubmit={(event) => { event.preventDefault(); settings.patch(route('credits.accounts.update', customer.id)); }} className="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-[180px_180px_1fr_auto]"><input type="number" min="0" step="0.01" value={settings.data.credit_limit} onChange={(event) => settings.setData('credit_limit', event.target.value)} placeholder="Límite de crédito" className="rounded-lg border-slate-300 text-sm" /><label className="flex items-center gap-2 text-sm font-semibold text-slate-700"><input type="checkbox" checked={settings.data.is_blocked} onChange={(event) => settings.setData('is_blocked', event.target.checked)} /> Bloquear crédito</label><input value={settings.data.notes} onChange={(event) => settings.setData('notes', event.target.value)} placeholder="Notas" className="rounded-lg border-slate-300 text-sm" /><button className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Guardar</button></form>}
            <form onSubmit={filter} className="flex flex-wrap gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><input type="date" name="date_from" defaultValue={filters.date_from} className="rounded-lg border-slate-300 text-sm" /><input type="date" name="date_to" defaultValue={filters.date_to} className="rounded-lg border-slate-300 text-sm" /><button className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Filtrar</button><span className="self-center text-xs text-slate-500">Rango máximo: 3 meses</span></form>
            <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"><div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-200 text-sm"><thead className="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500"><tr><th className="px-4 py-3">Fecha</th><th className="px-4 py-3">Documento</th><th className="px-4 py-3">Descripción</th><th className="px-4 py-3 text-right">Cargo</th><th className="px-4 py-3 text-right">Abono</th><th className="px-4 py-3 text-right">Saldo</th><th className="px-4 py-3">Usuario</th><th className="px-4 py-3">Sucursal</th></tr></thead><tbody className="divide-y divide-slate-100">{movements.data.map((movement) => <tr key={movement.id}><td className="px-4 py-3">{new Date(movement.created_at).toLocaleString()}</td><td className="px-4 py-3 font-semibold">{movement.sale ? `V-${movement.sale.business_number}` : movement.payment ? `AB-${movement.payment.payment_number}` : '-'}</td><td className="px-4 py-3">{movement.description}</td><td className="px-4 py-3 text-right">{movement.direction === 'debit' ? formatCurrency(movement.amount, country) : '-'}</td><td className="px-4 py-3 text-right">{movement.direction === 'credit' ? formatCurrency(movement.amount, country) : '-'}</td><td className="px-4 py-3 text-right font-semibold">{formatCurrency(movement.balance_after, country)}</td><td className="px-4 py-3">{movement.created_by?.name ?? '-'}</td><td className="px-4 py-3">{movement.branch?.name ?? '-'}</td></tr>)}{movements.data.length === 0 && <tr><td colSpan={8} className="px-4 py-12 text-center text-slate-500">No hay movimientos en este período.</td></tr>}</tbody></table></div><div className="flex flex-wrap gap-2 border-t border-slate-200 p-4">{movements.links.map((link, index) => link.url ? <Link key={index} href={link.url} preserveState className={`rounded-lg px-3 py-1.5 text-sm ${link.active ? 'bg-indigo-600 text-white' : 'border border-slate-200 text-slate-700'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : null)}</div></section>
        </div>
    </AuthenticatedLayout>;
}

function Metric({ label, value }: { label: string; value: string }) {
    return <div className="rounded-lg bg-slate-50 p-3"><div className="text-xs font-semibold uppercase text-slate-500">{label}</div><div className="mt-1 text-xl font-bold text-slate-950">{value}</div></div>;
}
