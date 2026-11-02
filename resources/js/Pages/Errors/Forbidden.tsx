import { Head } from '@inertiajs/react';

export default function Forbidden({ message }: { message?: string }) {
    return <main className="flex min-h-screen items-center justify-center bg-slate-50 px-5"><Head title="Acceso denegado" /><section className="max-w-md rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm"><p className="text-sm font-semibold text-orange-600">BLUNK</p><h1 className="mt-3 text-2xl font-bold text-slate-950">No tienes permiso para acceder a esta sección</h1><p className="mt-3 text-sm leading-6 text-slate-600">{message ?? 'Tu usuario no dispone de la autorización necesaria. Si necesitas acceso, contacta con el administrador de tu empresa.'}</p><button type="button" onClick={() => window.history.back()} className="mt-6 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Volver</button></section></main>;
}
