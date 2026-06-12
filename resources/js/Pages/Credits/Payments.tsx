import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatCurrency } from '@/utils/currency';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';

type Payment = { id: number; payment_number: number; amount: string | number; payment_method: string; status: string; created_at: string; customer: { name: string; doc_number: string | null }; branch: { name: string } | null; created_by: { name: string } | null };
type Customer = { id: number; name: string; doc_number: string | null };
type PageData = { data: Payment[]; links: { url: string | null; label: string; active: boolean }[] };

export default function Payments({ payments, customers, filters, branch, can_view_payments }: { payments: PageData; customers: Customer[]; filters: Record<string, string>; branch: { name: string }; can_view_payments: boolean }) {
    const page = usePage().props as { business?: { country?: string } | null; auth?: { permissions?: string[] }; flash?: { credit_payment_print_url?: string | null } };
    const country = page.business?.country ?? 'GT';
    const permissions = page.auth?.permissions ?? [];
    const [search, setSearch] = useState(filters.customer_search ?? '');
    const [method, setMethod] = useState(filters.payment_method ?? '');
    const form = useForm({ customer_id: '', amount: '', payment_method: 'cash', reference: '', notes: '' });

    useEffect(() => {
        if (page.flash?.credit_payment_print_url) window.open(page.flash.credit_payment_print_url, '_blank');
    }, [page.flash?.credit_payment_print_url]);

    function filter(event: FormEvent) {
        event.preventDefault();
        router.get(route('credits.payments.index'), { ...filters, customer_search: search, payment_method: method }, { preserveState: true, replace: true });
    }

    return <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-900">Abonos</h2>}>
        <Head title="Abonos" />
        <div className="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
            <div className="flex flex-wrap gap-2"><Link href={route('credits.accounts.index')} className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700">Cuentas por cobrar</Link><Link href={route('credits.index')} className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700">Reservas pendientes</Link></div>
            {permissions.includes('credits.payments.create') && <form onSubmit={(event) => { event.preventDefault(); form.post(route('credits.payments.store'), { onSuccess: () => form.reset() }); }} className="grid gap-3 rounded-xl border border-indigo-100 bg-white p-4 shadow-sm md:grid-cols-3">
                <h3 className="md:col-span-3 text-base font-semibold text-slate-900">Registrar abono · {branch.name}</h3>
                <select value={form.data.customer_id} onChange={(event) => form.setData('customer_id', event.target.value)} className="rounded-lg border-slate-300 text-sm"><option value="">Seleccionar cliente</option>{customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name} · {customer.doc_number}</option>)}</select>
                <input type="number" min="0.01" step="0.01" value={form.data.amount} onChange={(event) => form.setData('amount', event.target.value)} placeholder="Monto" className="rounded-lg border-slate-300 text-sm" />
                <select value={form.data.payment_method} onChange={(event) => form.setData('payment_method', event.target.value)} className="rounded-lg border-slate-300 text-sm"><option value="cash">Efectivo</option><option value="card">Tarjeta</option><option value="bank_transfer">Transferencia bancaria</option><option value="check">Cheque</option><option value="other">Otro</option></select>
                <input value={form.data.reference} onChange={(event) => form.setData('reference', event.target.value)} placeholder="Referencia" className="rounded-lg border-slate-300 text-sm" />
                <input value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} placeholder="Notas" className="rounded-lg border-slate-300 text-sm" />
                <button disabled={form.processing} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:bg-slate-300">Registrar abono</button>
                {Object.values(form.errors).length > 0 && <p className="md:col-span-3 text-sm font-semibold text-red-600">{Object.values(form.errors)[0]}</p>}
            </form>}
            {can_view_payments && <><form onSubmit={filter} className="flex flex-wrap gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar cliente / NIT" className="rounded-lg border-slate-300 text-sm" /><select value={method} onChange={(event) => setMethod(event.target.value)} className="rounded-lg border-slate-300 text-sm"><option value="">Todos los métodos</option><option value="cash">Efectivo</option><option value="card">Tarjeta</option><option value="bank_transfer">Transferencia</option><option value="check">Cheque</option><option value="other">Otro</option></select><button className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Filtrar</button></form>
            <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"><div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-200 text-sm"><thead className="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500"><tr><th className="px-4 py-3">Abono</th><th className="px-4 py-3">Fecha</th><th className="px-4 py-3">Cliente</th><th className="px-4 py-3">Método</th><th className="px-4 py-3 text-right">Monto</th><th className="px-4 py-3">Estado</th><th /></tr></thead><tbody className="divide-y divide-slate-100">{payments.data.map((payment) => <tr key={payment.id}><td className="px-4 py-3 font-semibold">AB-{payment.payment_number}</td><td className="px-4 py-3">{new Date(payment.created_at).toLocaleString()}</td><td className="px-4 py-3">{payment.customer.name}<div className="text-xs text-slate-500">{payment.customer.doc_number}</div></td><td className="px-4 py-3">{payment.payment_method}</td><td className="px-4 py-3 text-right font-semibold">{formatCurrency(payment.amount, country)}</td><td className="px-4 py-3">{payment.status}</td><td className="px-4 py-3 text-right space-x-3"><a href={route('credits.payments.print', payment.id)} target="_blank" className="font-semibold text-indigo-600">Imprimir</a>{payment.status === 'completed' && permissions.includes('credits.payments.cancel') && <button type="button" onClick={() => router.post(route('credits.payments.cancel', payment.id))} className="font-semibold text-red-600">Anular</button>}</td></tr>)}{payments.data.length === 0 && <tr><td colSpan={7} className="px-4 py-12 text-center text-slate-500">No hay abonos en este período.</td></tr>}</tbody></table></div><div className="flex flex-wrap gap-2 border-t border-slate-200 p-4">{payments.links.map((link, index) => link.url ? <Link key={index} href={link.url} preserveState className={`rounded-lg px-3 py-1.5 text-sm ${link.active ? 'bg-indigo-600 text-white' : 'border border-slate-200 text-slate-700'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : null)}</div></section></>}
        </div>
    </AuthenticatedLayout>;
}
