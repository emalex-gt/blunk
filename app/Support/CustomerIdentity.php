<?php

namespace App\Support;

final class CustomerIdentity
{
    public const NIT = 'NIT';
    public const CUI = 'CUI';
    public const CF = 'CF';

    public static function normalizeGuatemalaDocType(mixed $value): ?string
    {
        $normalized = mb_strtoupper(trim((string) $value));
        $normalized = in_array($normalized, ['C/F', 'CONSUMIDOR FINAL'], true) ? self::CF : $normalized;

        return in_array($normalized, [self::NIT, self::CUI, self::CF], true) ? $normalized : null;
    }

    public static function normalizeDocument(string $docType, mixed $value): ?string
    {
        return match (self::normalizeGuatemalaDocType($docType)) {
            self::CUI => self::normalizeCui($value),
            self::CF => self::CF,
            self::NIT => self::normalizeTaxId($value),
            default => null,
        };
    }

    public static function normalizeCui(mixed $value): string
    {
        $normalized = trim((string) $value);

        return preg_replace('/[\s\-\/]+/u', '', $normalized) ?? '';
    }

    public static function isValidCui(mixed $value): bool
    {
        return preg_match('/^\d{13}$/', self::normalizeCui($value)) === 1;
    }

    public static function normalizeTaxId(mixed $value): ?string
    {
        $normalized = mb_strtoupper(trim((string) $value));
        $normalized = preg_replace('/[\s\-\/]+/u', '', $normalized) ?? '';

        if ($normalized === '') {
            return null;
        }

        return in_array($normalized, ['CF', 'CONSUMIDORFINAL'], true) ? 'CF' : $normalized;
    }

    public static function normalizeName(mixed $value): string
    {
        $name = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';

        return mb_strtoupper($name);
    }

    public static function isRealTaxId(mixed $taxId): bool
    {
        $normalized = self::normalizeTaxId($taxId);

        return $normalized !== null && $normalized !== 'CF';
    }

    public static function isGenericFinalConsumer(mixed $taxId, mixed $name, array $payload = []): bool
    {
        if (self::normalizeTaxId($taxId) !== 'CF'
            || self::normalizeName($name) !== 'CONSUMIDOR FINAL') {
            return false;
        }

        foreach (['commercial_name', 'contact_name', 'address', 'phone', 'postal_code', 'department', 'municipality'] as $field) {
            if (filled($payload[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
