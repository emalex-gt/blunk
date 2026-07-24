type Props = {
    open: boolean;
    title: string;
    message: string;
    details?: string;
    confirmLabel: string;
    cancelLabel?: string;
    processing?: boolean;
    onCancel: () => void;
    onConfirm: () => void;
};

export default function ConfirmDialog({
    open,
    title,
    message,
    details,
    confirmLabel,
    cancelLabel = 'Cancelar',
    processing = false,
    onCancel,
    onConfirm,
}: Props) {
    if (!open) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-end bg-slate-950/50 p-4 sm:items-center sm:justify-center">
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="confirm-dialog-title"
                className="w-full rounded-2xl bg-white p-5 shadow-xl sm:max-w-md"
            >
                <h2 id="confirm-dialog-title" className="text-lg font-semibold text-slate-950">
                    {title}
                </h2>
                <p className="mt-2 text-sm text-slate-600">{message}</p>
                {details && <p className="mt-2 text-sm font-semibold text-slate-800">{details}</p>}
                <div className="mt-5 grid grid-cols-2 gap-2">
                    <button
                        type="button"
                        disabled={processing}
                        onClick={onCancel}
                        className="rounded-xl bg-white px-4 py-3 text-sm font-semibold text-slate-700 ring-1 ring-slate-200 disabled:opacity-60"
                    >
                        {cancelLabel}
                    </button>
                    <button
                        type="button"
                        disabled={processing}
                        onClick={onConfirm}
                        className="rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white disabled:opacity-60"
                    >
                        {processing ? 'Procesando...' : confirmLabel}
                    </button>
                </div>
            </div>
        </div>
    );
}
