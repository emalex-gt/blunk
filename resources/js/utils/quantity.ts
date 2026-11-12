export type QuantityValue = string | number | null | undefined;

const SCALE = 4;
const SCALE_FACTOR = 10n ** BigInt(SCALE);

function scaledQuantity(value: QuantityValue): bigint | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const normalized = String(value).trim().replace(',', '.');
    const match = normalized.match(/^(-?)(?:([0-9]+)(?:\.([0-9]{0,4}))?|\.([0-9]{1,4}))$/);

    if (!match) {
        return null;
    }

    const sign = match[1] === '-' ? -1n : 1n;
    const whole = match[2] ?? '0';
    const fraction = (match[3] ?? match[4] ?? '').padEnd(SCALE, '0');

    return sign * ((BigInt(whole) * SCALE_FACTOR) + BigInt(fraction || '0'));
}

function fromScaled(value: bigint): string {
    const sign = value < 0n ? '-' : '';
    const absolute = value < 0n ? -value : value;
    const whole = absolute / SCALE_FACTOR;
    const fraction = (absolute % SCALE_FACTOR).toString().padStart(SCALE, '0').replace(/0+$/, '');

    return `${sign}${whole}${fraction === '' ? '' : `.${fraction}`}`;
}

export function canonicalQuantity(value: QuantityValue): string | null {
    const scaled = scaledQuantity(value);

    return scaled === null ? null : fromScaled(scaled);
}

export function compareQuantity(left: QuantityValue, right: QuantityValue): number | null {
    const leftScaled = scaledQuantity(left);
    const rightScaled = scaledQuantity(right);

    if (leftScaled === null || rightScaled === null) {
        return null;
    }

    return leftScaled === rightScaled ? 0 : leftScaled > rightScaled ? 1 : -1;
}

export function addQuantity(left: QuantityValue, right: QuantityValue): string | null {
    const leftScaled = scaledQuantity(left);
    const rightScaled = scaledQuantity(right);

    return leftScaled === null || rightScaled === null ? null : fromScaled(leftScaled + rightScaled);
}

export function subtractQuantity(left: QuantityValue, right: QuantityValue): string | null {
    const leftScaled = scaledQuantity(left);
    const rightScaled = scaledQuantity(right);

    return leftScaled === null || rightScaled === null ? null : fromScaled(leftScaled - rightScaled);
}

export function formatQuantity(value: QuantityValue): string {
    return canonicalQuantity(value) ?? (value === null || value === undefined || value === '' ? '0' : String(value));
}
