import { ButtonHTMLAttributes } from 'react';

export default function PrimaryButton({
    className = '',
    disabled,
    children,
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement>) {
    return (
        <button
            {...props}
            className={
                `inline-flex items-center rounded-xl border border-transparent bg-indigo-600 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:bg-indigo-700 focus:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-100 active:scale-[0.98] active:bg-indigo-800 ${
                    disabled && 'opacity-25'
                } ` + className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
