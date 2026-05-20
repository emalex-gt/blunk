export type ToastType = 'success' | 'error' | 'warning' | 'info';

export type ToastMessage = {
    id: number;
    type: ToastType;
    message: string;
};

const toastStyles: Record<ToastType, string> = {
    success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    error: 'border-red-200 bg-red-50 text-red-800',
    warning: 'border-amber-200 bg-amber-50 text-amber-800',
    info: 'border-indigo-200 bg-indigo-50 text-indigo-800',
};

export default function Toast({
    toasts,
    onClose,
}: {
    toasts: ToastMessage[];
    onClose: (id: number) => void;
}) {
    if (toasts.length === 0) {
        return null;
    }

    return (
        <div className="fixed right-4 top-4 z-[100] w-full max-w-sm space-y-3">
            {toasts.map((toast) => (
                <div
                    key={toast.id}
                    className={[
                        'rounded-xl border px-4 py-3 shadow-lg transition-all duration-200',
                        toastStyles[toast.type],
                    ].join(' ')}
                >
                    <div className="flex items-start justify-between gap-3">
                        <p className="text-sm font-semibold">{toast.message}</p>
                        <button
                            type="button"
                            onClick={() => onClose(toast.id)}
                            className="rounded-md px-1 text-lg leading-none opacity-70 transition hover:opacity-100"
                            aria-label="Cerrar"
                        >
                            ×
                        </button>
                    </div>
                </div>
            ))}
        </div>
    );
}
