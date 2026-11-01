<?php

namespace App\Services;

use App\Models\Product;
use App\Support\ProductPurchaseCostHistory;
use Illuminate\Support\Collection;

class HistoricalProductCostAverageService
{
    private const CALCULATION_SCALE = 10;
    private const RESULT_SCALE = 5;

    /**
     * Returns a read-only historical weighted-average simulation for a tenant.
     *
     * @return Collection<int, array{
     *     product_id:int,
     *     current_cost:string,
     *     proposed_cost:?string,
     *     difference:?string,
     *     weighted_quantity:string,
     *     acquisition_count:int,
     *     source:?string,
     *     status:string,
     *     duplicate_acquisition_count:int,
     *     ambiguous_acquisition_count:int,
     *     invalid_acquisition_count:int
     * }>
     */
    public function simulate(int $businessId, ?array $productIds = null): Collection
    {
        if ($productIds === []) {
            return collect();
        }

        $products = Product::query()
            ->where('business_id', $businessId)
            ->when($productIds !== null, fn ($query) => $query->whereIn('id', $productIds))
            ->orderBy('id')
            ->get(['id', 'cost_price']);

        if ($products->isEmpty()) {
            return collect();
        }

        $productIds = $products->pluck('id')->all();
        $observations = ProductPurchaseCostHistory::normalized($businessId)
            ->whereIn('product_id', $productIds)
            ->orderBy('product_id')
            ->orderBy('acquired_at')
            ->orderBy('source_priority')
            ->orderBy('source_row_id')
            ->get();

        $observationsByProduct = $observations->groupBy('product_id');
        $nativeProductByRecord = $observations
            ->filter(fn (object $row) => $row->purchase_id !== null)
            ->mapWithKeys(fn (object $row) => [(string) $row->source_record_id => (int) $row->product_id]);

        return $products->map(function (Product $product) use ($observationsByProduct, $nativeProductByRecord): array {
            $result = $this->calculate(
                $observationsByProduct->get($product->id, collect()),
                $nativeProductByRecord,
            );
            $currentCost = $this->decimal($product->cost_price) ?? '0.00000';

            if ($result['status'] !== 'calculable') {
                return [
                    'product_id' => $product->id,
                    'current_cost' => $this->round($currentCost),
                    'proposed_cost' => null,
                    'difference' => null,
                    ...$result,
                ];
            }

            $proposedCost = $this->round(bcdiv($result['weighted_total'], $result['weighted_quantity'], self::CALCULATION_SCALE));

            return [
                'product_id' => $product->id,
                'current_cost' => $this->round($currentCost),
                'proposed_cost' => $proposedCost,
                'difference' => bcsub($proposedCost, $this->round($currentCost), self::RESULT_SCALE),
                ...$result,
            ];
        });
    }

    /**
     * @param Collection<int, object> $observations
     * @param Collection<string, int> $nativeProductByRecord
     * @return array{weighted_total:string,weighted_quantity:string,acquisition_count:int,source:?string,status:string,duplicate_acquisition_count:int,ambiguous_acquisition_count:int,invalid_acquisition_count:int}
     */
    private function calculate(Collection $observations, Collection $nativeProductByRecord): array
    {
        $weightedTotal = '0';
        $weightedQuantity = '0';
        $acquisitionCount = 0;
        $duplicateCount = 0;
        $ambiguousCount = 0;
        $invalidCount = 0;
        $sources = [];

        foreach ($observations as $observation) {
            $source = mb_strtolower(trim((string) $observation->source_system));
            $recordId = (string) $observation->source_record_id;
            $isNative = $observation->purchase_id !== null;

            if (! $isNative && $source === 'blunk') {
                if ($nativeProductByRecord->has($recordId)
                    && (int) $nativeProductByRecord->get($recordId) === (int) $observation->product_id) {
                    $duplicateCount++;

                    continue;
                }

                $ambiguousCount++;

                continue;
            }

            $quantity = $this->decimal($observation->quantity);
            $unitCost = $this->decimal($observation->unit_cost);

            if ($quantity === null || $unitCost === null
                || bccomp($quantity, '0', self::CALCULATION_SCALE) !== 1
                || bccomp($unitCost, '0', self::CALCULATION_SCALE) === -1) {
                $invalidCount++;

                continue;
            }

            $weightedQuantity = bcadd($weightedQuantity, $quantity, self::CALCULATION_SCALE);
            $weightedTotal = bcadd(
                $weightedTotal,
                bcmul($quantity, $unitCost, self::CALCULATION_SCALE),
                self::CALCULATION_SCALE,
            );
            $acquisitionCount++;
            $sources[$source] = true;
        }

        return [
            'weighted_total' => $weightedTotal,
            'weighted_quantity' => $this->roundQuantity($weightedQuantity),
            'acquisition_count' => $acquisitionCount,
            'source' => $sources === [] ? null : implode(', ', array_keys($sources)),
            'status' => $ambiguousCount > 0
                ? 'ambiguous'
                : ($acquisitionCount > 0 ? 'calculable' : 'sin_historial'),
            'duplicate_acquisition_count' => $duplicateCount,
            'ambiguous_acquisition_count' => $ambiguousCount,
            'invalid_acquisition_count' => $invalidCount,
        ];
    }

    private function decimal(mixed $value): ?string
    {
        $value = trim((string) $value);

        if (! preg_match('/^[+-]?\d+(?:\.\d+)?$/', $value)) {
            return null;
        }

        return bcadd($value, '0', self::CALCULATION_SCALE);
    }

    private function round(string $value): string
    {
        return bcadd($value, '0.000005', self::RESULT_SCALE);
    }

    private function roundQuantity(string $value): string
    {
        return bcadd($value, '0', self::RESULT_SCALE);
    }
}
