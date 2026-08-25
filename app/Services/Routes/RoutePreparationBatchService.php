<?php

namespace App\Services\Routes;

use App\Models\PreSale;
use App\Models\OperationIdempotencyKey;
use App\Models\RoutePreparationBatch;
use App\Models\RoutePreparationBatchPreSale;
use App\Models\RouteWorkDay;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\IdempotencyResult;
use App\Support\IdempotencyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoutePreparationBatchService
{
    public function __construct(private readonly RoutePreSalePreparationService $preparation)
    {
    }

    public function prepareAll(RouteWorkDay $workDay, User $user, string $idempotencyKey): IdempotencyResult
    {
        $businessId = (int) $workDay->business_id;
        $branchId = (int) $workDay->branch_id;
        $settings = TenantSetting::query()->where('business_id', $businessId)->first();
        $timing = $this->preparation->normalizeTiming($settings?->route_pre_sale_stock_deduction_timing);
        $invoicingMode = $this->normalizeInvoicingMode($settings?->route_pre_sale_invoicing_mode);
        $snapshot = $this->preSaleSnapshot($workDay, $user, $idempotencyKey);

        return app(IdempotencyService::class)->run(
            $businessId,
            $branchId,
            $user->id,
            'route_prepare_all',
            $idempotencyKey,
            [
                'business_id' => $businessId,
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'route_work_day_id' => $workDay->id,
                'stock_deduction_timing' => $timing,
                'invoicing_mode' => $invoicingMode,
                'pre_sales' => $snapshot,
            ],
            function () use ($workDay, $user, $businessId, $branchId, $timing, $invoicingMode) {
                return DB::transaction(function () use ($workDay, $user, $businessId, $branchId, $timing, $invoicingMode) {
                    $lockedWorkDay = RouteWorkDay::query()
                        ->where('business_id', $businessId)
                        ->where('branch_id', $branchId)
                        ->whereKey($workDay->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $activeBranch = BranchInventory::activeBranch($businessId);
                    if ((int) $activeBranch->id !== $branchId) {
                        abort(403);
                    }

                    $preSales = PreSale::query()
                        ->where('business_id', $businessId)
                        ->where('branch_id', $branchId)
                        ->where('route_work_day_id', $lockedWorkDay->id)
                        ->whereIn('status', [PreSale::STATUS_SUBMITTED, PreSale::STATUS_PROCESSING])
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    if ($preSales->isEmpty()) {
                        throw ValidationException::withMessages([
                            'pre_sales' => 'No hay preventas disponibles para preparar en esta jornada.',
                        ]);
                    }

                    $preparedRows = [];

                    // Validate every target before creating the batch or changing a single pre-sale.
                    foreach ($preSales as $preSale) {
                        $rows = $this->preparation->fullPreparationRows($preSale);
                        $this->preparation->validate($preSale, $rows, $timing);
                        $preparedRows[$preSale->id] = $rows;
                    }

                    $batch = RoutePreparationBatch::query()->create([
                        'business_id' => $businessId,
                        'branch_id' => $branchId,
                        'route_work_day_id' => $lockedWorkDay->id,
                        'route_zone_id' => $lockedWorkDay->route_zone_id,
                        'prepared_by' => $user->id,
                        'status' => RoutePreparationBatch::STATUS_PROCESSING,
                        'stock_deduction_timing' => $timing,
                        'invoicing_mode' => $invoicingMode,
                    ]);

                    $totalItems = 0;
                    $totalAmount = 0.0;

                    foreach ($preSales as $preSale) {
                        $result = $this->preparation->prepare($preSale, $preparedRows[$preSale->id], $user, $timing, $batch);

                        RoutePreparationBatchPreSale::query()->create([
                            'route_preparation_batch_id' => $batch->id,
                            'pre_sale_id' => $preSale->id,
                            'status' => 'prepared',
                            'total_items' => $result['total_items'],
                            'total_amount' => $result['total_amount'],
                        ]);

                        $totalItems += $result['total_items'];
                        $totalAmount += $result['total_amount'];
                    }

                    $batch->update([
                        'status' => RoutePreparationBatch::STATUS_COMPLETED,
                        'prepared_at' => now(),
                        'total_pre_sales' => $preSales->count(),
                        'total_items' => $totalItems,
                        'total_amount' => round($totalAmount, 2),
                    ]);

                    return [
                        'result_id' => $batch->id,
                        'response_payload' => [
                            'batch_id' => $batch->id,
                            'route_work_day_id' => $lockedWorkDay->id,
                            'total_pre_sales' => $preSales->count(),
                        ],
                    ];
                });
            },
            'route_preparation_batch',
        );
    }

    /** @return array<int, array{id: int}> */
    private function preSaleSnapshot(RouteWorkDay $workDay, User $user, string $idempotencyKey): array
    {
        $existingResultId = OperationIdempotencyKey::query()
            ->where('business_id', $workDay->business_id)
            ->where('branch_id', $workDay->branch_id)
            ->where('user_id', $user->id)
            ->where('operation_type', 'route_prepare_all')
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', IdempotencyService::STATUS_COMPLETED)
            ->value('result_id');

        if ($existingResultId) {
            return RoutePreparationBatchPreSale::query()
                ->where('route_preparation_batch_id', $existingResultId)
                ->orderBy('pre_sale_id')
                ->get(['pre_sale_id'])
                ->map(fn (RoutePreparationBatchPreSale $entry) => ['id' => $entry->pre_sale_id])
                ->all();
        }

        return PreSale::query()
            ->where('business_id', $workDay->business_id)
            ->where('branch_id', $workDay->branch_id)
            ->where('route_work_day_id', $workDay->id)
            ->whereIn('status', [PreSale::STATUS_SUBMITTED, PreSale::STATUS_PROCESSING])
            ->orderBy('id')
            ->get(['id'])
            ->map(fn (PreSale $preSale) => ['id' => $preSale->id])
            ->all();
    }

    private function normalizeInvoicingMode(?string $mode): string
    {
        return in_array($mode, ['automatic', 'automatic_all'], true) ? 'automatic_all' : 'manual';
    }
}
