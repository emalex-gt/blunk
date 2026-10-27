import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { getProductImageUrl } from '@/lib/cloudinary';
import { Head, Link } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';

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

type ReservationDetails = {
    physical_stock: number;
    reserved_pre_sales: number;
    reserved_credit_reservations: number;
    reserved_other: number;
    reserved_total: number;
    available_stock: number;
    pre_sale_reservations: {
        reservation_id: number;
        customer_name: string | null;
        customer_commercial_name: string | null;
        route_zone_name: string | null;
        seller_name: string | null;
        pre_sale_status: string;
        visit_status: string | null;
        quantity: number;
        created_at: string;
    }[];
    credit_reservations: {
        customer_name: string | null;
        status: string;
        quantity: number;
        created_at: string;
    }[];
    other_reservations: {
        reservation_id: number;
        source_type: string | null;
        source_id: number | null;
        quantity: number;
        created_at: string;
    }[];
    orphan_or_ignored_reservations: {
        reservation_id: number;
        reason: string;
        quantity: number;
        created_at: string;
    }[];
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
    can_view_reservations = false,
}: {
    product: Product;
    movements: PaginatedMovements;
    use_product_images?: boolean;
    can_view_reservations?: boolean;
}) {
    const [reservationDetails, setReservationDetails] = useState<ReservationDetails | null>(null);
    const [reservationLoading, setReservationLoading] = useState(false);
    const [reservationError, setReservationError] = useState<string | null>(null);

    async function loadReservations() {
        setReservationLoading(true);
        setReservationError(null);

        try {
            const response = await fetch(route('inventory.products.reservations', product.id), {
                headers: { Accept: 'application/json' },
            });

            if (! response.ok) {
                throw new Error('No fue posible cargar las reservas.');
            }

            setReservationDetails(await response.json() as ReservationDetails);
        } catch {
            setReservationError('No fue posible cargar las reservas. Intenta nuevamente.');
        } finally {
            setReservationLoading(false);
        }
    }

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
                            {can_view_reservations && Number(product.reserved_stock ?? 0) > 0 && (
                                <button
                                    type="button"
                                    onClick={() => reservationDetails ? setReservationDetails(null) : loadReservations()}
                                    disabled={reservationLoading}
                                    className="mt-3 rounded-md border border-orange-200 bg-white px-3 py-2 text-sm font-semibold text-orange-700 hover:bg-orange-50 disabled:cursor-wait disabled:opacity-60"
                                >
                                    {reservationLoading ? 'Cargando reservas…' : reservationDetails ? 'Ocultar reservas' : 'Ver reservas'}
                                </button>
                            )}
                        </div>
                    </div>
                </section>

                {can_view_reservations && reservationError && (
                    <p className="mb-6 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                        {reservationError}
                    </p>
                )}

                {can_view_reservations && reservationDetails && (
                    <section className="mb-6 space-y-5 rounded-lg bg-white p-5 shadow">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 className="text-lg font-semibold text-gray-900">Reservas activas</h2>
                                <p className="mt-1 text-sm text-gray-600">Detalle de la sucursal activa.</p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setReservationDetails(null)}
                                className="rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                            >
                                Cerrar
                            </button>
                        </div>

                        <div className="grid gap-3 sm:grid-cols-3">
                            <Summary label="Existencia física" value={reservationDetails.physical_stock} />
                            <Summary label="Reservado" value={reservationDetails.reserved_total} />
                            <Summary label="Disponible" value={reservationDetails.available_stock} />
                        </div>
                        <div className="grid gap-3 sm:grid-cols-3">
                            <Summary label="Preventas" value={reservationDetails.reserved_pre_sales} />
                            <Summary label="Crédito" value={reservationDetails.reserved_credit_reservations} />
                            <Summary label="Otras" value={reservationDetails.reserved_other} />
                        </div>

                        {reservationDetails.pre_sale_reservations.length > 0 && (
                            <ReservationSection title="Reservas de preventas">
                                {reservationDetails.pre_sale_reservations.map((reservation) => (
                                    <ReservationRow
                                        key={'pre-sale-' + reservation.reservation_id}
                                        title={reservation.quantity + ' unidades · ' + (reservation.customer_commercial_name || reservation.customer_name || 'Sin cliente')}
                                        detail={'Preventista: ' + (reservation.seller_name ?? 'Sin asignar') + ' · Zona: ' + (reservation.route_zone_name ?? 'Sin zona') + ' · Preventa: ' + reservation.pre_sale_status + (reservation.visit_status ? ' · Visita: ' + reservation.visit_status : '')}
                                        createdAt={reservation.created_at}
                                    />
                                ))}
                            </ReservationSection>
                        )}

                        {reservationDetails.credit_reservations.length > 0 && (
                            <ReservationSection title="Reservas de crédito">
                                {reservationDetails.credit_reservations.map((reservation, index) => (
                                    <ReservationRow
                                        key={'credit-' + index + '-' + reservation.created_at}
                                        title={reservation.quantity + ' unidades · ' + (reservation.customer_name ?? 'Sin cliente')}
                                        detail={'Estado: ' + reservation.status}
                                        createdAt={reservation.created_at}
                                    />
                                ))}
                            </ReservationSection>
                        )}

                        {reservationDetails.other_reservations.length > 0 && (
                            <ReservationSection title="Otras reservas">
                                {reservationDetails.other_reservations.map((reservation) => (
                                    <ReservationRow
                                        key={'other-' + reservation.reservation_id}
                                        title={reservation.quantity + ' unidades · ' + (reservation.source_type ?? 'Origen no especificado')}
                                        detail={'Origen técnico: ' + (reservation.source_type ?? '-') + (reservation.source_id ? ' #' + reservation.source_id : '')}
                                        createdAt={reservation.created_at}
                                    />
                                ))}
                            </ReservationSection>
                        )}

                        {reservationDetails.orphan_or_ignored_reservations.length > 0 && (
                            <ReservationSection title="Incidencias de reservas" warning>
                                {reservationDetails.orphan_or_ignored_reservations.map((reservation) => (
                                    <ReservationRow
                                        key={'ignored-' + reservation.reservation_id}
                                        title={reservation.quantity + ' unidades · Registro ignorado/no efectivo'}
                                        detail={'Motivo: ' + reservation.reason}
                                        createdAt={reservation.created_at}
                                        warning
                                    />
                                ))}
                            </ReservationSection>
                        )}
                    </section>
                )}

                <section className="rounded-lg bg-white p-5 shadow">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr className="text-left text-gray-500">
                                    <th className="py-2 pr-3">Fecha</th>
                                    <th className="px-3 py-2">Tipo</th>
                                    <th className="px-3 py-2 text-right">Stock anterior</th>
                                    <th className="px-3 py-2 text-right">Cantidad</th>
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
                                        <td className="px-3 py-3 text-right text-gray-700">
                                            {movement.previous_stock ?? '-'}
                                        </td>
                                        <td className="px-3 py-3 text-right font-semibold text-gray-900">
                                            {movement.quantity}
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

