import { useEffect, useRef, useState } from 'react';

export type SupplierInfo = {
    supplier_name: string;
    supplier_phone?: string | null;
    supplier_email?: string | null;
    supplier_address?: string | null;
    supplier_contact_person?: string | null;
};

export default function SupplierInfoPopover({ supplier }: { supplier: SupplierInfo }) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLSpanElement>(null);
    const details = [
        ['Persona de contacto', supplier.supplier_contact_person],
        ['Teléfono', supplier.supplier_phone],
        ['Email', supplier.supplier_email],
        ['Dirección', supplier.supplier_address],
    ].filter(([, value]) => Boolean(value));

    useEffect(() => {
        if (!open) {
            return;
        }

        const handleClickOutside = (event: MouseEvent) => {
            if (ref.current && !ref.current.contains(event.target as Node)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', handleClickOutside);

        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, [open]);

    return (
        <span ref={ref} className="relative inline-block">
            <button
                type="button"
                onClick={() => setOpen((current) => !current)}
                className="font-semibold text-indigo-600 underline decoration-dotted underline-offset-4 transition hover:text-indigo-700"
            >
                {supplier.supplier_name}
            </button>

            {open && (
                <span className="absolute left-0 top-full z-50 mt-2 block w-64 rounded-xl border border-slate-200 bg-white p-3 text-left text-sm text-slate-700 shadow-lg">
                    <span className="block font-semibold text-slate-950">
                        Proveedor: {supplier.supplier_name}
                    </span>

                    {details.length === 0 ? (
                        <span className="mt-2 block text-slate-500">Sin información adicional</span>
                    ) : (
                        <span className="mt-2 block space-y-1">
                            {details.map(([label, value]) => (
                                <span key={label} className="block">
                                    <span className="font-medium text-slate-600">{label}:</span>{' '}
                                    <span>{value}</span>
                                </span>
                            ))}
                        </span>
                    )}
                </span>
            )}
        </span>
    );
}
