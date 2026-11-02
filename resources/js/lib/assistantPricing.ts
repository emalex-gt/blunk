export type AssistantPricingInput = {
    productId: number | string;
    productName: string;
    price: string | number | null | undefined;
    label: string;
};

export type AssistantPricingAlert = {
    id: string;
    productId: number | string;
    productName: string;
    label: string;
    severity: 'critical' | 'info';
    kind: 'missing_cost' | 'below_cost' | 'at_cost' | 'low_markup' | 'high_markup';
    message: string;
    markupPercent?: number;
};

function numeric(value: string | number | null | undefined): number | null {
    if (value === null || value === undefined || (typeof value === 'string' && value.trim() === '')) {
        return null;
    }

    const result = Number(value);

    return Number.isFinite(result) ? result : null;
}

function markupLabel(value: number): string {
    return `${Math.round(value * 100) / 100}%`;
}

export function evaluateAssistantPricing(
    cost: string | number | null | undefined,
    prices: AssistantPricingInput[],
): AssistantPricingAlert[] {
    const availablePrices = prices.filter((price) => numeric(price.price) !== null);

    if (availablePrices.length === 0) {
        return [];
    }

    const numericCost = numeric(cost);

    if (numericCost === null || numericCost <= 0) {
        return [{
            id: 'missing-cost',
            productId: availablePrices[0].productId,
            productName: availablePrices[0].productName,
            label: 'Costo registrado',
            severity: 'info',
            kind: 'missing_cost',
            message: 'No hay un costo válido registrado. No se puede evaluar el recargo sobre costo.',
        }];
    }

    return availablePrices.flatMap<AssistantPricingAlert>((entry) => {
        const price = numeric(entry.price);

        if (price === null) {
            return [];
        }

        const markupPercent = ((price - numericCost) / numericCost) * 100;
        const base = {
            id: `${entry.productId}-${entry.label}`,
            productId: entry.productId,
            productName: entry.productName,
            label: entry.label,
            markupPercent,
        };

        if (price < numericCost) {
            return [{
                ...base,
                severity: 'critical' as const,
                kind: 'below_cost' as const,
                message: `El precio está por debajo del costo registrado (recargo sobre costo: ${markupLabel(markupPercent)}).`,
            }];
        }

        if (price === numericCost) {
            return [{
                ...base,
                severity: 'info' as const,
                kind: 'at_cost' as const,
                message: 'El precio es igual al costo registrado (recargo sobre costo: 0%).',
            }];
        }

        if (markupPercent <= 10) {
            return [{
                ...base,
                severity: 'info' as const,
                kind: 'low_markup' as const,
                message: `El recargo sobre costo es pequeño (${markupLabel(markupPercent)}).`,
            }];
        }

        if (markupPercent > 200) {
            return [{
                ...base,
                severity: 'info' as const,
                kind: 'high_markup' as const,
                message: `El precio tiene un recargo sobre costo excepcionalmente alto (${markupLabel(markupPercent)}).`,
            }];
        }

        return [];
    });
}
