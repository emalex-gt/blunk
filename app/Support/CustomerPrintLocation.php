<?php

namespace App\Support;

final class CustomerPrintLocation
{
    /** @param array{address: string, municipality: string, department: string} $location */
    public static function format(array $location): string
    {
        $address = trim((string) $location['address']);
        $municipality = trim((string) $location['municipality']);
        $department = trim((string) $location['department']);
        $administrativeLocation = array_values(array_filter([$municipality, $department]));

        if ($address === '' || $administrativeLocation === []) {
            return implode(', ', array_filter([$address, ...$administrativeLocation]));
        }

        $normalizedAddress = mb_strtolower($address);
        $completeLocation = implode(', ', $administrativeLocation);

        if (str_ends_with($normalizedAddress, mb_strtolower($completeLocation))) {
            return $address;
        }

        if ($municipality !== '' && str_ends_with($normalizedAddress, mb_strtolower($municipality))) {
            return $department !== '' && mb_strtolower($municipality) !== mb_strtolower($department)
                ? implode(', ', [$address, $department])
                : $address;
        }

        if ($department !== '' && str_ends_with($normalizedAddress, mb_strtolower($department))) {
            return $address;
        }

        return implode(', ', [$address, ...$administrativeLocation]);
    }
}
