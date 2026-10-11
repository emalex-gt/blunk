import { Head, Link, usePage } from '@inertiajs/react';
import { PropsWithChildren, ReactNode } from 'react';

type Props = PropsWithChildren<{
    title: string;
    actions?: ReactNode;
}>;

export default function SuperAdminLayout({ title, actions, children }: Props) {
    const user = usePage().props.auth.user;

    const navigation = [
        {
            label: 'Dashboard',
            href: route('super-admin.dashboard'),
            active: route().current('super-admin.dashboard'),
        },
        {
            label: 'Negocios',
            href: route('super-admin.tenants.index'),
            active: route().current('super-admin.tenants.*'),
        },
        {
            label: 'Incidencias FEL',
            href: route('super-admin.fel-incidents.index'),
            active: route().current('super-admin.fel-incidents.*'),
        },
        {
            label: 'Sistema',
            href: route('super-admin.system.deploy.index'),
            active: route().current('super-admin.system.deploy.*'),
        },
        {
            label: 'Seguridad',
            href: route('super-admin.security.roles'),
            active: route().current('super-admin.security.*'),
        },
        {
            label: 'Usuarios',
            href: route('super-admin.tenants.index'),
            active: false,
        },
    ];

    return (
        <div className="min-h-screen bg-[#f3f4f6] text-slate-900">
            <Head title={title} />

            <header className="sticky top-0 z-50 border-b border-slate-800 bg-slate-950/95 shadow-lg shadow-slate-950/10 backdrop-blur-xl">
                <div className="mx-auto flex h-16 max-w-[1800px] items-center justify-between gap-4 px-5 sm:px-6">
                    <div className="flex min-w-0 items-center gap-8">
                        <Link href={route('super-admin.dashboard')} className="flex items-center gap-3">
                            <span className="flex h-9 w-9 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-600 to-amber-500 text-sm font-bold text-white shadow-lg shadow-orange-200">
                                SA
                            </span>
                            <div className="hidden sm:block">
                                <div className="text-base font-bold tracking-tight text-white">
                                    Panel administrativo
                                </div>
                                <div className="text-xs font-medium text-slate-400">Super Admin</div>
                            </div>
                        </Link>

                        <nav className="hidden gap-2 md:flex">
                            {navigation.map((item) => (
                                <Link
                                    key={item.label}
                                    href={item.href}
                                    className={[
                                        'rounded-xl px-4 py-2 text-sm transition-all duration-200',
                                        item.active
                                            ? 'bg-orange-500/15 font-semibold text-orange-300 shadow-sm ring-1 ring-orange-500/20'
                                            : 'font-medium text-slate-300 hover:bg-slate-800 hover:text-white',
                                    ].join(' ')}
                                >
                                    {item.label}
                                </Link>
                            ))}
                        </nav>
                    </div>

                    <div className="flex items-center gap-3">
                        {actions}
                        <div className="hidden text-right sm:block">
                            <div className="text-sm font-semibold text-white">{user?.name}</div>
                            <div className="text-xs text-slate-400">{user?.email}</div>
                        </div>
                        <Link
                            href={route('logout')}
                            method="post"
                            as="button"
                            className="rounded-xl bg-slate-900 px-3 py-2 text-sm font-semibold text-slate-200 transition-all duration-200 hover:bg-slate-800 hover:text-white focus:outline-none focus:ring-4 focus:ring-orange-500/20"
                        >
                            Salir
                        </Link>
                    </div>
                </div>
            </header>

            <main className="mx-auto max-w-[1800px] px-5 py-5 sm:px-6">{children}</main>
        </div>
    );
}