function Summary({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-md bg-gray-50 px-4 py-3">
            <div className="text-xs font-semibold uppercase tracking-wide text-gray-500">{label}</div>
            <div className="mt-1 text-lg font-bold text-gray-900">{value}</div>
        </div>
    );
}

function ReservationSection({
    title,
    warning = false,
    children,
}: {
    title: string;
    warning?: boolean;
    children: ReactNode;
}) {
    return (
        <div className={warning ? 'rounded-lg border border-amber-200 bg-amber-50 p-4' : 'rounded-lg border border-gray-200 p-4'}>
            <h3 className={warning ? 'font-semibold text-amber-900' : 'font-semibold text-gray-900'}>{title}</h3>
            <div className="mt-3 space-y-2">{children}</div>
        </div>
    );
}

function ReservationRow({
    title,
    detail,
    createdAt,
    warning = false,
}: {
    title: string;
    detail: string;
    createdAt: string;
    warning?: boolean;
}) {
    return (
        <div className={warning ? 'rounded-md border border-amber-200 bg-white/70 px-3 py-2' : 'rounded-md bg-gray-50 px-3 py-2'}>
            <p className={warning ? 'text-sm font-semibold text-amber-950' : 'text-sm font-semibold text-gray-900'}>{title}</p>
            <p className={warning ? 'mt-1 text-xs text-amber-800' : 'mt-1 text-xs text-gray-600'}>{detail}</p>
            <p className={warning ? 'mt-1 text-xs text-amber-800' : 'mt-1 text-xs text-gray-500'}>{formatDate(createdAt)}</p>
        </div>
    );
}
