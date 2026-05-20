<?php

namespace App\Services\Fel\Providers\Digifact;

use App\Models\ElectronicDocument;
use App\Models\Sale;
use App\Models\TenantFelSetting;

class DigifactPayloadBuilder
{
    public function buildInvoicePayload(Sale $sale, TenantFelSetting $settings): array
    {
        $sale->loadMissing(['business', 'customer', 'items.product', 'payments']);
        $business = $sale->business;
        $customer = $sale->customer;

        return [
            'metadata' => [
                'provider' => 'digifact',
                'environment' => $settings->environment,
                'sale_id' => $sale->id,
                'document_type' => 'FACT',
            ],
            'issuer' => [
                'business_id' => $business?->id,
                'name' => $settings->establishment_name ?: $business?->name,
                'establishment_code' => $settings->establishment_code,
                'establishment_name' => $settings->establishment_name,
                'affiliate_type' => $settings->affiliate_type,
                'phrase_type' => $settings->phrase_type,
                'phrase_scenario' => $settings->phrase_scenario,
            ],
            'receiver' => [
                'name' => $customer?->name ?: 'Consumidor Final',
                'doc_type' => $customer?->doc_type ?: 'CF',
                'doc_number' => $customer?->doc_number ?: 'CF',
                'address' => $customer?->address,
                'phone' => $customer?->phone,
                'country' => $customer?->country ?: 'GT',
                'is_final_consumer' => (bool) ($customer?->is_final_consumer ?? false),
            ],
            'items' => $sale->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'description' => $item->product_name,
                'code' => $item->product?->barcode ?: $item->product?->code,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total' => (float) $item->total,
                // TODO: Replace with exact SAT IVA structure required by Digifact.
                'tax_amount' => 0,
            ])->values()->all(),
            'payments' => $sale->payments->map(fn ($payment) => [
                'method' => $payment->method,
                'amount' => (float) $payment->amount,
                'reference' => $payment->reference,
            ])->values()->all(),
            'totals' => [
                'subtotal' => (float) $sale->total,
                // TODO: Use sale tax fields if a tax module is added later.
                'tax_total' => 0,
                'grand_total' => (float) $sale->total,
            ],
            'issued_at' => $sale->created_at?->toIso8601String(),
        ];
    }

    public function buildCancellationPayload(ElectronicDocument $document, string $reason): array
    {
        $document->loadMissing(['sale.customer', 'business']);

        return [
            'metadata' => [
                'provider' => $document->provider,
                'environment' => $document->environment,
                'sale_id' => $document->sale_id,
                'electronic_document_id' => $document->id,
            ],
            'document' => [
                'uuid' => $document->uuid,
                'series' => $document->series,
                'number' => $document->number,
                'certification_date' => $document->certification_date?->toIso8601String(),
            ],
            'receiver' => [
                'name' => $document->sale?->customer?->name,
                'doc_number' => $document->sale?->customer?->doc_number ?: 'CF',
            ],
            'reason' => $reason,
            'cancelled_at' => now()->toIso8601String(),
        ];
    }
}
