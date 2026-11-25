/**
 * Multiplies a unit price by a quantity using exact decimal
 * arithmetic and rounds half up to two monetary decimals.
 */
export function multiplyMoney(
    unitPrice: number | string,
    quantity: number | string,
): number {
    const price = String(unitPrice).trim().replace(',', '.');
    const qty = String(quantity).trim().replace(',', '.');

    const decimalPattern = /^(?:\d+(?:\.\d+)?|\.\d+)$/;

    if (!decimalPattern.test(price) || !decimalPattern.test(qty)) {
        return 0;
    }

    const [priceWhole, priceFraction = ''] = price.split('.');
    const [qtyWhole, qtyFraction = ''] = qty.split('.');

    const priceUnits = BigInt(
        (priceWhole || '0') + priceFraction,
    );

    const quantityUnits = BigInt(
        (qtyWhole || '0') + qtyFraction,
    );

    const denominator =
        10n ** BigInt(priceFraction.length + qtyFraction.length);

    const numerator = priceUnits * quantityUnits * 100n;

    const cents =
        (numerator * 2n + denominator) / (denominator * 2n);

    return Number(cents) / 100;
}

/**
 * Calculates a percentage discount using exact decimal arithmetic.
 * The percentage is normalized to two decimals, as in the backend.
 */
export function percentageMoney(
    subtotal: number | string,
    percentage: number | string,
): number {
    const normalizedPercentage = multiplyMoney(percentage, 1);
    const rate = (normalizedPercentage / 100).toFixed(4);

    return multiplyMoney(subtotal, rate);
}
