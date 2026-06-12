<?php

namespace App\Services\Fel\Providers\Digifact;

use App\Models\Business;
use App\Services\Fel\FelException;
use Illuminate\Support\Carbon;

class DigifactReconciliationService
{
    public function __construct(
        private readonly DigifactInvoiceService $invoiceService,
    ) {
    }

    public function findByInternalReference(Business $business, string $internalReference, Carbon|string $issuedDate): array
    {
        try {
            $settings = $business->tenantFelSetting;
            $raw = DigifactClient::forBusiness($business, $settings)
                ->findDocumentByInternalReference($settings, $internalReference, $issuedDate);
        } catch (FelException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new FelException('No se pudo consultar la referencia interna en Digifact.', previous: $exception);
        }

        if ($raw === []) {
            return ['found' => false, 'raw' => []];
        }

        $ids = $this->invoiceService->extractCertificationIdentifiers($raw);
        $flat = $this->flatten($raw);

        return [
            'found' => filled($ids['uuid']),
            'authNumber' => $ids['uuid'],
            'serial' => $ids['number'],
            'batch' => $ids['series'],
            'issuedTimeStamp' => $ids['issued_at']?->toISOString(),
            'totalAmount' => $this->first($flat, ['totalAmount', 'TotalAmount', 'GrandTotal', 'TOTAL', 'RESPONSE.TotalAmount']),
            'receiverTaxID' => $this->first($flat, ['receiverTaxID', 'ReceiverTaxID', 'NITReceptor', 'RESPONSE.receiverTaxID']),
            'receiverName' => $this->first($flat, ['receiverName', 'ReceiverName', 'NombreReceptor', 'RESPONSE.receiverName']),
            'raw' => $raw,
        ];
    }

    private function flatten(array $payload, string $prefix = ''): array
    {
        $flat = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);
                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }

    private function first(array $flat, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $flat) && filled($flat[$key])) {
                return $flat[$key];
            }
        }

        return null;
    }
}
