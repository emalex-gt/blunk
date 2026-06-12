import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatCurrency } from '@/utils/currency';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Account = {
    id: number;
    credit_limit: number | string | null;
    current_balance: number | string;
    is_blocked: boolean;
    movements_max_created_at: string | null;
    customer: { id: number; name: string; doc_number: string | null };
};

type PageData = { data: Account[]; links: { url: string | null; label: string; active: boolean }[] };

export default function Accounts({ accounts, filters }: { accounts: PageData; filters: { search?: string; status?: string; minBalance?: number | null } }) {
    const country = (usePage().props.business as { country?: string } | null)?.country ?? 'GT';
    const permissions = ((usePage().props.auth as { permissions?: string[] })?.permissions ?? []);
    const canRegisterPayment = permissions.includes('credits.payments.create');
    const canOpenPayments = permissions.includes('credits.payments.view') || canRegisterPayment;
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? 'all');
    const [minBalance, setMinBalance] = useState(filters.minBalance?.toString() ?? '');

    function submit(event: FormEvent) {
        event.preventDefault();
        router.get(route('credits.accounts.index'), { customer_search: search, status, min_balance: minBalance }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-900">Cuentas por cobrar</h2>}>
            <Head title="Cuentas por cobrar" />
            <div className="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
                <div className="flex flex-wrap gap-2">
                    <Link href={route('credits.index')} className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700">Reservas pendientes</Link>
                    {canOpenPayments && <Link href={route('credits.payments.index')} className="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white">Abonos</Link>}
                </div>
                <form onSubmit={submit} className="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-[1fr_180px_180px_auto]">
                    <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar cliente / NIT" className="rounded-lg border-slate-300 text-sm" />
                    <select value={status} onChange={(event) => setStatus(event.target.value)} className="rounded-lg border-slate-300 text-sm">
                        <option value="all">Todos</option><option value="active">Activos</option><option value="blocked">Bloqueados</option>
                    </select>
                    <input type="number" min="0" step="0.01" value={minBalance} onChange={(event) => setMinBalance(event.target.value)} placeholder="Saldo mínimo" className="rounded-lg border-slate-300 text-sm" />
                    <button className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Filtrar</button>
                </form>
                <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm">
                            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500"><tr><th className="px-4 py-3">Cliente</th><th className="px-4 py-3">NIT</th><th className="px-4 py-3 text-right">Saldo</th><th className="px-4 py-3 text-right">Límite</th><th className="px-4 py-3 text-right">Disponible</th><th className="px-4 py-3">Estado</th><th className="px-4 py-3">Último movimiento</th><th /></tr></thead>
                            <tbody className="divide-y divide-slate-100">
                                {accounts.data.map((account) => {
                                    const limit = account.credit_limit === null ? null : Number(account.credit_limit);
                                    const balance = Number(account.current_balance);
                                    return <tr key={account.id}><td className="px-4 py-3 font-semibold text-slate-900">{account.customer.name}</td><td className="px-4 py-3">{account.customer.doc_number ?? '-'}</td><td className="px-4 py-3 text-right font-semibold">{formatCurrency(balance, country)}</td><td className="px-4 py-3 text-right">{limit === null ? 'Sin límite' : formatCurrency(limit, country)}</td><td className="px-4 py-3 text-right">{limit === null ? '-' : formatCurrency(Math.max(0, limit - balance), country)}</td><td className="px-4 py-3">{account.is_blocked ? 'Bloqueado' : 'Activo'}</td><td className="px-4 py-3 text-slate-500">{account.movements_max_created_at ? new Date(account.movements_max_created_at).toLocaleString() : '-'}</td><td className="px-4 py-3 text-right"><div className="flex flex-col items-end gap-1"><Link href={route('credits.accounts.statement', account.customer.id)} className="font-semibold text-indigo-600">Estado de cuenta</Link>{canRegisterPayment && <Link href={route('credits.payments.index', { customer_search: account.customer.doc_number ?? account.customer.name })} className="font-semibold text-emerald-600">Registrar abono</Link>}</div></td></tr>;
                                })}
                                {accounts.data.length === 0 && <tr><td colSpan={8} className="px-4 py-12 text-center text-slate-500">No hay saldos pendientes.</td></tr>}
                            </tbody>
                        </table>
                    </div>
                    <Pagination links={accounts.links} />
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function Pagination({ links }: { links: PageData['links'] }) {
    return <div className="flex flex-wrap gap-2 border-t border-slate-200 p-4">{links.map((link, index) => link.url ? <Link key={index} href={link.url} preserveState className={`rounded-lg px-3 py-1.5 text-sm ${link.active ? 'bg-indigo-600 text-white' : 'border border-slate-200 text-slate-700'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : null)}</div>;
}
