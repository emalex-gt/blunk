<?php

namespace App\Services\Routes;

use App\Models\PreSale;
use App\Models\OperationIdempotencyKey;
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

        foreach ($workDays as $workDay) {
            foreach ($workDay->preSales as $preSale) {
                $eligible = in_array($preSale->status, $eligibleStatuses, true) && (! $requireUnconverted || $preSale->converted_sale_id === null);
                if (! $eligible) {
                    $blocked[] = ['work_day_id' => $workDay->id, 'pre_sale_id' => $preSale->id, 'seller_id' => $workDay->seller_id, 'reason_code' => $preSale->converted_sale_id ? 'pre_sale_already_converted' : 'pre_sale_status_ineligible'];
                    continue;
                }
                $sellerGroups[$workDay->seller_id] ??= ['seller' => ['id' => $workDay->seller_id, 'name' => $workDay->seller?->name], 'work_day_ids' => [], 'pre_sales' => [], 'total' => 0.0];
                $sellerGroups[$workDay->seller_id]['work_day_ids'][] = $workDay->id;
                $sellerGroups[$workDay->seller_id]['pre_sales'][] = ['id' => $preSale->id, 'work_day_id' => $workDay->id, 'total' => (float) $preSale->total];
                $sellerGroups[$workDay->seller_id]['total'] += (float) $preSale->total;
            }
        }
        foreach ($sellerGroups as &$seller) {
            $seller['work_day_ids'] = array_values(array_unique($seller['work_day_ids']));
            $seller['total'] = round($seller['total'], 2);
        }
        unset($seller);
        $sellers = array_values($sellerGroups);
        $workDayIds = $sellers === [] ? [] : array_merge(...array_map(fn (array $group) => $group['work_day_ids'], $sellers));
        return ['summary' => ['sellers' => count($sellers), 'work_days' => count(array_unique($workDayIds)), 'pre_sales' => array_sum(array_map(fn ($group) => count($group['pre_sales']), $sellers)), 'total' => round(array_sum(array_map(fn ($group) => $group['total'], $sellers)), 2)], 'sellers' => $sellers, 'blocked' => $blocked];
    }

    private function execute(User $actor, string $globalKey, array $statuses, string $operation, callable $child, bool $requireUnconverted = false): array
    {
        if (blank($globalKey)) {
            throw ValidationException::withMessages(['idempotency_key' => 'La llave de idempotencia es obligatoria.']);
        }
        $businessId = (int) $actor->business_id;
        $branchId = (int) $actor->current_branch_id;
        $preview = $this->preview($businessId, $branchId, $statuses, $requireUnconverted);
        $eligibleWorkDayIds = collect($preview['sellers'])->flatMap(fn (array $seller) => $seller['work_day_ids'])->unique()->flip();
        $workDays = RouteWorkDay::query()->where('business_id', $businessId)->where('branch_id', $branchId)->with('seller:id,name')->orderBy('seller_id')->orderBy('id')->get();
        $result = ['processed' => [], 'blocked' => $preview['blocked'], 'failed' => []];
        foreach ($workDays as $workDay) {
            $childKey = 'route-global:'.$operation.':'.$globalKey.':'.$workDay->id;
            $existingResultId = OperationIdempotencyKey::query()
                ->where('business_id', $businessId)->where('branch_id', $branchId)->where('user_id', $actor->id)
                ->where('operation_type', $operation === 'prepare' ? 'route_prepare_all' : 'route_deliver_all')
                ->where('idempotency_key', $childKey)->where('status', 'completed')->value('result_id');
            if ($existingResultId) {
                $result['processed'][] = ['work_day_id' => $workDay->id, 'seller_id' => $workDay->seller_id, 'seller_name' => $workDay->seller?->name, 'batch_id' => (int) $existingResultId, 'replayed' => true];
                continue;
            }
            if (! $eligibleWorkDayIds->has($workDay->id)) {
                continue;
            }
            try {
                $childResult = $child($workDay, $childKey);
                $result['processed'][] = ['work_day_id' => $workDay->id, 'seller_id' => $workDay->seller_id, 'seller_name' => $workDay->seller?->name, 'batch_id' => $childResult->resultId, 'replayed' => $childResult->replayed];
            } catch (ValidationException $exception) {
                $result['blocked'][] = ['work_day_id' => $workDay->id, 'seller_id' => $workDay->seller_id, 'seller_name' => $workDay->seller?->name, 'reason_code' => 'child_validation_blocked', 'message' => $exception->getMessage()];
            } catch (\Throwable $exception) {
                $result['failed'][] = ['work_day_id' => $workDay->id, 'seller_id' => $workDay->seller_id, 'seller_name' => $workDay->seller?->name, 'reason_code' => 'unexpected_child_failure', 'message' => $exception->getMessage()];
            }
        }
        return $result;
    }
}
