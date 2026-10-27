export function limitDecimalPlaces(value: string, decimals = 2): string {
    const normalized = value.replace(',', '.');
    const decimalIndex = normalized.indexOf('.');

    if (decimalIndex === -1) {
        return normalized;
    }

    const integerPart = normalized.slice(0, decimalIndex);
    const decimalPart = normalized.slice(decimalIndex + 1, decimalIndex + 1 + Math.max(0, decimals));

    return decimals > 0 ? `${integerPart}.${decimalPart}` : integerPart;
}
