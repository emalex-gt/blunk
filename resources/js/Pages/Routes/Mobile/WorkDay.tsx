import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

type Visit = {
    id: number;
    status: string;
    visit_order: number | null;
    customer: { name: string; doc_number: string | null; address: string | null; phone: string | null };
    pre_sale?: { id: number; status: string; total: string } | null;
};

export default function WorkDay({ workDay, visits }: { workDay: { id: number; status: string; zone?: { name: string }; branch?: { name: string } }; visits: Visit[] }) {
    const mapHref = (visit: Visit) => `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(visit.customer.address || visit.customer.name)}`;

    return (
        <AuthenticatedLayout>
            <Head title="Jornada de ruta" />
            <div className="mx-auto max-w-xl space-y-4 px-4 pb-28 pt-5">
                <div>
                    <h1 className="text-2xl font-semibold text-slate-950">{workDay.zone?.name}</h1>
                    <p className="text-sm text-slate-500">{workDay.branch?.name} · {workDay.status}</p>
                </div>
                {visits.map((visit) => (
                    <div key={visit.id} className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="text-xs font-semibold uppercase text-slate-400">#{visit.visit_order ?? '-'}</p>
                                <h2 className="text-lg font-semibold text-slate-950">{visit.customer.name}</h2>
                                <p className="text-sm text-slate-500">{visit.customer.doc_number ?? '-'}</p>
                            </div>
                            <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">{visit.status}</span>
                        </div>
                        <p className="mt-2 text-sm text-slate-600">{visit.customer.address ?? 'Sin dirección'}</p>
                        {visit.pre_sale && (
                            <p className="mt-2 rounded-lg bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-700">
                                Preventa: Q {Number(visit.pre_sale.total).toFixed(2)} · {visit.pre_sale.status}
                            </p>
                        )}
                        <div className="mt-4 grid grid-cols-2 gap-2">
                            <a href={mapHref(visit)} target="_blank" className="rounded-xl bg-slate-100 px-3 py-3 text-center text-sm font-semibold text-slate-700">
                                Abrir Maps
                            </a>
                            <Link href={route('routes.mobile.visits.show', visit.id)} className="rounded-xl bg-indigo-600 px-3 py-3 text-center text-sm font-semibold text-white">
                                Crear/Editar preventa
                            </Link>
                            <button
                                onClick={() => router.post(route('routes.mobile.visits.without-sale', visit.id), {}, { preserveScroll: true })}
                                className="col-span-2 rounded-xl bg-white px-3 py-3 text-sm font-semibold text-slate-700 ring-1 ring-slate-200"
                            >
                                Sin compra
                            </button>
                        </div>
                    </div>
                ))}
                <div className="fixed inset-x-0 bottom-0 border-t border-slate-200 bg-white p-4">
                    <div className="mx-auto max-w-xl">
                        <button
                            onClick={() => router.post(route('routes.mobile.work-days.close', workDay.id))}
                            className="w-full rounded-xl bg-slate-950 px-4 py-3 text-base font-semibold text-white"
                        >
                            Cerrar jornada
                        </button>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
