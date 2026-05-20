import { formatCurrency } from '@/utils/currency';
import { Head, usePage } from '@inertiajs/react';
import { useEffect } from 'react';

type ReceiptSale = {
    id: number;
    created_at_local: string | null;
    total: number;
    created_by: string | null;
    customer: { name: string; doc_type: string | null; doc_number: string | null } | null;
    items: { id: number; product_name: string; quantity: number; unit_price: number; total: number }[];
    payments: { id: number; method: string; amount: number; reference: string | null }[];
};

type Company = {
    logo_url: string | null;
    name: string | null;
    tax_id: string | null;
    address: string | null;
    phone: string | null;
};

const paymentLabels: Record<string, string> = {
    cash: 'Efectivo',
    card: 'Tarjeta',
    transfer: 'Transferencia',
    check: 'Cheque',
    mercadopago: 'MercadoPago',
    bizum: 'Bizum',
    other: 'Otro',
};

export default function Receipt({
    sale,
    company,
    paperSize,
}: {
    sale: ReceiptSale;
    company: Company;
    paperSize: 'A4' | 'Letter';
}) {
    const business = usePage().props.business as { country?: string | null } | null;
    const country = business?.country ?? 'GT';

    useEffect(() => {
        const timer = window.setTimeout(() => window.print(), 350);

        return () => window.clearTimeout(timer);
    }, []);

    return (
        <>
            <Head title={`Comprobante #${sale.id}`} />
            <style>{`
                @page { size: ${paperSize}; margin: 14mm; }
                @media print {
                    .no-print { display: none !important; }
                    body { background: white !important; color: black !important; }
                    .receipt-page { box-shadow: none !important; border: none !important; margin: 0 !important; width: 100% !important; }
                }
            `}</style>

            <div className="min-h-screen bg-slate-100 px-4 py-6 text-slate-950">
                <div className="no-print mx-auto mb-4 flex max-w-4xl justify-end gap-2">
                    <button
                        type="button"
                        onClick={() => window.print()}
                        className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                    >
                        Imprimir
                    </button>
                    <button
                        type="button"
                        onClick={() => window.close()}
                        className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                    >
                        Cerrar
                    </button>
                </div>

                <main className="receipt-page mx-auto max-w-4xl rounded-2xl border border-slate-200 bg-white p-8 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                    <header className="flex items-start justify-between gap-6 border-b border-slate-200 pb-6">
                        <div className="flex items-start gap-4">
                            {company.logo_url && (
                                <img src={company.logo_url} alt="Logo" className="h-20 w-20 object-contain" />
                            )}
                            <div>
                                <h1 className="text-2xl font-bold">{company.name ?? 'Empresa'}</h1>
                                {company.tax_id && <p className="mt-1 text-sm">Identificación fiscal: {company.tax_id}</p>}
                                {company.address && <p className="text-sm">{company.address}</p>}
                                {company.phone && <p className="text-sm">Teléfono: {company.phone}</p>}
                            </div>
                        </div>
                        <div className="text-right">
                            <div className="text-xl font-bold uppercase">Comprobante de venta</div>
                            <div className="mt-2 text-sm">Venta #{sale.id}</div>
                            <div className="text-sm">Fecha: {sale.created_at_local ?? '-'}</div>
                        </div>
                    </header>

                    <section className="grid gap-4 border-b border-slate-200 py-5 text-sm md:grid-cols-2">
                        <div>
                            <div className="font-semibold">Cliente</div>
                            <div>{sale.customer?.name ?? 'Consumidor Final'}</div>
                            {sale.customer?.doc_number && (
                                <div>{sale.customer.doc_type ?? 'Documento'}: {sale.customer.doc_number}</div>
                            )}
                        </div>
                        <div className="md:text-right">
                            <div className="font-semibold">Usuario</div>
                            <div>{sale.created_by ?? '-'}</div>
                        </div>
                    </section>

                    <section className="py-5">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-slate-300 text-left">
                                    <th className="py-2">Producto</th>
                                    <th className="py-2 text-right">Cantidad</th>
                                    <th className="py-2 text-right">Precio unitario</th>
                                    <th className="py-2 text-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                {sale.items.map((item) => (
                                    <tr key={item.id} className="border-b border-slate-100">
                                        <td className="py-2">{item.product_name}</td>
                                        <td className="py-2 text-right">{item.quantity}</td>
                                        <td className="py-2 text-right">{formatCurrency(item.unit_price, country)}</td>
                                        <td className="py-2 text-right font-semibold">{formatCurrency(item.total, country)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>

                    <section className="grid gap-6 border-t border-slate-200 pt-5 md:grid-cols-2">
                        <div>
                            <h2 className="font-semibold">Método de pago</h2>
                            <div className="mt-2 space-y-1 text-sm">
                                {sale.payments.map((payment) => (
                                    <div key={payment.id} className="flex justify-between gap-4">
                                        <span>{paymentLabels[payment.method] ?? payment.method}</span>
                                        <span className="font-semibold">{formatCurrency(payment.amount, country)}</span>
                                    </div>
                                ))}
                            </div>
                        </div>

                        <div className="text-right">
                            <div className="text-sm font-semibold uppercase text-slate-500">Total</div>
                            <div className="mt-1 text-4xl font-bold">{formatCurrency(sale.total, country)}</div>
                        </div>
                    </section>

                    <footer className="mt-10 border-t border-slate-200 pt-5 text-center text-sm font-semibold">
                        Gracias por su compra
                    </footer>
                </main>
            </div>
        </>
    );
}
