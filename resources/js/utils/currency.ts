type CurrencyConfig = {
    symbol: string;
    position: 'before' | 'after';
};

const currencies: Record<string, CurrencyConfig> = {
    AR: { symbol: '$', position: 'before' },
    GT: { symbol: 'Q', position: 'before' },
};

export function formatCurrency(amount: number | string | null | undefined, country?: string | null): string {
    const config = currencies[country || 'GT'] ?? currencies.GT;
    const value = Number(amount ?? 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    if (config.position === 'after') {
        return `${value}\u00A0${config.symbol}`;
    }

    return `${config.symbol}\u00A0${value}`;
}
