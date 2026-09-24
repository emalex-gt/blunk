<?php

namespace App\Services\Routes;

use App\Models\PreSale;
use App\Models\OperationIdempotencyKey;
use App\Models\RouteDeliveryBatch;
use App\Models\RoutePreparationBatch;
use App\Models\RouteWorkDay;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RouteGlobalOperationsService
{
    /** @return array{summary:array<string,int|float>,sellers:array<int,array<string,mixed>>,blocked:array<int,array<string,mixed>>} */
    public function preparationPreview(int $businessId, int $branchId): array
    {
        return $this->preview($businessId, $branchId, [PreSale::STATUS_SUBMITTED, PreSale::STATUS_PROCESSING]);
    }

    /** @return array{summary:array<string,int|float>,sellers:array<int,array<string,mixed>>,blocked:array<int,array<string,mixed>>} */
    public function salesPreview(int $businessId, int $branchId): array
    {
        return $this->preview($businessId, $branchId, [PreSale::STATUS_PICKED], true);
    }

    /** @return array{processed:array<int,array<string,mixed>>,blocked:array<int,array<string,mixed>>,failed:array<int,array<string,mixed>>} */
    public function prepareAll(User $actor, string $globalKey): array
    {
        return $this->execute($actor, $globalKey, [PreSale::STATUS_SUBMITTED, PreSale::STATUS_PROCESSING], 'prepare', fn (RouteWorkDay $workDay, string $key) => app(RoutePreparationBatchService::class)->prepareAll($workDay, $actor, $key));
    }

    /** @return array{processed:array<int,array<string,mixed>>,blocked:array<int,array<string,mixed>>,failed:array<int,array<string,mixed>>} */
    public function generateSales(User $actor, string $globalKey): array
    {
        return $this->execute($actor, $globalKey, [PreSale::STATUS_PICKED], 'generate-sales', fn (RouteWorkDay $workDay, string $key) => app(RouteDeliveryBatchService::class)->deliverAll($workDay, $actor, $key), true);
    }

    private function preview(int $businessId, int $branchId, array $eligibleStatuses, bool $requireUnconverted = false): array
    {
        $workDays = RouteWorkDay::query()
            ->where('business_id', $businessId)
            ->where('branch_id', $branchId)
            ->with(['seller:id,name', 'preSales' => fn ($query) => $query->where('business_id', $businessId)->where('branch_id', $branchId)->orderBy('id')])
            ->orderBy('seller_id')->orderBy('id')->get();
        $sellerGroups = [];
        $blocked = [];
        $seenPreSaleIds = [];
        $policyBlocksByWorkDay = [];

        if ($requireUnconverted) {
            foreach ($workDays as $workDay) {
                if (! $workDay->preSales->contains(fn (PreSale $preSale) => $this->isSalesCandidate($preSale))) {
                    continue;
                }
                $policyBlocksByWorkDay[$workDay->id] = app(RouteDeliveryBatchService::class)->policyPreflightPreview($workDay);
                foreach ($policyBlocksByWorkDay[$workDay->id] as $policyBlock) {
                    $blocked[] = [
                        'work_day_id' => $workDay->id,
                        'seller_id' => $workDay->seller_id,
                        'seller_name' => $workDay->seller?->name,
                        ...$policyBlock,
                    ];
                }
            }
        }

        foreach ($workDays as $workDay) {
            $workDayHasPolicyBlocks = ($policyBlocksByWorkDay[$workDay->id] ?? []) !== [];
            foreach ($workDay->preSales as $preSale) {
                if (! $this->requiresOperationalAction($preSale)) {
                    continue;
                }
                if (isset($seenPreSaleIds[$preSale->id])) {
                    continue;
                }
                $seenPreSaleIds[$preSale->id] = true;

                $sellerGroups[$workDay->seller_id] ??= [
                    'seller' => ['id' => $workDay->seller_id, 'name' => $workDay->seller?->name],
                    'work_day_ids' => [],
                    'operation_work_day_ids' => [],
                    'pre_sales' => [],
                    'total' => 0.0,
                    'total_pre_sales' => 0,
                    'total_amount' => 0.0,
                    'prepared_count' => 0,
                    'converted_count' => 0,
                    'preparation_eligible_count' => 0,
                    'sales_eligible_count' => 0,
                    'preparation_blocked_count' => 0,
                    'sales_blocked_count' => 0,
                ];
                $seller = &$sellerGroups[$workDay->seller_id];
                $seller['total_pre_sales']++;
                $seller['total_amount'] += (float) $preSale->total;
                $seller['work_day_ids'][] = $workDay->id;

                $preparationCandidate = $this->isPreparationCandidate($preSale);
                $salesCandidate = $this->isSalesCandidate($preSale);
                if ($salesCandidate) {
                    $seller['prepared_count']++;
                }
                if ($preparationCandidate) {
                    $seller['preparation_eligible_count']++;
                }
                if ($salesCandidate) {
                    $seller[$workDayHasPolicyBlocks ? 'sales_blocked_count' : 'sales_eligible_count']++;
                }

                $eligible = $this->matchesRequestedOperation($preSale, $eligibleStatuses)
                    && (! $requireUnconverted || ! $workDayHasPolicyBlocks);
                if (! $eligible) {
                    if (! $workDayHasPolicyBlocks && $this->matchesRequestedOperation($preSale, $eligibleStatuses)) {
                        $blocked[] = [
                            'work_day_id' => $workDay->id,
                            'pre_sale_id' => $preSale->id,
                            'seller_id' => $workDay->seller_id,
                            'seller_name' => $workDay->seller?->name,
                            'reason_code' => $preSale->converted_sale_id ? 'pre_sale_already_converted' : 'pre_sale_status_ineligible',
                        ];
                    }
                    unset($seller);
                    continue;
                }
                $seller['operation_work_day_ids'][] = $workDay->id;
                $seller['pre_sales'][] = ['id' => $preSale->id, 'work_day_id' => $workDay->id, 'total' => (float) $preSale->total];
                $seller['total'] += (float) $preSale->total;
                unset($seller);
            }
        }
        foreach ($sellerGroups as &$seller) {
            $seller['work_day_ids'] = array_values(array_unique($seller['work_day_ids']));
            $seller['operation_work_day_ids'] = array_values(array_unique($seller['operation_work_day_ids']));
            $seller['total'] = round($seller['total'], 2);
            $seller['total_amount'] = round($seller['total_amount'], 2);
        }
        unset($seller);
        $sellers = array_values($sellerGroups);
        return ['summary' => [
            'sellers' => count($sellers),
            'work_days' => count(array_unique(array_merge(...array_map(fn (array $seller) => $seller['work_day_ids'], $sellers)))) ,
            'pre_sales' => count($seenPreSaleIds),
            'total' => round(array_sum(array_map(fn ($group) => $group['total_amount'], $sellers)), 2),
            'prepared_count' => array_sum(array_column($sellers, 'prepared_count')),
            'converted_count' => array_sum(array_column($sellers, 'converted_count')),
            'preparation_eligible_count' => array_sum(array_column($sellers, 'preparation_eligible_count')),
            'sales_eligible_count' => array_sum(array_column($sellers, 'sales_eligible_count')),
            'preparation_blocked_count' => array_sum(array_column($sellers, 'preparation_blocked_count')),
            'sales_blocked_count' => array_sum(array_column($sellers, 'sales_blocked_count')),
        ], 'sellers' => $sellers, 'blocked' => $blocked];
    }

    private function execute(User $actor, string $globalKey, array $statuses, string $operation, callable $child, bool $requireUnconverted = false): array
    {
        if (blank($globalKey)) {
            throw ValidationException::withMessages(['idempotency_key' => 'La llave de idempotencia es obligatoria.']);
        }
        $businessId = (int) $actor->business_id;
        $branchId = (int) $actor->current_branch_id;
        $preview = $this->preview($businessId, $branchId, $statuses, $requireUnconverted);
        $eligibleWorkDayIds = collect($preview['sellers'])->flatMap(fn (array $seller) => $seller['operation_work_day_ids'])->unique()->flip();
        $workDays = RouteWorkDay::query()->where('business_id', $businessId)->where('branch_id', $branchId)->with('seller:id,name')->orderBy('seller_id')->orderBy('id')->get();
        $result = ['processed' => [], 'blocked' => $preview['blocked'], 'failed' => []];
        foreach ($workDays as $workDay) {
            $childKey = 'route-global:'.$operation.':'.$globalKey.':'.$workDay->id;
            $existingResultId = OperationIdempotencyKey::query()
                ->where('business_id', $businessId)->where('branch_id', $branchId)->where('user_id', $actor->id)
                ->where('operation_type', $operation === 'prepare' ? 'route_prepare_all' : 'route_deliver_all')
                ->where('idempotency_key', $childKey)->where('status', 'completed')->value('result_id');
            if ($existingResultId) {
                $result['processed'][] = [
                    'work_day_id' => $workDay->id,
                    'seller_id' => $workDay->seller_id,
                    'seller_name' => $workDay->seller?->name,
                    'batch_id' => (int) $existingResultId,
                    'total_pre_sales' => $this->batchPreSales($operation, (int) $existingResultId),
                    'replayed' => true,
                ];
                continue;
            }
            if (! $eligibleWorkDayIds->has($workDay->id)) {
                continue;
            }
            try {
                $childResult = $child($workDay, $childKey);
                $result['processed'][] = [
                    'work_day_id' => $workDay->id,
                    'seller_id' => $workDay->seller_id,
                    'seller_name' => $workDay->seller?->name,
                    'batch_id' => $childResult->resultId,
                    'total_pre_sales' => (int) ($childResult->responsePayload['total_pre_sales'] ?? $this->batchPreSales($operation, $childResult->resultId)),
                    'replayed' => $childResult->replayed,
                ];
            } catch (ValidationException $exception) {
                $reasonCode = array_key_first($exception->errors()) ?? 'child_validation_blocked';
                $result['blocked'][] = [
                    'work_day_id' => $workDay->id,
                    'seller_id' => $workDay->seller_id,
                    'seller_name' => $workDay->seller?->name,
                    'pre_sale_id' => null,
                    'reason_code' => $reasonCode,
                    'message' => collect($exception->errors())->flatten()->first() ?? $exception->getMessage(),
                ];
            } catch (\Throwable $exception) {
                $result['failed'][] = ['work_day_id' => $workDay->id, 'seller_id' => $workDay->seller_id, 'seller_name' => $workDay->seller?->name, 'reason_code' => 'unexpected_child_failure', 'message' => $exception->getMessage()];
            }
        }
        return $result;
    }

    private function batchPreSales(string $operation, int $batchId): int
    {
        $model = $operation === 'prepare' ? RoutePreparationBatch::class : RouteDeliveryBatch::class;

        return (int) $model::query()->whereKey($batchId)->value('total_pre_sales');
    }

    private function requiresOperationalAction(PreSale $preSale): bool
    {
        return $this->isPreparationCandidate($preSale) || $this->isSalesCandidate($preSale);
    }

    private function isPreparationCandidate(PreSale $preSale): bool
    {
        return $preSale->converted_sale_id === null
            && in_array($preSale->status, [PreSale::STATUS_SUBMITTED, PreSale::STATUS_PROCESSING], true);
    }

    private function isSalesCandidate(PreSale $preSale): bool
    {
        return $preSale->converted_sale_id === null && $preSale->status === PreSale::STATUS_PICKED;
    }

    private function matchesRequestedOperation(PreSale $preSale, array $eligibleStatuses): bool
    {
        return $this->requiresOperationalAction($preSale) && in_array($preSale->status, $eligibleStatuses, true);
    }
}
