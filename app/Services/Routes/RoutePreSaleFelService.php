<?php

namespace App\Services\Routes;

use App\Models\Business;
use App\Models\PreSale;
use App\Models\Sale;
use App\Models\TenantFelSetting;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Fiscal\FiscalDocumentIntentService;
use App\Support\IdempotencyResult;
use App\Support\IdempotencyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoutePreSaleFelService
{
    public function __construct(
        private readonly RoutePreSaleFelEligibilityService $eligibility,
    )
    {
    }

    public function certify(PreSale $preSale, User $user, string $idempotencyKey): IdempotencyResult
    {
        $prepared = app(IdempotencyService::class)->run(
            (int) $preSale->business_id,
            (int) $preSale->branch_id,
            $user->id,
            'route_pre_sale_fel_certification',
            $idempotencyKey,
            [
                'business_id' => (int) $preSale->business_id,
                'branch_id' => (int) $preSale->branch_id,
                'pre_sale_id' => (int) $preSale->id,
                'sale_id' => (int) $preSale->converted_sale_id,
            ],
            function () use ($preSale, $user) {
                return DB::transaction(function () use ($preSale, $user) {
                    $lockedPreSale = PreSale::query()
                        ->where('business_id', $preSale->business_id)
                        ->whereKey($preSale->id)
                        ->with('customer')
                        ->lockForUpdate()
                        ->firstOrFail();


                    $sale = Sale::query()
                        ->where('business_id', $lockedPreSale->business_id)
                        ->whereKey($lockedPreSale->converted_sale_id)
                        ->with(['customer', 'electronicDocument'])
                        ->lockForUpdate()
                        ->first();

                    if (! $sale || $lockedPreSale->status !== PreSale::STATUS_CONVERTED || $sale->status !== 'completed') {
                        throw ValidationException::withMessages([
                            'pre_sale' => 'La preventa no tiene un comprobante interno disponible para certificar.',
                        ]);
                    }

                    $this->assertFelAvailable($sale);
                    $this->eligibility->assertEligible($lockedPreSale);

                    $status = $sale->electronicDocument?->status ?? $sale->certification_status;

                    if ($status === 'certified') {
                        throw ValidationException::withMessages([
                            'fel' => 'El comprobante ya está certificado FEL.',
                        ]);
                    }

                    if ($status === 'unknown') {
                        throw ValidationException::withMessages([
                            'fel' => 'La certificación FEL requiere conciliación antes de reintentar.',
                        ]);
                    }

                    if ($status === 'pending') {
                        throw ValidationException::withMessages([
                            'fel' => 'La certificación FEL ya está en proceso.',
                        ]);
                    }

                    return [
                        'result_id' => $sale->id,
                        'response_payload' => [
                            'pre_sale_id' => $lockedPreSale->id,
                            'sale_id' => $sale->id,
                        ],
                    ];
                });
            },
            'electronic_document',
        );

        $sale = Sale::query()
            ->where('business_id', $preSale->business_id)
            ->with(['business', 'customer', 'items.product', 'payments', 'electronicDocument'])
            ->findOrFail($prepared->resultId);

        $intent = app(FiscalDocumentIntentService::class)->createForExistingSale($sale, $idempotencyKey, $user->id);
        if ($intent->status === \App\Models\FiscalDocumentIntent::PENDING) {
            $intent = app(FiscalDocumentIntentService::class)->certify($intent);
        } elseif ($intent->status === \App\Models\FiscalDocumentIntent::CERTIFIED) {
            $intent = app(FiscalDocumentIntentService::class)->finalize($intent);
        }

        if ($intent->status === \App\Models\FiscalDocumentIntent::UNKNOWN) {
            throw ValidationException::withMessages([
                'fel' => 'La certificación FEL quedó en verificación y será conciliada por Super Admin.',
            ]);
        }
        if ($intent->status === \App\Models\FiscalDocumentIntent::REJECTED) {
            throw ValidationException::withMessages([
                'fel' => 'Digifact rechazó explícitamente la certificación FEL.',
            ]);
        }

        return $prepared;
    }

    private function assertFelAvailable(Sale $sale): void
    {
        $business = Business::query()->findOrFail($sale->business_id);
        $settings = TenantSetting::query()->where('business_id', $sale->business_id)->first();
        $felSettings = TenantFelSetting::query()->where('business_id', $sale->business_id)->first();

        if ($business->country !== 'GT'
            || ! (bool) ($settings?->allow_invoices ?? false)
            || ! module_enabled('fel_gt', $sale->business_id)
            || ! (bool) $felSettings?->enabled
            || ! (bool) $felSettings?->isConfigured()) {
            throw ValidationException::withMessages([
                'fel' => 'La facturación electrónica FEL no está habilitada para este negocio.',
            ]);
        }
    }

}
