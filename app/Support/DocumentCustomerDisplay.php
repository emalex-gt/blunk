<?php

namespace App\Support;

use App\Models\Customer;

class DocumentCustomerDisplay
{
    public static function nameOnly(?Customer $customer, ?string $snapshotName = null, ?string $snapshotDocNumber = null): string
    {
        if (self::isFinalConsumer($customer, $snapshotName, $snapshotDocNumber)) {
            return 'Consumidor final';
        }

        $name = trim((string) ($customer?->name ?? $snapshotName ?? ''));

        return $name !== '' && strtoupper($name) !== 'CF'
            ? $name
            : 'Consumidor final';
    }

    private static function isFinalConsumer(?Customer $customer, ?string $snapshotName, ?string $snapshotDocNumber): bool
    {
        $values = [
            $customer?->doc_type,
            $customer?->doc_number,
            $snapshotDocNumber,
            $snapshotName,
        ];

        foreach ($values as $value) {
            if (strtoupper(trim((string) $value)) === 'CF') {
                return true;
            }
        }

        return (bool) ($customer?->is_final_consumer);
    }
}
