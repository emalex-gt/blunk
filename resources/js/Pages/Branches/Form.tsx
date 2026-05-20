import InputError from '@/Components/InputError';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import type { ReactNode } from 'react';

type Branch = {
    id: number;
    name: string;
    code: string | null;
    address: string | null;
    phone: string | null;
    is_active: boolean;
};

export default function Form({ branch }: { branch: Branch | null }) {
    const editing = Boolean(branch);
    const { data, setData, post, put, processing, errors } = useForm({
        name: branch?.name ?? '',
        code: branch?.code ?? '',
        address: branch?.address ?? '',
        phone: branch?.phone ?? '',
        is_active: branch?.is_active ?? true,
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        if (branch) {
            put(route('branches.update', branch.id));
            return;
        }

        post(route('branches.store'));
    }

    return (
        <AuthenticatedLayout>
            <Head title={editing ? 'Editar sucursal' : 'Nueva sucursal'} />
            <div className="mx-auto max-w-3xl px-4 py-6 sm:px-6">
                <form onSubmit={submit} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="mb-5">
                        <h1 className="text-2xl font-semibold text-slate-950">
                            {editing ? 'Editar sucursal' : 'Nueva sucursal'}
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">Mantén los datos operativos de la sucursal.</p>
                    </div>

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <Field label="Nombre" error={errors.name}>
                            <input className={inputClass} value={data.name} onChange={(event) => setData('name', event.target.value)} />
                        </Field>
                        <Field label="Código" error={errors.code}>
                            <input className={inputClass} value={data.code} onChange={(event) => setData('code', event.target.value)} />
                        </Field>
                        <Field label="Dirección" error={errors.address}>
                            <input className={inputClass} value={data.address} onChange={(event) => setData('address', event.target.value)} />
                        </Field>
                        <Field label="Teléfono" error={errors.phone}>
                            <input className={inputClass} value={data.phone} onChange={(event) => setData('phone', event.target.value)} />
                        </Field>
                    </div>

                    <label className="mt-4 flex items-center gap-2 text-sm font-semibold text-slate-700">
                        <input
                            type="checkbox"
                            checked={data.is_active}
                            onChange={(event) => setData('is_active', event.target.checked)}
                            className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                        />
                        Activa
                    </label>

                    <div className="mt-6 flex justify-end gap-3">
                        <Link href={route('branches.index')} className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            Cancelar
                        </Link>
                        <button disabled={processing} className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                            Guardar
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}

function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
    return (
        <label className="block text-sm font-medium text-slate-700">
            {label}
            <div className="mt-1">{children}</div>
            <InputError message={error} className="mt-1" />
        </label>
    );
}

const inputClass = 'h-11 w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100';
