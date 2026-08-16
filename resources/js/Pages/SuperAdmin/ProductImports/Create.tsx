import InputError from '@/Components/InputError';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout';
import { Link, router, useForm } from '@inertiajs/react';
import { FormEvent, ReactNode } from 'react';

type Business = {
    id: number;
    name: string;
    settings: {
        allow_duplicate_product_codes: boolean;
        allow_duplicate_product_barcodes: boolean;
    };
};

type Branch = {
    id: number;
    name: string;
    code: string | null;
};

type PreviewRow = {
    row_number: number;
    name: string | null;
    quantity: number | null;
    brand: string | null;
    supplier: string | null;
    barcode: string | null;
    code: string | null;
    category: string | null;
    cost_price: number | null;
    sale_price: number | null;
    action: 'create' | 'increment' | 'reject';
    status: 'ok' | 'warning' | 'error';
    messages: string[];
};

type Summary = Record<string, number | string | boolean | null>;

type Preview = {
    token: string | null;
    filename: string | null;
    branch: { id: number; name: string };
    missing_columns: string[];
    rows: PreviewRow[];
    summary: Summary;
    can_confirm: boolean;
};

export default function Create({
    business,
    branches,
    preview,
    result,
    reportUrl,
}: {
    business: Business;
    branches: Branch[];
    preview: Preview | null;
    result: Summary | null;
    reportUrl: string | null;
}) {
    const form = useForm({
        branch_id: branches[0]?.id ? String(branches[0].id) : '',
        file: null as File | null,
    });

    function submitPreview(event: FormEvent) {
        event.preventDefault();
        form.post(route('super-admin.product-imports.preview', business.id), {
            forceFormData: true,
            preserveScroll: true,
        });
    }

    function confirmImport() {
        if (!preview?.token || !preview.can_confirm) {
            return;
        }

        router.post(route('super-admin.product-imports.confirm', business.id), {
            branch_id: preview.branch.id,
            token: preview.token,
            filename: preview.filename,
        }, {
            preserveScroll: true,
        });
    }

    return (
        <SuperAdminLayout
            title={`Importar productos - ${business.name}`}
            actions={
                <Link href={route('super-admin.tenants.index')} className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Volver a negocios
                </Link>
            }
        >
            <div className="space-y-6">
                <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h1 className="text-2xl font-semibold text-slate-950">Importar productos</h1>
                            <p className="mt-1 text-sm text-slate-500">
                                Carga inicial o incremental para {business.name}. Esta herramienta es solo para Super Admin.
                            </p>
                        </div>
                        <a href={route('super-admin.product-imports.template')} className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                            Descargar plantilla
                        </a>
                    </div>

                    <div className="mt-4 grid gap-3 text-sm text-slate-600 lg:grid-cols-2">
                        <Info>
                            Si el negocio no permite códigos/códigos de barras duplicados, los productos existentes sumarán inventario y no se actualizarán datos ni precios.
                        </Info>
                        <Info>
                            Si el negocio permite duplicados, se creará un producto nuevo por cada fila válida. Las diferencias de costo/precio se reportarán en Excel.
                        </Info>
                    </div>
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <form onSubmit={submitPreview} className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
                        <label className="text-sm font-semibold text-slate-700">
                            Sucursal destino
                            <select
                                value={form.data.branch_id}
                                onChange={(event) => form.setData('branch_id', event.target.value)}
                                className="mt-1 h-11 w-full rounded-xl border-slate-200 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                {branches.map((branch) => (
                                    <option key={branch.id} value={branch.id}>
                                        {branch.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.branch_id} className="mt-1" />
                        </label>

                        <label className="text-sm font-semibold text-slate-700">
                            Archivo Excel .xlsx
                            <input
                                type="file"
                                accept=".xlsx"
                                onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)}
                                className="mt-1 block h-11 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm shadow-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1 file:text-sm file:font-semibold file:text-slate-700"
                            />
                            <InputError message={form.errors.file} className="mt-1" />
                        </label>

                        <button
                            type="submit"
                            disabled={form.processing}
                            className="h-11 rounded-xl bg-slate-900 px-5 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                        >
                            {form.processing ? 'Validando...' : 'Generar preview'}
                        </button>
                    </form>
                </section>

                {result && (
                    <section className="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 className="text-lg font-semibold text-emerald-950">Importación completada</h2>
                                <p className="mt-1 text-sm text-emerald-700">
                                    Productos creados: {result.new_products_created ?? 0}. Inventario sumado: {result.existing_products_incremented ?? 0}. Rechazados: {result.rejected_rows ?? 0}.
                                </p>
                            </div>
                            {reportUrl && (
                                <a href={reportUrl} className="rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                                    Descargar reporte Excel
                                </a>
                            )}
                        </div>
                    </section>
                )}

                {preview && (
                    <section className="space-y-5">
                        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <h2 className="text-xl font-semibold text-slate-950">Preview de importación</h2>
                                    <p className="mt-1 text-sm text-slate-500">
                                        Archivo: {preview.filename ?? '-'} · Sucursal: {preview.branch.name}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    disabled={!preview.can_confirm}
                                    onClick={confirmImport}
                                    className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300"
                                >
                                    Confirmar importación
                                </button>
                            </div>

                            {preview.missing_columns.length > 0 ? (
                                <div className="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-700">
                                    Faltan columnas obligatorias: {preview.missing_columns.join(', ')}
                                </div>
                            ) : (
                                <div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                                    <Metric label="Filas leídas" value={preview.summary.total_rows} />
                                    <Metric label="Productos nuevos" value={preview.summary.new_products} />
                                    <Metric label="Inventario a sumar" value={preview.summary.inventory_increments} />
                                    <Metric label="Rechazados" value={preview.summary.rejected_rows} />
                                    <Metric label="Advertencias" value={Number(preview.summary.price_warnings ?? 0) + Number(preview.summary.allowed_duplicates ?? 0)} />
                                    <Metric label="Categorías nuevas" value={preview.summary.categories_new} />
                                    <Metric label="Marcas nuevas" value={preview.summary.brands_new} />
                                    <Metric label="Proveedores nuevos" value={preview.summary.suppliers_new} />
                                    <Metric label="Dup. códigos" value={preview.summary.allow_duplicate_product_codes ? 'Permitidos' : 'Bloqueados'} />
                                    <Metric label="Dup. barras" value={preview.summary.allow_duplicate_product_barcodes ? 'Permitidos' : 'Bloqueados'} />
                                </div>
                            )}
                        </div>

                        {preview.rows.length > 0 && (
                            <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                                <div className="overflow-x-auto">
                                    <table className="min-w-[1300px] text-sm">
                                        <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                            <tr>
                                                <th className="px-3 py-3">Fila</th>
                                                <th className="px-3 py-3">Nombre</th>
                                                <th className="px-3 py-3">Cantidad</th>
                                                <th className="px-3 py-3">Marca</th>
                                                <th className="px-3 py-3">Proveedor</th>
                                                <th className="px-3 py-3">Código de barras</th>
                                                <th className="px-3 py-3">Código</th>
                                                <th className="px-3 py-3">Categoría</th>
                                                <th className="px-3 py-3">Costo</th>
                                                <th className="px-3 py-3">Precio venta</th>
                                                <th className="px-3 py-3">Acción</th>
                                                <th className="px-3 py-3">Estado</th>
                                                <th className="px-3 py-3">Mensajes</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {preview.rows.map((row) => (
                                                <tr key={row.row_number} className={row.status === 'error' ? 'bg-red-50/50' : row.status === 'warning' ? 'bg-amber-50/50' : ''}>
                                                    <td className="px-3 py-3 font-semibold">{row.row_number}</td>
                                                    <td className="px-3 py-3 font-semibold text-slate-900">{row.name ?? '-'}</td>
                                                    <td className="px-3 py-3">{row.quantity ?? '-'}</td>
                                                    <td className="px-3 py-3">{row.brand ?? '-'}</td>
                                                    <td className="px-3 py-3">{row.supplier ?? '-'}</td>
                                                    <td className="px-3 py-3">{row.barcode ?? '-'}</td>
                                                    <td className="px-3 py-3">{row.code ?? '-'}</td>
                                                    <td className="px-3 py-3">{row.category ?? '-'}</td>
                                                    <td className="px-3 py-3">{row.cost_price ?? '-'}</td>
                                                    <td className="px-3 py-3">{row.sale_price ?? '-'}</td>
                                                    <td className="px-3 py-3">{actionLabel(row.action)}</td>
                                                    <td className="px-3 py-3"><StatusBadge status={row.status} /></td>
                                                    <td className="max-w-sm px-3 py-3 text-xs text-slate-600">{row.messages.join(' | ') || '-'}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}
                    </section>
                )}
            </div>
        </SuperAdminLayout>
    );
}

function Info({ children }: { children: ReactNode }) {
    return <div className="rounded-xl border border-slate-200 bg-slate-50 p-3">{children}</div>;
}

function Metric({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-slate-50 p-3">
            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-1 text-lg font-bold text-slate-950">{value}</div>
        </div>
    );
}

function StatusBadge({ status }: { status: PreviewRow['status'] }) {
    const classes = {
        ok: 'bg-emerald-100 text-emerald-700',
        warning: 'bg-amber-100 text-amber-700',
        error: 'bg-red-100 text-red-700',
    };
    const labels = { ok: 'OK', warning: 'Advertencia', error: 'Error' };

    return <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${classes[status]}`}>{labels[status]}</span>;
}

function actionLabel(action: PreviewRow['action']) {
    return {
        create: 'Crear producto',
        increment: 'Sumar inventario',
        reject: 'Rechazado',
    }[action];
}
