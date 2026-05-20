import TextInput from '@/Components/TextInput';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout';
import { Link, router } from '@inertiajs/react';
import { FormEvent, ReactNode, useState } from 'react';

type Tenant = {
    id: number;
    name: string;
    country: string | null;
    currency: string;
    email: string | null;
    is_active: boolean;
    tenant_setting: { use_product_images: boolean; max_users: number } | null;
    latest_subscription: { status: string } | null;
    active_users_count: number;
};

type Paginated<T> = {
    data: T[];
};

const statusLabels: Record<string, string> = {
    active: 'Activa',
    paused: 'Pausada',
    cancelled: 'Cancelada',
    trial: 'Prueba',
    expired: 'Expirada',
};

export default function Index({
    tenants,
    filters,
}: {
    tenants: Paginated<Tenant>;
    filters: { search: string };
}) {
    const [search, setSearch] = useState(filters.search ?? '');

    function submit(event: FormEvent) {
        event.preventDefault();
        router.get(route('super-admin.tenants.index'), { search }, { preserveState: true });
    }

    return (
        <SuperAdminLayout
            title="Negocios"
            actions={
                <Link
                    href={route('super-admin.tenants.create')}
                    className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                >
                    Crear negocio
                </Link>
            }
        >
            <div className="space-y-6">
                <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <form onSubmit={submit} className="flex flex-wrap gap-3">
                        <TextInput
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Buscar por nombre o email"
                            className="min-w-72 rounded-md border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500"
                        />
                        <button
                            type="submit"
                            className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                        >
                            Buscar
                        </button>
                    </form>
                </div>

                <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    {tenants.data.length === 0 ? (
                        <div className="py-16 text-center">
                            <h2 className="text-xl font-semibold text-gray-900">No hay negocios creados</h2>
                            <p className="mt-2 text-sm text-gray-500">
                                Crea el primer negocio para empezar a administrar la plataforma.
                            </p>
                            <Link
                                href={route('super-admin.tenants.create')}
                                className="mt-5 inline-flex rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                            >
                                Crear negocio
                            </Link>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200 text-sm">
                                <thead>
                                    <tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        <th className="px-4 py-3">Nombre</th>
                                        <th className="px-4 py-3">País</th>
                                        <th className="px-4 py-3">Estado</th>
                                        <th className="px-4 py-3">Usuarios</th>
                                        <th className="px-4 py-3">Imágenes</th>
                                        <th className="px-4 py-3">Suscripción</th>
                                        <th className="px-4 py-3 text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {tenants.data.map((tenant) => (
                                        <tr key={tenant.id} className="hover:bg-gray-50">
                                            <td className="px-4 py-3 font-semibold text-gray-900">
                                                {tenant.name}
                                                {tenant.email && (
                                                    <div className="text-xs font-normal text-gray-500">
                                                        {tenant.email}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-gray-600">{tenant.country ?? '-'}</td>
                                            <td className="px-4 py-3">
                                                <Badge
                                                    className={
                                                        tenant.is_active
                                                            ? 'bg-green-100 text-green-700'
                                                            : 'bg-red-100 text-red-700'
                                                    }
                                                >
                                                    {tenant.is_active ? 'Activa' : 'Inactiva'}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
                                                    {tenant.active_users_count} / {tenant.tenant_setting?.max_users ?? 1}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge
                                                    className={
                                                        tenant.tenant_setting?.use_product_images ?? true
                                                            ? 'bg-blue-100 text-blue-700'
                                                            : 'bg-gray-100 text-gray-700'
                                                    }
                                                >
                                                    {tenant.tenant_setting?.use_product_images ?? true
                                                        ? 'Activas'
                                                        : 'Inactivas'}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3">
                                                {subscriptionBadge(tenant.latest_subscription?.status)}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex justify-end gap-2">
                                                    <ActionLink href={route('super-admin.tenants.edit', tenant.id)}>
                                                        Editar
                                                    </ActionLink>
                                                    <ActionLink href={route('super-admin.tenants.users', tenant.id)}>
                                                        Usuarios
                                                    </ActionLink>
                                                    <ActionLink
                                                        href={route('super-admin.tenants.subscription', tenant.id)}
                                                    >
                                                        Suscripción
                                                    </ActionLink>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </SuperAdminLayout>
    );
}

function Badge({ children, className }: { children: ReactNode; className: string }) {
    return (
        <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${className}`}>
            {children}
        </span>
    );
}

function ActionLink({ href, children }: { href: string; children: ReactNode }) {
    return (
        <Link
            href={href}
            className="rounded-md border border-gray-300 px-3 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-100"
        >
            {children}
        </Link>
    );
}

function subscriptionBadge(status?: string | null) {
    if (!status) {
        return <Badge className="bg-gray-100 text-gray-700">Sin suscripción</Badge>;
    }

    const classes: Record<string, string> = {
        active: 'bg-green-100 text-green-700',
        paused: 'bg-yellow-100 text-yellow-700',
        cancelled: 'bg-red-100 text-red-700',
        trial: 'bg-blue-100 text-blue-700',
        expired: 'bg-red-100 text-red-700',
    };

    return (
        <Badge className={classes[status] ?? 'bg-gray-100 text-gray-700'}>
            {statusLabels[status] ?? status}
        </Badge>
    );
}
