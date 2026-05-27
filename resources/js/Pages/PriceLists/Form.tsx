import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type PriceType = {
    id: number;
    name: string;
    is_default: boolean;
    is_active: boolean;
};

export default function Form({ priceType }: { priceType: PriceType | null }) {
    const editing = Boolean(priceType);
    const form = useForm({
        name: priceType?.name ?? '',
        is_active: priceType?.is_active ?? true,
        is_default: priceType?.is_default ?? false,
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        if (priceType) {
            form.patch(route('price-lists.update', priceType.id));
            return;
        }

        form.post(route('price-lists.store'));
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-950">{editing ? 'Editar lista' : 'Nueva lista'}</h2>}>
            <Head title={editing ? 'Editar lista' : 'Nueva lista'} />

            <div className="mx-auto max-w-2xl px-5 py-6 sm:px-6">
                <form onSubmit={submit} className="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-950">{editing ? 'Editar lista de precios' : 'Crear lista de precios'}</h1>
                        <p className="mt-1 text-sm text-slate-500">Solo puede existir una lista predeterminada activa por empresa.</p>
                    </div>

                    <div>
                        <InputLabel htmlFor="name" value="Nombre" />
                        <TextInput id="name" className="mt-1 block w-full" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
                        <InputError message={form.errors.name} className="mt-2" />
                    </div>

                    <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input type="checkbox" checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} className="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                        Activa
                    </label>
                    <InputError message={form.errors.is_active} />

                    <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input type="checkbox" checked={form.data.is_default} onChange={(event) => form.setData('is_default', event.target.checked)} className="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                        Predeterminada
                    </label>
                    <InputError message={form.errors.is_default} />

                    <div className="flex gap-2">
                        <PrimaryButton disabled={form.processing}>{editing ? 'Actualizar' : 'Crear'}</PrimaryButton>
                        <Link href={route('price-lists.index')}>
                            <SecondaryButton type="button">Cancelar</SecondaryButton>
                        </Link>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
