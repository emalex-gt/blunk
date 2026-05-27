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
        <div className="min-h-screen bg-[#f4f6fb] text-slate-900">
            <Head title={title} />

            <header className="sticky top-0 z-50 border-b border-slate-200/70 bg-white/85 shadow-[0_1px_0_rgba(15,23,42,0.04)] backdrop-blur-xl">
                <div className="mx-auto flex h-16 max-w-[1800px] items-center justify-between gap-4 px-5 sm:px-6">
                    <div className="flex min-w-0 items-center gap-8">
                        <Link href={route('super-admin.dashboard')} className="flex items-center gap-3">
                            <span className="flex h-9 w-9 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-600 to-violet-600 text-sm font-bold text-white shadow-lg shadow-indigo-200">
                                SA
                            </span>
                            <div className="hidden sm:block">
                                <div className="text-base font-bold tracking-tight text-slate-950">
                                    Panel administrativo
                                </div>
                                <div className="text-xs font-medium text-slate-500">Super Admin</div>
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
                                            ? 'bg-indigo-50 font-semibold text-indigo-700 shadow-sm'
                                            : 'font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-950',
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
                            <div className="text-sm font-semibold text-slate-900">{user?.name}</div>
                            <div className="text-xs text-slate-500">{user?.email}</div>
                        </div>
                        <Link
                            href={route('logout')}
                            method="post"
                            as="button"
                            className="rounded-xl bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700 transition-all duration-200 hover:bg-slate-100 focus:outline-none focus:ring-4 focus:ring-indigo-100"
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
