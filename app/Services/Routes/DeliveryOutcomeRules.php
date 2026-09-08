<?php

namespace App\Services\Routes;

use Illuminate\Validation\ValidationException;

class DeliveryOutcomeRules
{
    public const DELIVERED = 'delivered';
    public const NOT_DELIVERED = 'not_delivered';

    public const REASONS = [
        'customer_absent',
        'customer_rejected',
        'address_issue',
        'business_closed',
        'damaged_goods',
        'other',
    ];

    public static function normalize(string $status, ?string $reasonCode, ?string $notes): array
    {
        $notes = blank($notes) ? null : trim($notes);

        if (! in_array($status, [self::DELIVERED, self::NOT_DELIVERED], true)) {
            throw ValidationException::withMessages(['delivery_status' => 'El estado de entrega no es válido.']);
        }

        if ($status === self::DELIVERED) {
            return [
                'delivery_status' => self::DELIVERED,
                'not_delivered_reason_code' => null,
                'delivery_notes' => $notes,
            ];
        }

        if (! in_array($reasonCode, self::REASONS, true)) {
            throw ValidationException::withMessages(['not_delivered_reason' => 'Debe indicar el motivo de la no entrega.']);
        }
        if ($reasonCode === 'other' && $notes === null) {
            throw ValidationException::withMessages(['notes' => 'Debe explicar el motivo seleccionado como otro.']);
        }

        return [
            'delivery_status' => self::NOT_DELIVERED,
            'not_delivered_reason_code' => $reasonCode,
            'delivery_notes' => $notes,
        ];
    }
}
