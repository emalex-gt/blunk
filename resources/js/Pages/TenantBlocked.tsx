import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

export default function TenantBlocked({ message }: { message: string }) {
    return (
        <AuthenticatedLayout>
            <Head title="Acceso bloqueado" />
            <div className="mx-auto max-w-2xl px-4 py-12">
                <div className="rounded-lg bg-white p-8 text-center shadow">
                    <h1 className="text-xl font-semibold text-gray-900">
                        Acceso bloqueado
                    </h1>
                    <p className="mt-3 text-gray-600">{message}</p>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
