export function formatQuantity(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '0';
    }

    const quantity = String(value);
    if (!/^-?\d+(?:\.\d{1,4})?$/.test(quantity)) {
        return quantity;
    }

    const [whole, fraction] = quantity.split('.');
    const trimmedFraction = fraction?.replace(/0+$/, '');
    const formatted = trimmedFraction === undefined || trimmedFraction === ''
        ? whole
        : `${whole}.${trimmedFraction}`;

    return formatted === '-0' ? '0' : formatted;
}
