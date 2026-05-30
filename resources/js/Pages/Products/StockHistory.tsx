import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { getProductImageUrl } from '@/lib/cloudinary';
import { Head, Link } from '@inertiajs/react';

type Product = {
    id: number;
    name: string;
    code: string | null;
    barcode: string | null;
    stock: number;
    reserved_stock?: number;
    available_stock?: number;
    branch?: { id: number; name: string } | null;
    location: string | null;
    image_url: string | null;
};

type User = {
    id: number;
    name: string;
};

type Movement = {
    id: number;
    created_at: string;
    type: string;
    quantity: number;
    previous_stock: number | null;
    new_stock: number | null;
    note: string | null;
    created_by: User | null;
    user: User | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedMovements = {
    data: Movement[];
    links: PaginationLink[];
};

const typeLabels: Record<string, string> = {
    initial: 'Inicial',
    in: 'Entrada',
    out: 'Salida',
    sale: 'Venta',
    sale_cancel: 'Anulación de venta',
    adjustment: 'Ajuste',
    entry: 'Entrada',
    exit: 'Salida',
    add: 'Entrada',
    remove: 'Salida',
    purchase: 'Compra',
};

function formatDate(value: string) {
    return new Intl.DateTimeFormat('es', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}

function cleanPaginationLabel(label: string) {
    return label
        .replace('&laquo; Previous', 'Anterior')
        .replace('Next &raquo;', 'Siguiente');
}

export default function StockHistory({
    product,
    movements,
    use_product_images = true,
}: {
    product: Product;
    movements: PaginatedMovements;
    use_product_images?: boolean;
}) {
    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-xl font-semibold text-gray-800">
                        Historial de stock
                    </h2>
                    <Link
                        href={route('products.index')}
                        className="rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50"
                    >
                        Volver a productos
                    </Link>
                </div>
            }
        >
            <Head title="Historial de stock" />

            <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                <section className="mb-6 rounded-lg bg-white p-5 shadow">
                    <div className="flex flex-wrap items-center gap-4">
                        {use_product_images && product.image_url ? (
                            <img
                                src={getProductImageUrl(product.image_url, 160) ?? ''}
                                alt={product.name}
                                className="h-20 w-20 rounded-md object-cover"
                                loading="lazy"
                            />
                        ) : null}

                        <div className="min-w-0 flex-1">
                            <div className="text-sm font-semibold uppercase text-gray-500">
                                Producto
                            </div>
                            <h1 className="truncate text-2xl font-bold text-gray-900">
                                {product.name}
                            </h1>
                            <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-600">
                                <span>{product.barcode || product.code || 'Sin código'}</span>
                                {product.location && <span>{product.location}</span>}
                            </div>
                        </div>

                        <div className="rounded-lg bg-gray-50 px-5 py-4 text-right">
                            <div className="text-sm font-semibold text-gray-500">
                                {product.branch ? `Sucursal: ${product.branch.name}` : 'Stock actual'}
                            </div>
                            <div className="mt-1 space-y-1 text-sm font-semibold text-gray-700">
                                <div>Existencia: {product.stock}</div>
                                <div>Reservado: {product.reserved_stock ?? 0}</div>
                                <div>Disponible: {product.available_stock ?? product.stock}</div>
                            </div>
                        </div>
                    </div>
                </section>

                <section className="rounded-lg bg-white p-5 shadow">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr className="text-left text-gray-500">
                                    <th className="py-2 pr-3">Fecha</th>
                                    <th className="px-3 py-2">Tipo</th>
                                    <th className="px-3 py-2 text-right">Cantidad</th>
                                    <th className="px-3 py-2 text-right">Stock anterior</th>
                                    <th className="px-3 py-2 text-right">Stock nuevo</th>
                                    <th className="px-3 py-2">Nota</th>
                                    <th className="py-2 pl-3">Usuario</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {movements.data.map((movement) => (
                                    <tr key={movement.id}>
                                        <td className="py-3 pr-3 text-gray-700">
                                            {formatDate(movement.created_at)}
                                        </td>
                                        <td className="px-3 py-3 font-medium text-gray-900">
                                            {typeLabels[movement.type] ?? movement.type}
                                        </td>
                                        <td className="px-3 py-3 text-right font-semibold text-gray-900">
                                            {movement.quantity}
                                        </td>
                                        <td className="px-3 py-3 text-right text-gray-700">
                                            {movement.previous_stock ?? '-'}
                                        </td>
                                        <td className="px-3 py-3 text-right text-gray-700">
                                            {movement.new_stock ?? '-'}
                                        </td>
                                        <td className="max-w-xs truncate px-3 py-3 text-gray-600">
                                            {movement.note ?? '-'}
                                        </td>
                                        <td className="py-3 pl-3 text-gray-700">
                                            {movement.created_by?.name ?? movement.user?.name ?? '-'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {movements.data.length === 0 && (
                        <div className="py-10 text-center text-sm font-medium text-gray-500">
                            Sin movimientos
                        </div>
                    )}

                    {movements.links.length > 3 && (
                        <div className="mt-5 flex flex-wrap gap-2">
                            {movements.links.map((link, index) =>
                                link.url ? (
                                    <Link
                                        key={`${link.label}-${index}`}
                                        href={link.url}
                                        preserveScroll
                                        className={`rounded-md border px-3 py-2 text-sm font-semibold ${
                                            link.active
                                                ? 'border-gray-900 bg-gray-900 text-white'
                                                : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
                                        }`}
                                    >
                                        {cleanPaginationLabel(link.label)}
                                    </Link>
                                ) : (
                                    <span
                                        key={`${link.label}-${index}`}
                                        className="rounded-md border border-gray-200 px-3 py-2 text-sm font-semibold text-gray-400"
                                    >
                                        {cleanPaginationLabel(link.label)}
                                    </span>
                                ),
                            )}
                        </div>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
