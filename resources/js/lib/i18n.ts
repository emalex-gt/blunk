import es from '@/lang/es';

type TranslationKey = keyof typeof es;

export function t(key: TranslationKey): string {
    return es[key];
}

export type { TranslationKey };
