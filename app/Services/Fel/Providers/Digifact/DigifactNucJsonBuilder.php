<?php

namespace App\Services\Fel\Providers\Digifact;

use App\Models\ElectronicDocument;
use App\Models\Sale;
use App\Models\TenantFelSetting;
use App\Models\TenantSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class DigifactNucJsonBuilder
{
    public function buildInvoicePayload(Sale $sale, TenantFelSetting $settings): array
    {
        $sale->loadMissing(['business.tenantSetting', 'customer', 'items.product']);
        $settings->loadMissing('phrases');
        $companySettings = $sale->business?->tenantSetting
            ?: TenantSetting::query()->where('business_id', $sale->business_id)->first();
        $customer = $sale->customer;
        $buyerTaxId = DigifactNit::cleanReceiverNit($sale->customer_doc_number ?: $customer?->doc_number ?: 'CF');
        $buyerName = $buyerTaxId === 'CF'
            ? ($sale->customer_name ?: $customer?->name ?: 'Consumidor Final')
            : (string) ($sale->customer_name ?: $customer?->name);
        $buyerAddress = trim((string) (
            $sale->customer_address
            ?: $customer?->address
            ?: $settings->establishment_address
            ?: ''
        )) ?: 'Ciudad';
        $buyerPostalCode = trim((string) (
            $sale->customer_postal_code
            ?: $customer?->postal_code
            ?: $settings->establishment_postal_code
            ?: ''
        )) ?: '01001';
        $buyerMunicipality = trim((string) (
            $sale->customer_municipality
            ?: $customer?->municipality
            ?: $settings->establishment_municipality
            ?: ''
        )) ?: 'Guatemala';
        $buyerDepartment = trim((string) (
            $sale->customer_department
            ?: $customer?->department
            ?: $settings->establishment_department
            ?: ''
        )) ?: 'Guatemala';
        $buyerCountry = trim((string) (
            $sale->customer_country
            ?: $customer?->country
            ?: $settings->establishment_country
            ?: ''
        )) ?: 'GT';

        $buyer = [
            'TaxID' => $buyerTaxId,
            'Name' => $buyerName ?: 'Consumidor Final',
            'AddressInfo' => [
                'Address' => $buyerAddress,
                'City' => $buyerPostalCode,
                'District' => $buyerMunicipality,
                'State' => $buyerDepartment,
                'Country' => $buyerCountry,
            ],
        ];

        $branchDefaults = [];
        $branchCode = (string) ($settings->establishment_code ?: '1');
        $branchName = $settings->establishment_name ?: 'Casa Matriz';
        $branchAddress = $settings->establishment_address
            ?: $companySettings?->company_address
            ?: 'Ciudad';
        $branchPostalCode = $settings->establishment_postal_code ?: '01001';
        $branchMunicipality = $settings->establishment_municipality ?: 'Guatemala';
        $branchDepartment = $settings->establishment_department ?: 'Guatemala';
        $branchCountry = $settings->establishment_country ?: 'GT';

        if (! $settings->establishment_code) {
            $branchDefaults[] = 'establishment_code';
        }

        if (! $settings->establishment_name) {
            $branchDefaults[] = 'establishment_name';
        }

        if (! $settings->establishment_address && ! $companySettings?->company_address) {
            $branchDefaults[] = 'establishment_address';
        }

        if (! $settings->establishment_postal_code) {
            $branchDefaults[] = 'establishment_postal_code';
        }

        if (! $settings->establishment_municipality) {
            $branchDefaults[] = 'establishment_municipality';
        }

        if (! $settings->establishment_department) {
            $branchDefaults[] = 'establishment_department';
        }

        if (! $settings->establishment_country) {
            $branchDefaults[] = 'establishment_country';
        }

        if ($branchDefaults !== []) {
            Log::warning('Digifact Seller.BranchInfo defaults used', [
                'business_id' => $sale->business_id,
                'defaults' => $branchDefaults,
            ]);
        }

        $items = [];
        $grandTotal = 0.0;
        $grandTax = 0.0;

        foreach ($sale->items as $index => $item) {
            $quantity = (float) $item->quantity;
            $unitPrice = round((float) $item->unit_price, 2);
            $lineSubtotal = round($quantity * $unitPrice, 2);
            $lineDiscount = round((float) ($item->discount_amount ?? 0), 2);
            $lineTotal = round($lineSubtotal - $lineDiscount, 2);
            $taxable = round($lineTotal / 1.12, 4);
            $tax = round($lineTotal - $taxable, 4);
            $grandTotal += $lineTotal;
            $grandTax += $tax;

            Log::info('DIGIFACT FEL item payload values', [
                'business_id' => $sale->business_id,
                'sale_id' => $sale->id,
                'product' => $item->product_name,
                'qty' => $quantity,
                'unit_price' => $unitPrice,
                'line_subtotal' => $lineSubtotal,
                'line_discount' => $lineDiscount,
                'line_total' => $lineTotal,
                'payload_price' => $unitPrice,
                'payload_total_item' => $lineTotal,
            ]);

            $items[] = [
                'NumberLine' => $index + 1,
                'Type' => 'B',
                'Description' => $item->product_name,
                'Qty' => $quantity,
                'UnitOfMeasure' => 'UNI',
                'Price' => $unitPrice,
                'Discounts' => [
                    'Discount' => [
                        [
                            'Amount' => $lineDiscount,
                        ],
                    ],
                ],
                'Taxes' => [
                    'Tax' => [
                        [
                            'Code' => '1',
                            'Description' => 'IVA',
                            'TaxableAmount' => $taxable,
                            'Amount' => $tax,
                        ],
                    ],
                ],
                'Totals' => [
                    'TotalItem' => $lineTotal,
                ],
            ];
        }

        // TODO: Adjust for special GT regimes and non-IVA items when tax settings exist.
        $payload = [
            'Version' => '1.00',
            'CountryCode' => 'GT',
            'Header' => [
                'DocType' => 'FACT',
                'IssuedDateTime' => $sale->created_at?->copy()->timezone('America/Guatemala')->format('Y-m-d\TH:i:sP')
                    ?? now('America/Guatemala')->format('Y-m-d\TH:i:sP'),
                'Currency' => 'GTQ',
            ],
            'Seller' => [
                'TaxID' => DigifactNit::cleanIssuerNitForPayload($settings->issuer_tax_id),
                'TaxIDAdditionalInfo' => [
                    [
                        'Name' => 'AfiliacionIVA',
                        'Data' => null,
                        'Value' => $settings->affiliate_type ?: 'GEN',
                    ],
                ],
                'AdditionlInfo' => $this->buildSellerAdditionlInfo($settings),
                'Name' => $settings->establishment_name
                    ?: $companySettings?->company_name
                    ?: $sale->business?->name
                    ?: 'Empresa',
                'BranchInfo' => [
                    'Code' => $branchCode,
                    'Name' => $branchName,
                    'AddressInfo' => [
                        'Address' => $branchAddress,
                        'City' => $branchPostalCode,
                        'District' => $branchMunicipality,
                        'State' => $branchDepartment,
                        'Country' => $branchCountry,
                    ],
                ],
            ],
            'Buyer' => $buyer,
            'Items' => $items,
            'Totals' => [
                'TotalTaxes' => [
                    'TotalTax' => [
                        [
                            'Description' => 'IVA',
                            'Amount' => round($grandTax, 2),
                        ],
                    ],
                ],
                'GrandTotal' => [
                    'InvoiceTotal' => round($grandTotal, 2),
                ],
            ],
            'AdditionalDocumentInfo' => [
                'AdditionalInfo' => [],
            ],
        ];

        return $payload;
    }

    private function buildSellerAdditionlInfo(TenantFelSetting $settings): array
    {
        $phrases = $settings->phrases->isNotEmpty()
            ? $settings->phrases
            : collect([(object) [
                'data_identifier' => '1',
                'phrase_type' => '1',
                'scenario_code' => '2',
                'resolution_number' => null,
                'resolution_date' => null,
                'type_data' => null,
                'type_value' => null,
                'scenario_data' => null,
                'scenario_value' => null,
            ]]);

        $items = $phrases->flatMap(function ($phrase): array {
            $data = (string) (
                $phrase->data_identifier
                ?: $phrase->type_data
                ?: $phrase->scenario_data
                ?: '1'
            );
            $phraseType = (string) ($phrase->phrase_type ?: $phrase->type_value ?: '1');
            $scenarioCode = (string) ($phrase->scenario_code ?: $phrase->scenario_value ?: '2');

            $items = [
                [
                    'Name' => 'TipoFrase',
                    'Data' => $data,
                    'Value' => $phraseType,
                ],
                [
                    'Name' => 'Escenario',
                    'Data' => $data,
                    'Value' => $scenarioCode,
                ],
            ];

            if (filled($phrase->resolution_number ?? null)) {
                $items[] = [
                    'Name' => 'NumeroResolucion',
                    'Data' => $data,
                    'Value' => (string) $phrase->resolution_number,
                ];
            }

            if (filled($phrase->resolution_date ?? null)) {
                $items[] = [
                    'Name' => 'FechaResolucion',
                    'Data' => $data,
                    'Value' => $phrase->resolution_date instanceof \DateTimeInterface
                        ? $phrase->resolution_date->format('Y-m-d')
                        : Carbon::parse($phrase->resolution_date)->format('Y-m-d'),
                ];
            }

            return $items;
        })->values()->all();

        $hasRequiredFactPhrase = collect($items)
            ->where('Name', 'TipoFrase')
            ->where('Value', '1')
            ->contains(fn (array $typeInfo) => collect($items)
                ->where('Name', 'Escenario')
                ->where('Data', $typeInfo['Data'])
                ->where('Value', '2')
                ->isNotEmpty());

        if (! $hasRequiredFactPhrase) {
            array_unshift(
                $items,
                [
                    'Name' => 'Escenario',
                    'Data' => '1',
                    'Value' => '2',
                ],
            );
            array_unshift(
                $items,
                [
                    'Name' => 'TipoFrase',
                    'Data' => '1',
                    'Value' => '1',
                ],
            );
        }

        return $items;
    }

    public function buildCancellationPayload(ElectronicDocument $document, string $reason): array
    {
        $document->loadMissing(['sale.customer', 'business.tenantFelSetting']);
        $settings = $document->business?->tenantFelSetting;
        $customer = $document->sale?->customer;

        return [
            'Version' => '1.00',
            'CountryCode' => 'GT',
            'NumeroDocumentoAAnular' => $document->uuid,
            'NITEmisorDocumentoAnular' => DigifactNit::normalizeIssuerTaxId($settings?->issuer_tax_id),
            'IDReceptorDocumentoAnular' => DigifactNit::cleanReceiverNit($customer?->doc_number),
            'NITCertificadorDocumentoAnular' => DigifactNit::cleanReceiverNit($settings?->certifier_tax_id),
            'FechaEmisionDocumentoAnular' => ($document->certification_date ?: $document->sale?->created_at)?->copy()->timezone('America/Guatemala')->format('Y-m-d\TH:i:sP'),
            'FechaAnulacion' => Carbon::now('America/Guatemala')->format('Y-m-d\TH:i:sP'),
            'MotivoAnulacion' => $reason,
        ];
    }
}
