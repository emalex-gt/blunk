import SuperAdminLayout from '@/Layouts/SuperAdminLayout';

type Permission = { id: number; key: string; name: string; group: string | null; description: string | null };

export default function Permissions({ permissions }: { permissions: Permission[] }) {
    return (
        <SuperAdminLayout title="Permisos">
            <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div className="mb-4 flex gap-2">
                    <a className="rounded-lg bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-700" href={route('super-admin.security.roles')}>Roles</a>
                    <a className="rounded-lg bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-700" href={route('super-admin.security.assignments')}>Asignaciones</a>
                </div>
                <table className="min-w-full text-sm">
                    <thead className="text-left text-xs uppercase text-slate-500">
                        <tr><th className="py-2">Grupo</th><th>Permiso</th><th>Clave</th></tr>
                    </thead>
                    <tbody>
                        {permissions.map((permission) => (
                            <tr key={permission.id} className="border-t border-slate-100">
                                <td className="py-3 text-slate-500">{permission.group ?? 'Otros'}</td>
                                <td className="py-3 font-semibold text-slate-900">{permission.name}</td>
                                <td className="py-3"><code className="text-xs text-slate-500">{permission.key}</code></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
        </SuperAdminLayout>
    );
}
