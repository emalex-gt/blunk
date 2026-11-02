<?php

namespace App\Services;

use App\Models\Product;
use App\Support\ProductPurchaseCostHistory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HistoricalProductCostAverageService
{
    public const METHOD_CURRENT_STOCK_FIFO = 'current_stock_fifo_assumption';
    public const METHOD_HISTORICAL_WEIGHTED_AVERAGE = 'historical_weighted_average';

    private const CALCULATION_SCALE = 10;
    private const RESULT_SCALE = 5;
    private const CHUNK_SIZE = 200;

    public function simulate(int $businessId, ?array $productIds = null): Collection
    {
        if ($productIds === []) {
            return collect();
        }

        if ($productIds !== null) {
            return $this->simulateProducts($businessId, $productIds);
        }

        $results = collect();

        Product::query()
            ->where('business_id', $businessId)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $products) use ($businessId, &$results): void {
                $results = $results->concat($this->simulateProducts($businessId, $products->pluck('id')->all(), $products));
            }, 'id');

        return $results->values();
    }

    /** @param array<int, int> $productIds */
    public function simulateProducts(int $businessId, array $productIds, ?Collection $products = null): Collection
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        if ($productIds === []) {
            return collect();
        }

        $products ??= Product::query()
            ->where('business_id', $businessId)
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->get(['id', 'cost_price']);

        if ($products->isEmpty()) {
            return collect();
        }

        $productIds = $products->pluck('id')->map(fn (mixed $id) => (int) $id)->all();
        $stockByProduct = DB::table('product_branch_stocks')
            ->where('business_id', $businessId)
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(stock)::numeric as physical_stock')
            ->pluck('physical_stock', 'product_id');
        $observations = ProductPurchaseCostHistory::normalized($businessId)
            ->whereIn('product_id', $productIds)
            ->orderBy('product_id')
            ->orderByDesc('acquired_at')
            ->orderByDesc('source_priority')
            ->orderByDesc('source_row_id')
            ->get();
        $observationsByProduct = $observations->groupBy('product_id');
        $nativeProductByRecord = $observations
            ->filter(fn (object $row) => $row->purchase_id !== null)
            ->mapWithKeys(fn (object $row) => [(string) $row->source_record_id => (int) $row->product_id]);

        return $products->map(function (Product $product) use ($stockByProduct, $observationsByProduct, $nativeProductByRecord): array {
            $currentCost = $this->decimal($product->cost_price) ?? '0.00000';
            $physicalStock = $this->decimal($stockByProduct->get($product->id, '0')) ?? '0.00000';
            $result = bccomp($physicalStock, '0', self::CALCULATION_SCALE) === 0
                ? $this->calculateHistoricalAverage($observationsByProduct->get($product->id, collect()), $nativeProductByRecord)
                : $this->calculateCurrentStock($physicalStock, $observationsByProduct->get($product->id, collect()), $nativeProductByRecord);

            if ($result['status'] !== 'calculable') {
                return [
                    'product_id' => $product->id,
                    'current_cost' => $this->round($currentCost),
                    'proposed_cost' => null,
                    'difference' => null,
                    ...$result,
                ];
            }

            $denominator = $result['calculation_method'] === self::METHOD_HISTORICAL_WEIGHTED_AVERAGE
                ? $result['weighted_quantity']
                : $result['covered_quantity'];
            $proposedCost = $this->round(bcdiv($result['weighted_total'], $denominator, self::CALCULATION_SCALE));

            return [
                'product_id' => $product->id,
                'current_cost' => $this->round($currentCost),
                'proposed_cost' => $proposedCost,
                'difference' => bcsub($proposedCost, $this->round($currentCost), self::RESULT_SCALE),
                ...$result,
            ];
        });
    }

    /** @return array<string, int|string> */
    public function summary(int $businessId): array
    {
        $summary = [
            'examined' => 0,
            'calculable' => 0,
            'calculable_current_stock' => 0,
            'calculable_historical_zero_stock' => 0,
            'different' => 0,
            'without_history' => 0,
            'insufficient_history' => 0,
            'ambiguous' => 0,
            'invalid' => 0,
            'negative_stock' => 0,
        ];
        $fingerprint = hash_init('sha256');

        Product::query()
            ->where('business_id', $businessId)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $products) use ($businessId, &$summary, $fingerprint): void {
                foreach ($this->simulateProducts($businessId, $products->pluck('id')->all(), $products) as $row) {
                    $summary['examined']++;

                    if ($row['status'] === 'calculable') {
                        $summary['calculable']++;
                        $summary[$row['calculation_method'] === self::METHOD_HISTORICAL_WEIGHTED_AVERAGE
                            ? 'calculable_historical_zero_stock'
                            : 'calculable_current_stock']++;
                        if (bccomp($row['current_cost'], (string) $row['proposed_cost'], self::RESULT_SCALE) !== 0) {
                            $summary['different']++;
                        }
                    } elseif ($row['status'] === 'sin_historial') {
                        $summary['without_history']++;
                    } elseif ($row['status'] === 'historial_insuficiente') {
                        $summary['insufficient_history']++;
                    } elseif ($row['status'] === 'ambiguous') {
                        $summary['ambiguous']++;
                    } elseif ($row['status'] === 'datos_invalidos') {
                        $summary['invalid']++;
                    } elseif ($row['status'] === 'stock_negativo') {
                        $summary['negative_stock']++;
                    }

                    hash_update($fingerprint, json_encode([
                        'product_id' => $row['product_id'],
                        'current_cost' => $row['current_cost'],
                        'proposed_cost' => $row['proposed_cost'],
                        'method' => $row['calculation_method'],
                        'physical_stock' => $row['physical_stock'],
                        'covered_quantity' => $row['covered_quantity'],
                        'weighted_quantity' => $row['weighted_quantity'],
                        'missing_quantity' => $row['missing_quantity'],
                        'acquisition_count' => $row['acquisition_count'],
                        'status' => $row['status'],
                        'duplicates' => $row['duplicate_acquisition_count'],
                        'ambiguous' => $row['ambiguous_acquisition_count'],
                        'invalid' => $row['invalid_acquisition_count'],
                        'acquisitions' => $row['selected_acquisitions'],
                    ], JSON_THROW_ON_ERROR)."\n");
                }
            }, 'id');

        return [...$summary, 'fingerprint' => hash_final($fingerprint)];
    }

    /** @param Collection<int, object> $observations @param Collection<string, int> $nativeProductByRecord */
    private function calculateCurrentStock(string $physicalStock, Collection $observations, Collection $nativeProductByRecord): array
    {
        if (bccomp($physicalStock, '0', self::CALCULATION_SCALE) === -1) {
            return $this->result($physicalStock, '0', bcsub('0', $physicalStock, self::CALCULATION_SCALE), 'stock_negativo', null);
        }

        $remaining = $physicalStock;
        $weightedTotal = '0';
        $covered = '0';
        $acquisitionCount = 0;
        $ambiguousCount = 0;
        $invalidCount = 0;
        $sources = [];
        $selected = [];
        $blockingStatus = null;

        foreach ($observations as $observation) {
            [$source, $recordId, $isDuplicate] = $this->identity($observation, $nativeProductByRecord);

            if ($isDuplicate) {
                continue;
            }

            if ($this->isAmbiguousBlunk($observation, $source)) {
                $ambiguousCount++;
                if (bccomp($remaining, '0', self::CALCULATION_SCALE) === 1) {
                    $blockingStatus = 'ambiguous';
                    break;
                }
                continue;
            }

            $quantity = $this->decimal($observation->quantity);
            $unitCost = $this->decimal($observation->unit_cost);
            if (! $this->isValidAcquisition($quantity, $unitCost)) {
                $invalidCount++;
                if (bccomp($remaining, '0', self::CALCULATION_SCALE) === 1) {
                    $blockingStatus = 'datos_invalidos';
                    break;
                }
                continue;
            }

            $taken = bccomp($quantity, $remaining, self::CALCULATION_SCALE) === 1 ? $remaining : $quantity;
            $remaining = bcsub($remaining, $taken, self::CALCULATION_SCALE);
            $covered = bcadd($covered, $taken, self::CALCULATION_SCALE);
            $weightedTotal = bcadd($weightedTotal, bcmul($taken, $unitCost, self::CALCULATION_SCALE), self::CALCULATION_SCALE);
            $acquisitionCount++;
            $sources[$source] = true;
            $selected[] = $this->selected($observation, $source, $recordId, $quantity, $taken, $unitCost);

            if (bccomp($remaining, '0', self::CALCULATION_SCALE) === 0) {
                break;
            }
        }

        $status = $blockingStatus
            ?? (bccomp($remaining, '0', self::CALCULATION_SCALE) === 0
                ? 'calculable'
                : ($acquisitionCount === 0 ? 'sin_historial' : 'historial_insuficiente'));

        return $this->result(
            $physicalStock,
            $covered,
            $remaining,
            $status,
            self::METHOD_CURRENT_STOCK_FIFO,
            $acquisitionCount,
            $sources,
            $this->duplicateCount($observations, $nativeProductByRecord),
            $ambiguousCount,
            $invalidCount,
            $selected,
            $weightedTotal,
            $covered,
        );
    }

    /** @param Collection<int, object> $observations @param Collection<string, int> $nativeProductByRecord */
    private function calculateHistoricalAverage(Collection $observations, Collection $nativeProductByRecord): array
    {
        $weightedTotal = '0';
        $weightedQuantity = '0';
        $acquisitionCount = 0;
        $ambiguousCount = 0;
        $invalidCount = 0;
        $sources = [];
        $selected = [];

        foreach ($observations as $observation) {
            [$source, $recordId, $isDuplicate] = $this->identity($observation, $nativeProductByRecord);
            if ($isDuplicate) {
                continue;
            }

            if ($this->isAmbiguousBlunk($observation, $source)) {
                $ambiguousCount++;
                continue;
            }

            $quantity = $this->decimal($observation->quantity);
            $unitCost = $this->decimal($observation->unit_cost);
            if (! $this->isValidAcquisition($quantity, $unitCost)) {
                $invalidCount++;
                continue;
            }

            $weightedQuantity = bcadd($weightedQuantity, $quantity, self::CALCULATION_SCALE);
            $weightedTotal = bcadd($weightedTotal, bcmul($quantity, $unitCost, self::CALCULATION_SCALE), self::CALCULATION_SCALE);
            $acquisitionCount++;
            $sources[$source] = true;
            $selected[] = $this->selected($observation, $source, $recordId, $quantity, $quantity, $unitCost);
        }

        $status = $ambiguousCount > 0
            ? 'ambiguous'
            : ($invalidCount > 0
                ? 'datos_invalidos'
                : ($acquisitionCount > 0 ? 'calculable' : 'sin_historial'));

        return $this->result(
            '0',
            '0',
            '0',
            $status,
            self::METHOD_HISTORICAL_WEIGHTED_AVERAGE,
            $acquisitionCount,
            $sources,
            $this->duplicateCount($observations, $nativeProductByRecord),
            $ambiguousCount,
            $invalidCount,
            $selected,
            $weightedTotal,
            $weightedQuantity,
        );
    }

    /** @return array{0:string,1:string,2:bool} */
    private function identity(object $observation, Collection $nativeProductByRecord): array
    {
        $source = mb_strtolower(trim((string) $observation->source_system));
        $recordId = (string) $observation->source_record_id;
        $duplicate = $observation->purchase_id === null
            && $source === 'blunk'
            && $nativeProductByRecord->has($recordId)
            && (int) $nativeProductByRecord->get($recordId) === (int) $observation->product_id;

        return [$source, $recordId, $duplicate];
    }

    private function isAmbiguousBlunk(object $observation, string $source): bool
    {
        return $observation->purchase_id === null && $source === 'blunk';
    }

    private function isValidAcquisition(?string $quantity, ?string $unitCost): bool
    {
        return $quantity !== null && $unitCost !== null
            && bccomp($quantity, '0', self::CALCULATION_SCALE) === 1
            && bccomp($unitCost, '0', self::CALCULATION_SCALE) !== -1;
    }

    /** @param Collection<int, object> $observations @param Collection<string, int> $nativeProductByRecord */
    private function duplicateCount(Collection $observations, Collection $nativeProductByRecord): int
    {
        return $observations->filter(function (object $observation) use ($nativeProductByRecord): bool {
            [, , $duplicate] = $this->identity($observation, $nativeProductByRecord);

            return $duplicate;
        })->count();
    }

    /** @return array<string, string|bool> */
    private function selected(object $observation, string $source, string $recordId, string $quantity, string $taken, string $unitCost): array
    {
        return [
            'acquired_at' => (string) $observation->acquired_at,
            'source' => $source,
            'identifier' => $recordId,
            'historical_quantity' => $this->roundQuantity($quantity),
            'taken_quantity' => $this->roundQuantity($taken),
            'unit_cost' => $this->round($unitCost),
            'partial' => bccomp($taken, $quantity, self::CALCULATION_SCALE) === -1,
        ];
    }

    /** @param array<string, true> $sources @param array<int, array<string, string|bool>> $selected */
    private function result(string $physicalStock, string $covered, string $missing, string $status, ?string $method, int $acquisitionCount = 0, array $sources = [], int $duplicates = 0, int $ambiguous = 0, int $invalid = 0, array $selected = [], string $weightedTotal = '0', ?string $weightedQuantity = null): array
    {
        return [
            'weighted_total' => $weightedTotal,
            'physical_stock' => $this->roundQuantity($physicalStock),
            'covered_quantity' => $this->roundQuantity($covered),
            'weighted_quantity' => $this->roundQuantity($weightedQuantity ?? $covered),
            'missing_quantity' => $this->roundQuantity($missing),
            'acquisition_count' => $acquisitionCount,
            'source' => $sources === [] ? null : implode(', ', array_keys($sources)),
            'status' => $status,
            'calculation_method' => $method,
            'duplicate_acquisition_count' => $duplicates,
            'ambiguous_acquisition_count' => $ambiguous,
            'invalid_acquisition_count' => $invalid,
            'selected_acquisitions' => $selected,
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
