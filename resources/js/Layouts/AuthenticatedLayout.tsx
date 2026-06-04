import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink';
import { t } from '@/lib/i18n';
import { setCsrfToken } from '@/bootstrap';
import { Link, router, usePage } from '@inertiajs/react';
import { PropsWithChildren, ReactNode, useEffect, useRef, useState } from 'react';

type NavItem = {
    label: string;
    href: string;
    active: boolean;
};

type DropdownKey = 'management' | 'reports' | 'settings' | 'administration';

type BranchOption = {
    id: number;
    name: string;
    code: string | null;
};

type SessionProbeResult = 'active' | 'expired' | 'unknown';

export default function Authenticated({
    header,
    children,
}: PropsWithChildren<{ header?: ReactNode }>) {
    const user = usePage().props.auth.user;
    const permissions = ((usePage().props.auth as { permissions?: string[] })?.permissions ?? []);
    const enabledModules = (usePage().props.enabled_modules as string[] | undefined) ?? [];
    const currentBusinessId = usePage().props.current_business_id as number | null;
    const availableBusinesses = usePage().props.available_businesses as { id: number; name: string }[] | null;
    const branchesEnabled = Boolean(usePage().props.branches_enabled);
    const branchCanSwitch = Boolean(usePage().props.branch_can_switch);
    const activeBranch = usePage().props.active_branch as BranchOption | null;
    const branches = (usePage().props.branches as BranchOption[] | undefined) ?? [];
    const [showingNavigationDropdown, setShowingNavigationDropdown] = useState(false);
    const [openDropdown, setOpenDropdown] = useState<DropdownKey | null>(null);
    const [sessionExpired, setSessionExpired] = useState(false);
    const [sessionExpiredInPos, setSessionExpiredInPos] = useState(false);
    const navRef = useRef<HTMLDivElement>(null);
    const sessionProbeRef = useRef<Promise<SessionProbeResult> | null>(null);
    const canManageUsers = Boolean(user?.is_super_admin) || ['owner', 'admin'].includes(user?.role ?? '');
    const canViewCredits = Boolean(user?.is_super_admin) || permissions.includes('credits.view');
    const hasModule = (module: string) => enabledModules.includes(module);
    const settingsVisible = false;
    const administrationVisible = canManageUsers;
    const can = (permission: string) => Boolean(user?.is_super_admin) || permissions.includes(permission);

    const managementActive =
        (hasModule('inventory') && (route().current('products.*') || route().current('stock.*') || route().current('price-lists.*'))) ||
        (hasModule('branches') && route().current('inventory.transfers.*')) ||
        (hasModule('purchases') && route().current('purchases.*')) ||
        (hasModule('cash_register') && route().current('cash-register.*')) ||
        (hasModule('credits') && route().current('credits.*'));
    const settingsActive = false;
    const administrationActive = canManageUsers && route().current('users.*');
    const reportsActive = route().current('reports.*');

    const managementItems: NavItem[] = [
        hasModule('inventory') ? { label: t('nav.products'), href: route('products.index'), active: route().current('products.*') } : null,
        hasModule('inventory') && canManageUsers ? { label: 'Listas de precios', href: route('price-lists.index'), active: route().current('price-lists.*') } : null,
        hasModule('purchases') ? { label: 'Compras', href: route('purchases.index'), active: route().current('purchases.*') } : null,
        hasModule('inventory') ? { label: t('nav.stock'), href: route('stock.quick'), active: route().current('stock.*') } : null,
        hasModule('branches') ? { label: 'Traslados', href: route('inventory.transfers.index'), active: route().current('inventory.transfers.*') } : null,
        hasModule('cash_register') ? { label: 'Caja', href: route('cash-register.index'), active: route().current('cash-register.*') } : null,
        hasModule('credits') && canViewCredits ? { label: 'Créditos', href: route('credits.index'), active: route().current('credits.*') } : null,
    ].filter(Boolean) as NavItem[];

    const reportItems: NavItem[] = [
        hasModule('reports') && can('reports.sales.view') ? { label: 'Ventas', href: route('reports.sales'), active: route().current('reports.sales') } : null,
        hasModule('reports') && can('reports.inventory.view') ? { label: 'Inventario', href: route('reports.inventory'), active: route().current('reports.inventory') } : null,
        hasModule('reports') && can('reports.low_stock.view') ? { label: 'Stock bajo', href: route('reports.low-stock'), active: route().current('reports.low-stock') } : null,
        hasModule('reports') && can('reports.daily.view') ? { label: 'Diario', href: route('reports.daily'), active: route().current('reports.daily') } : null,
        hasModule('reports') && can('reports.profit.view') ? { label: 'Utilidades', href: route('reports.profit'), active: route().current('reports.profit') } : null,
        hasModule('reports') && can('reports.warehouse_money.view') ? { label: 'Dinero en bodega', href: route('reports.warehouse-money'), active: route().current('reports.warehouse-money') } : null,
        hasModule('reports') && can('reports.top_products.view') ? { label: 'Productos más vendidos', href: route('reports.top-products'), active: route().current('reports.top-products') } : null,
        hasModule('reports') && can('reports.sales_by_seller.view') ? { label: 'Ventas por vendedor', href: route('reports.sales-by-seller'), active: route().current('reports.sales-by-seller') } : null,
        hasModule('reports') && can('reports.sales_by_date.view') ? { label: 'Ventas por fecha', href: route('reports.sales-by-date'), active: route().current('reports.sales-by-date') } : null,
        hasModule('reports') && can('reports.sales_by_customer.view') ? { label: 'Ventas por cliente', href: route('reports.sales-by-customer'), active: route().current('reports.sales-by-customer') } : null,
        hasModule('reports') && can('reports.sales_detailed.view') ? { label: 'Ventas detalladas', href: route('reports.sales-detailed'), active: route().current('reports.sales-detailed') } : null,
        hasModule('reports') && can('reports.products_sold_detailed.view') ? { label: 'Productos vendidos detallado', href: route('reports.products-sold-detailed'), active: route().current('reports.products-sold-detailed') } : null,
        hasModule('reports') && can('reports.products_sold_summary.view') ? { label: 'Productos vendidos resumido', href: route('reports.products-sold-summary'), active: route().current('reports.products-sold-summary') } : null,
    ].filter(Boolean) as NavItem[];

    const settingsItems: NavItem[] = [];

    const administrationItems: NavItem[] = [
        canManageUsers ? { label: 'Usuarios', href: route('users.index'), active: route().current('users.*') } : null,
    ].filter(Boolean) as NavItem[];

    useEffect(() => {
        const handleClickOutside = (event: MouseEvent) => {
            if (navRef.current && !navRef.current.contains(event.target as Node)) {
                setOpenDropdown(null);
            }
        };

        document.addEventListener('mousedown', handleClickOutside);

        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    useEffect(() => {
        if (!user) {
            setSessionExpired(false);
            setSessionExpiredInPos(false);
            return;
        }

        setSessionExpired(false);
        setSessionExpiredInPos(false);

        const clearExpired = () => {
            setSessionExpired(false);
            setSessionExpiredInPos(false);
        };

        const showExpired = (isPos = false) => {
            setSessionExpiredInPos(Boolean(isPos || route().current('sales.create')));
            setSessionExpired(true);
        };

        const probeSession = (): Promise<SessionProbeResult> => {
            if (sessionProbeRef.current) {
                return sessionProbeRef.current;
            }

            const probe = fetch(route('session.keep-alive'), {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
                .then(async (response): Promise<SessionProbeResult> => {
                    if (response.status === 401 || response.status === 419) {
                        return 'expired';
                    }

                    if (!response.ok) {
                        return 'unknown';
                    }

                    const payload = await response.json() as { csrf_token?: string };

                    if (payload.csrf_token) {
                        setCsrfToken(payload.csrf_token);
                    }

                    return 'active';
                })
                .catch((): SessionProbeResult => 'unknown')
                .finally(() => {
                    sessionProbeRef.current = null;
                });

            sessionProbeRef.current = probe;

            return probe;
        };

        const handleExpired = (event: Event) => {
            const detail = (event as CustomEvent<{ isPos?: boolean }>).detail ?? {};

            void probeSession().then((result) => {
                if (result === 'active') {
                    clearExpired();
                } else if (result === 'expired') {
                    showExpired(detail.isPos);
                }
            });
        };

        const removeInvalidListener = router.on('invalid', (event) => {
            const response = event.detail.response;

            if (response.status === 419) {
                event.preventDefault();
                window.dispatchSessionExpired?.({ isPos: route().current('sales.create') });
            }
        });

        const keepAlive = async () => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            const result = await probeSession();

            if (result === 'active') {
                clearExpired();
            } else if (result === 'expired') {
                showExpired(route().current('sales.create'));
            }
        };

        window.addEventListener('blunk:session-expired', handleExpired);
        window.addEventListener('blunk:session-restored', clearExpired);
        const initialTimeout = window.setTimeout(keepAlive, 60 * 1000);
        const interval = window.setInterval(keepAlive, 10 * 60 * 1000);

        return () => {
            window.removeEventListener('blunk:session-expired', handleExpired);
            window.removeEventListener('blunk:session-restored', clearExpired);
            window.clearTimeout(initialTimeout);
            window.clearInterval(interval);
            removeInvalidListener();
        };
    }, [user?.id]);

    return (
        <div className="min-h-screen bg-[#f4f6fb] text-slate-900">
            <nav className="sticky top-0 z-50 border-b border-slate-200/70 bg-white/85 shadow-[0_1px_0_rgba(15,23,42,0.04)] backdrop-blur-xl">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex h-16 justify-between">
                        <div className="flex min-w-0">
                            <div className="flex shrink-0 items-center">
                                <Link href={route('dashboard')} className="flex items-center">
                                    <ApplicationLogo className="h-10 w-auto max-w-[154px] object-contain sm:max-w-[190px]" />
                                </Link>
                            </div>

                            <div
                                ref={navRef}
                                className="hidden min-w-0 flex-1 flex-wrap items-center gap-2 sm:-my-px sm:ms-6 sm:flex"
                            >
                                <HomeNavLink active={route().current('dashboard')} />
                                {hasModule('pos') && (
                                    <TopNavLink
                                        item={{
                                            label: 'POS',
                                            href: route('sales.create'),
                                            active: route().current('sales.create'),
                                        }}
                                    />
                                )}
                                {managementItems.length > 0 && (
                                    <TopNavDropdown
                                        id="management"
                                        label="Gestión"
                                        active={Boolean(managementActive)}
                                        items={managementItems}
                                        openDropdown={openDropdown}
                                        setOpenDropdown={setOpenDropdown}
                                    />
                                )}
                                {reportItems.length > 0 && (
                                    <TopNavDropdown
                                        id="reports"
                                        label="Reportes"
                                        active={Boolean(reportsActive)}
                                        items={reportItems}
                                        openDropdown={openDropdown}
                                        setOpenDropdown={setOpenDropdown}
                                    />
                                )}
                                {settingsVisible && (
                                    <TopNavDropdown
                                        id="settings"
                                        label="Configuración"
                                        active={Boolean(settingsActive)}
                                        items={settingsItems}
                                        openDropdown={openDropdown}
                                        setOpenDropdown={setOpenDropdown}
                                    />
                                )}
                                {administrationVisible && administrationItems.length > 0 && (
                                    <TopNavDropdown
                                        id="administration"
                                        label="Administración"
                                        active={Boolean(administrationActive)}
                                        items={administrationItems}
                                        openDropdown={openDropdown}
                                        setOpenDropdown={setOpenDropdown}
                                    />
                                )}
                            </div>
                        </div>

                        <div className="hidden gap-3 sm:ms-6 sm:flex sm:items-center">
                            {user?.id === 1 && availableBusinesses && (
                                <label className="flex items-center gap-2 text-xs font-semibold text-slate-500">
                                    Negocio actual
                                    <select
                                        value={currentBusinessId ?? ''}
                                        onChange={(event) =>
                                            router.post(
                                                route('tenant.switch'),
                                                { business_id: Number(event.target.value) },
                                                { preserveScroll: true },
                                            )
                                        }
                                        className="h-10 max-w-56 rounded-xl border-slate-200 bg-white text-sm font-semibold text-slate-700 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                    >
                                        <option value="" disabled>
                                            Cambiar negocio
                                        </option>
                                        {availableBusinesses.map((business) => (
                                            <option key={business.id} value={business.id}>
                                                {business.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            )}
                            {branchesEnabled && branches.length > 0 && (
                                <label className="flex items-center gap-2 text-xs font-semibold text-slate-500">
                                    Sucursal activa
                                    <select
                                        value={activeBranch?.id ?? ''}
                                        disabled={!branchCanSwitch}
                                        onChange={(event) =>
                                            router.post(
                                                route('branches.active'),
                                                { branch_id: Number(event.target.value) },
                                                { preserveScroll: true },
                                            )
                                        }
                                        className="h-10 max-w-48 rounded-xl border-slate-200 bg-white text-sm font-semibold text-slate-700 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500"
                                    >
                                        {branches.map((branch) => (
                                            <option key={branch.id} value={branch.id}>
                                                {branch.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            )}
                            <Dropdown>
                                <Dropdown.Trigger>
                                    <button
                                        type="button"
                                        className="inline-flex items-center gap-2 rounded-2xl bg-slate-50 px-3 py-2 text-sm font-medium leading-4 text-slate-700 transition-all duration-200 hover:bg-slate-100 hover:text-slate-950 focus:outline-none focus:ring-4 focus:ring-indigo-100"
                                    >
                                        <span className="flex h-7 w-7 items-center justify-center rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-xs font-bold text-white">
                                            {user?.name?.slice(0, 1).toUpperCase()}
                                        </span>
                                        {user?.name}
                                        <svg className="-me-0.5 ms-2 h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                            <path fillRule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clipRule="evenodd" />
                                        </svg>
                                    </button>
                                </Dropdown.Trigger>

                                <Dropdown.Content>
                                    <Dropdown.Link href={route('profile.edit')}>
                                        {t('auth.profile')}
                                    </Dropdown.Link>
                                    <Dropdown.Link href={route('logout')} method="post" as="button">
                                        {t('auth.logout')}
                                    </Dropdown.Link>
                                </Dropdown.Content>
                            </Dropdown>
                        </div>

                        <div className="-me-2 flex items-center sm:hidden">
                            <button
                                onClick={() => setShowingNavigationDropdown((current) => !current)}
                                className="inline-flex items-center justify-center rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-700 focus:bg-slate-100 focus:text-slate-700 focus:outline-none"
                            >
                                <svg className="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                    <path
                                        className={!showingNavigationDropdown ? 'inline-flex' : 'hidden'}
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        className={showingNavigationDropdown ? 'inline-flex' : 'hidden'}
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div className={(showingNavigationDropdown ? 'block' : 'hidden') + ' border-t border-slate-200 bg-white sm:hidden'}>
                    <div className="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink href={route('dashboard')} active={route().current('dashboard')}>
                            Inicio
                        </ResponsiveNavLink>
                        {hasModule('pos') && (
                        <ResponsiveNavLink href={route('sales.create')} active={route().current('sales.create')}>
                            POS
                        </ResponsiveNavLink>
                        )}

                        {managementItems.length > 0 && (
                            <div className="px-4 pt-3 text-xs font-semibold uppercase tracking-wide text-slate-400">
                                Gestión
                            </div>
                        )}
                        {managementItems.map((item) => (
                            <ResponsiveNavLink key={item.label} href={item.href} active={item.active}>
                                {item.label}
                            </ResponsiveNavLink>
                        ))}

                        {reportItems.length > 0 && (
                            <div className="px-4 pt-3 text-xs font-semibold uppercase tracking-wide text-slate-400">
                                Reportes
                            </div>
                        )}
                        {reportItems.map((item) => (
                            <ResponsiveNavLink key={item.label} href={item.href} active={item.active}>
                                {item.label}
                            </ResponsiveNavLink>
                        ))}

                        {settingsVisible && (
                            <div className="px-4 pt-3 text-xs font-semibold uppercase tracking-wide text-slate-400">
                                Configuración
                            </div>
                        )}
                        {settingsItems.map((item) => (
                            <ResponsiveNavLink key={item.label} href={item.href} active={item.active}>
                                {item.label}
                            </ResponsiveNavLink>
                        ))}

                        {administrationVisible && administrationItems.length > 0 && (
                            <div className="px-4 pt-3 text-xs font-semibold uppercase tracking-wide text-slate-400">
                                Administración
                            </div>
                        )}
                        {administrationItems.map((item) => (
                            <ResponsiveNavLink key={item.label} href={item.href} active={item.active}>
                                {item.label}
                            </ResponsiveNavLink>
                        ))}
                    </div>

                    <div className="border-t border-slate-200 pb-1 pt-4">
                        <div className="px-4">
                            <div className="text-base font-medium text-slate-900">{user?.name}</div>
                            <div className="text-sm font-medium text-slate-500">{user?.email}</div>
                        </div>

                        {user?.id === 1 && availableBusinesses && (
                            <div className="mt-3 px-4">
                                <label className="block text-xs font-semibold text-slate-500">
                                    Negocio actual
                                </label>
                                <select
                                    value={currentBusinessId ?? ''}
                                    onChange={(event) =>
                                        router.post(
                                            route('tenant.switch'),
                                            { business_id: Number(event.target.value) },
                                            { preserveScroll: true },
                                        )
                                    }
                                    className="mt-1 h-10 w-full rounded-xl border-slate-200 bg-white text-sm font-semibold text-slate-700 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                >
                                    <option value="" disabled>
                                        Cambiar negocio
                                    </option>
                                    {availableBusinesses.map((business) => (
                                        <option key={business.id} value={business.id}>
                                            {business.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}

                        {branchesEnabled && branches.length > 0 && (
                            <div className="mt-3 px-4">
                                <label className="block text-xs font-semibold text-slate-500">
                                    Sucursal activa
                                </label>
                                <select
                                    value={activeBranch?.id ?? ''}
                                    disabled={!branchCanSwitch}
                                    onChange={(event) =>
                                        router.post(
                                            route('branches.active'),
                                            { branch_id: Number(event.target.value) },
                                            { preserveScroll: true },
                                        )
                                    }
                                    className="mt-1 h-10 w-full rounded-xl border-slate-200 bg-white text-sm font-semibold text-slate-700 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500"
                                >
                                    {branches.map((branch) => (
                                        <option key={branch.id} value={branch.id}>
                                            {branch.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}

                        <div className="mt-3 space-y-1">
                            <ResponsiveNavLink href={route('profile.edit')}>
                                {t('auth.profile')}
                            </ResponsiveNavLink>
                            <ResponsiveNavLink method="post" href={route('logout')} as="button">
                                {t('auth.logout')}
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            {header && (
                <header className="border-b border-slate-200/70 bg-white/70 backdrop-blur-xl">
                    <div className="mx-auto max-w-[1800px] px-5 py-5 sm:px-6">
                        {header}
                    </div>
                </header>
            )}

            <main className="min-h-[calc(100vh-4rem)] bg-[#f4f6fb]">{children}</main>

            {sessionExpired && (
                <div className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/60 px-4">
                    <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                        <h2 className="text-lg font-semibold text-slate-950">Sesión expirada</h2>
                        <p className="mt-2 text-sm text-slate-600">
                            Por seguridad, tu sesión expiró. Inicia sesión nuevamente para continuar.
                        </p>
                        {sessionExpiredInPos && (
                            <p className="mt-3 rounded-xl bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-700">
                                Tu venta quedó guardada como borrador.
                            </p>
                        )}
                        <div className="mt-5 flex justify-end">
                            <button
                                type="button"
                                onClick={() => {
                                    window.location.href = route('login');
                                }}
                                className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700"
                            >
                                Iniciar sesión
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

function TopNavLink({ item }: { item: NavItem }) {
    return (
        <Link
            href={item.href}
            className={[
                'inline-flex items-center rounded-xl px-4 py-2 text-sm leading-5 transition-all duration-200 focus:outline-none',
                'shrink-0',
                item.active
                    ? 'bg-indigo-50 font-semibold text-indigo-700 shadow-sm'
                    : 'font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-950',
            ].join(' ')}
        >
            {item.label}
        </Link>
    );
}

function HomeNavLink({ active }: { active: boolean }) {
    return (
        <Link
            href={route('dashboard')}
            aria-label="Inicio"
            title="Inicio"
            className={[
                'inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl transition-all duration-200 focus:outline-none',
                active
                    ? 'bg-indigo-50 text-indigo-700 shadow-sm'
                    : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950',
            ].join(' ')}
        >
            <svg
                className="h-5 w-5"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
                aria-hidden="true"
            >
                <path d="m3 11 9-8 9 8" />
                <path d="M5 10v10h14V10" />
                <path d="M9 20v-6h6v6" />
            </svg>
        </Link>
    );
}

function TopNavDropdown({
    id,
    label,
    active,
    items,
    openDropdown,
    setOpenDropdown,
}: {
    id: DropdownKey;
    label: string;
    active: boolean;
    items: NavItem[];
    openDropdown: DropdownKey | null;
    setOpenDropdown: (value: DropdownKey | null) => void;
}) {
    const isOpen = openDropdown === id;

    return (
        <div className="relative shrink-0">
            <button
                type="button"
                onClick={() => setOpenDropdown(isOpen ? null : id)}
                className={[
                    'inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-sm leading-5 transition-all duration-200 focus:outline-none',
                    active || isOpen
                        ? 'bg-indigo-50 font-semibold text-indigo-700 shadow-sm'
                        : 'font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-950',
                ].join(' ')}
                aria-expanded={isOpen}
            >
                {label}
                <svg
                    className={[
                        'h-4 w-4 transition-transform duration-200',
                        isOpen ? 'rotate-180' : '',
                    ].join(' ')}
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    aria-hidden="true"
                >
                    <path
                        fillRule="evenodd"
                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                        clipRule="evenodd"
                    />
                </svg>
            </button>

            {isOpen && (
                <div className="absolute left-0 z-50 mt-2 min-w-48 rounded-xl border border-slate-200 bg-white py-2 shadow-lg">
                    {items.map((item) => (
                        <Link
                            key={item.label}
                            href={item.href}
                            onClick={() => setOpenDropdown(null)}
                            className={[
                                'block px-4 py-2 text-sm transition-colors duration-150 hover:bg-indigo-50 hover:text-indigo-700',
                                item.active ? 'font-semibold text-indigo-700' : 'text-slate-700',
                            ].join(' ')}
                        >
                            {item.label}
                        </Link>
                    ))}
                </div>
            )}
        </div>
    );
}
