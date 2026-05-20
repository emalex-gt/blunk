<?php

namespace App\Services\Fel\Providers\Digifact;

class DigifactNit
{
    public static function cleanIssuerNitForPayload(?string $nit): string
    {
        return self::clean($nit);
    }

    public static function padIssuerNitForApi(?string $nit): string
    {
        return str_pad(self::cleanIssuerNitForPayload($nit), 12, '0', STR_PAD_LEFT);
    }

    public static function normalizeIssuerTaxId(?string $nit): string
    {
        return self::padIssuerNitForApi($nit);
    }

    public static function cleanReceiverNit(?string $nit): string
    {
        $value = self::clean($nit);

        return $value === 'CF' || $value === '' ? 'CF' : $value;
    }

    public static function clean(?string $nit): string
    {
        return strtoupper(preg_replace('/[\s-]+/', '', trim((string) $nit)));
    }
}
