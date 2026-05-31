<?php

namespace App\Services\Fel\Providers\Digifact;

use App\Models\Branch;
use App\Models\ElectronicDocument;
use App\Models\Sale;
use App\Models\TenantFelSetting;
use App\Models\TenantSetting;
use App\Services\Fel\FelException;
use App\Support\BranchInventory;
use App\Support\FelPhraseRenderer;
use Illuminate\Support\Carbon;

class DigifactNucJsonBuilder
{
    public function buildInvoicePayload(Sale $sale, TenantFelSetting $settings): array
    {
        $sale->loadMissing(['business.tenantSetting', 'branch', 'customer', 'items.product']);
        $settings->loadMissing('phrases');
        FelPhraseRenderer::validate($settings->phrases);
        $internalReference = $sale->fel_internal_reference ?: $this->internalReference($sale);
        $companySettings = $sale->business?->tenantSetting
            ?: TenantSetting::query()->where('business_id', $sale->business_id)->first();
        $felBranch = $this->felBranch($sale);
        $this->validateFelBranch($felBranch);
        $customer = $sale->customer;
        $buyerTaxId = DigifactNit::cleanReceiverNit($sale->customer_doc_number ?: $customer?->doc_number ?: 'CF');
        $buyerName = $buyerTaxId === 'CF'
            ? ($sale->customer_name ?: $customer?->name ?: 'Consumidor Final')
            : (string) ($sale->customer_name ?: $customer?->name);
        $buyerAddress = trim((string) (
            $sale->customer_address
            ?: $customer?->address
            ?: $felBranch->fel_address
            ?: $settings->establishment_address
            ?: ''
        )) ?: 'Ciudad';
        $buyerPostalCode = trim((string) (
            $sale->customer_postal_code
            ?: $customer?->postal_code
            ?: $felBranch->fel_postal_code
            ?: $settings->establishment_postal_code
            ?: ''
        )) ?: '01001';
        $buyerMunicipality = trim((string) (
            $sale->customer_municipality
            ?: $customer?->municipality
            ?: $felBranch->fel_municipality
            ?: $settings->establishment_municipality
            ?: ''
        )) ?: 'Guatemala';
        $buyerDepartment = trim((string) (
            $sale->customer_department
            ?: $customer?->department
            ?: $felBranch->fel_department
            ?: $settings->establishment_department
            ?: ''
        )) ?: 'Guatemala';
        $buyerCountry = trim((string) (
            $sale->customer_country
            ?: $customer?->country
            ?: $felBranch->fel_country
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

            if (config('app.debug') && app()->environment('local')) {
                Log::debug('DIGIFACT FEL item payload values', [
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
            }

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
                'Name' => $companySettings?->company_name
                    ?: $sale->business?->name
                    ?: 'Empresa',
                'BranchInfo' => [
                    'Code' => (string) $felBranch->fel_establishment_code,
                    'Name' => $felBranch->fel_establishment_name ?: $felBranch->name,
                    'AddressInfo' => [
                        'Address' => $felBranch->fel_address,
                        'City' => $felBranch->fel_postal_code,
                        'District' => $felBranch->fel_municipality,
                        'State' => $felBranch->fel_department,
                        'Country' => $felBranch->fel_country ?: 'GT',
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
                'AdditionalInfo' => [
                    [
                        'Name' => 'NoReferencia',
                        'Data' => null,
                        'Value' => $internalReference,
                    ],
                ],
            ],
        ];

        return $payload;
    }

    public function internalReference(Sale $sale): string
    {
        return 'BLUNK-'.$sale->business_id.'-'.$sale->id;
    }

    public function felMetadataFromPayload(array $payload, TenantFelSetting $settings): array
    {
        $branchInfo = $payload['Seller']['BranchInfo'] ?? [];
        $addressInfo = $branchInfo['AddressInfo'] ?? [];

        return [
            'fel_establishment' => [
                'code' => $branchInfo['Code'] ?? null,
                'name' => $branchInfo['Name'] ?? null,
                'address' => $addressInfo['Address'] ?? null,
                'postal_code' => $addressInfo['City'] ?? null,
                'municipality' => $addressInfo['District'] ?? null,
                'department' => $addressInfo['State'] ?? null,
                'country' => $addressInfo['Country'] ?? null,
            ],
            'fel_visible_phrases' => FelPhraseRenderer::visiblePhrases($settings->phrases),
        ];
    }

    private function felBranch(Sale $sale): Branch
    {
        $business = $sale->business;

        if ($sale->branch && (int) $sale->branch->business_id === (int) $sale->business_id) {
            if ($business && ! BranchInventory::branchesEnabled($sale->business_id)) {
                return BranchInventory::defaultBranchForBusiness($business);
            }

            return $sale->branch;
        }

        if (! $business) {
            throw new FelException('No se pudo resolver la empresa para certificar FEL.');
        }

        return BranchInventory::felBranchForBusiness($business, auth()->user());
    }

    private function validateFelBranch(Branch $branch): void
    {
        foreach ([
            'fel_establishment_code',
            'fel_establishment_name',
            'fel_address',
            'fel_postal_code',
            'fel_municipality',
            'fel_department',
            'fel_country',
        ] as $field) {
            if (! filled($branch->{$field})) {
                throw new FelException('Faltan datos del establecimiento FEL. Configura código, nombre y dirección del establecimiento.');
            }
        }
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
