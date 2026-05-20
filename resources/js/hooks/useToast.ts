import { ToastMessage, ToastType } from '@/Components/Toast';
import { useCallback, useState } from 'react';

let nextToastId = 1;

type ToastOptions = {
    persistent?: boolean;
};

export function useToast() {
    const [toasts, setToasts] = useState<ToastMessage[]>([]);

    const removeToast = useCallback((id: number) => {
        setToasts((current) => current.filter((toast) => toast.id !== id));
    }, []);

    const showToast = useCallback((type: ToastType, message: string, options: ToastOptions = {}) => {
        const id = nextToastId++;

        setToasts((current) => [...current, { id, type, message }]);

        if (options.persistent) {
            return;
        }

        window.setTimeout(() => {
            removeToast(id);
        }, 4000);
    }, [removeToast]);

    return {
        toasts,
        removeToast,
        success: (message: string, options?: ToastOptions) => showToast('success', message, options),
        error: (message: string, options?: ToastOptions) => showToast('error', message, options),
        warning: (message: string, options?: ToastOptions) => showToast('warning', message, options),
        info: (message: string, options?: ToastOptions) => showToast('info', message, options),
    };
}
