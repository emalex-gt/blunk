import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.tsx',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                blunk: {
                    primary: '#F97316',
                    hover: '#EA580C',
                    soft: '#FFF7ED',
                    accent: '#F59E0B',
                    graphite: '#0F172A',
                    canvas: '#F3F4F6',
                    surface: '#FFFFFF',
                    muted: '#64748B',
                    info: '#0284C7',
                    success: '#16A34A',
                    warning: '#D97706',
                    danger: '#DC2626',
                },
            },
        },
    },

    plugins: [forms],
};
