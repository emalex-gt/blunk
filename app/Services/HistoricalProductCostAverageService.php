<?php

namespace App\Services;

use App\Models\Product;
use App\Support\ProductPurchaseCostHistory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HistoricalProductCostAverageService
{
    private const CALCULATION_SCALE = 10;
    private const RESULT_SCALE = 5;
    private const CHUNK_SIZE = 200;

    /**
     * Simulates a FIFO-assumption valuation of currently physical stock. It
     * consumes the newest valid acquisitions backwards only until the current
     * stock is covered; it never values absent or partially covered stock.
     */
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
                $results = $results->concat($this->simulateProducts(
                    $businessId,
                    $products->pluck('id')->all(),
                    $products,
                ));
            }, 'id');

        return $results->values();
    }

    /**
     * @param array<int, int> $productIds
     * @param Collection<int, Product>|null $products
     */
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
            $result = $this->calculate(
                $physicalStock,
                $observationsByProduct->get($product->id, collect()),
                $nativeProductByRecord,
            );

            if ($result['status'] !== 'calculable') {
                return [
                    'product_id' => $product->id,
                    'current_cost' => $this->round($currentCost),
                    'proposed_cost' => null,
                    'difference' => null,
                    ...$result,
                ];
            }

            $proposedCost = $this->round(bcdiv($result['weighted_total'], $result['covered_quantity'], self::CALCULATION_SCALE));

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
     * @return array{examined:int,calculable:int,different:int,without_stock:int,without_history:int,insufficient_history:int,ambiguous:int,invalid:int,fingerprint:string}
     */
    public function summary(int $businessId): array
    {
        $summary = [
            'examined' => 0,
            'calculable' => 0,
            'different' => 0,
            'without_stock' => 0,
            'without_history' => 0,
            'insufficient_history' => 0,
            'ambiguous' => 0,
            'invalid' => 0,
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
                        if (bccomp($row['current_cost'], (string) $row['proposed_cost'], self::RESULT_SCALE) !== 0) {
                            $summary['different']++;
                        }
                    } elseif (in_array($row['status'], ['sin_existencias', 'stock_negativo'], true)) {
                        $summary['without_stock']++;
                    } elseif ($row['status'] === 'sin_historial') {
                        $summary['without_history']++;
                    } elseif ($row['status'] === 'historial_insuficiente') {
                        $summary['insufficient_history']++;
                    } elseif ($row['status'] === 'ambiguous') {
                        $summary['ambiguous']++;
                    } elseif ($row['status'] === 'datos_invalidos') {
                        $summary['invalid']++;
                    }

                    hash_update($fingerprint, json_encode([
                        'product_id' => $row['product_id'],
                        'current_cost' => $row['current_cost'],
                        'proposed_cost' => $row['proposed_cost'],
                        'physical_stock' => $row['physical_stock'],
                        'covered_quantity' => $row['covered_quantity'],
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

    /**
     * @param Collection<int, object> $observations
     * @param Collection<string, int> $nativeProductByRecord
     */
    private function calculate(string $physicalStock, Collection $observations, Collection $nativeProductByRecord): array
    {
        $stock = $this->roundQuantity($physicalStock);
        $remaining = $physicalStock;
        $weightedTotal = '0';
        $covered = '0';
        $acquisitionCount = 0;
        // Deduplication is diagnostic and applies to the complete normalized
        // history, not only to the newest rows consumed for current stock.
        // Otherwise a native acquisition that fully covers stock hides its
        // older documentary duplicate from the audit counter.
        $duplicateCount = $observations
            ->filter(function (object $observation) use ($nativeProductByRecord): bool {
                $source = mb_strtolower(trim((string) $observation->source_system));

                return $observation->purchase_id === null
                    && $source === 'blunk'
                    && $nativeProductByRecord->has((string) $observation->source_record_id)
                    && (int) $nativeProductByRecord->get((string) $observation->source_record_id) === (int) $observation->product_id;
            })
            ->count();
        $ambiguousCount = 0;
        $invalidCount = 0;
        $sources = [];
        $selected = [];
        $blockingStatus = null;

        if (bccomp($physicalStock, '0', self::CALCULATION_SCALE) === 0) {
            return $this->result($stock, '0', '0', 'sin_existencias', 0, null, 0, 0, 0, []);
        }

        if (bccomp($physicalStock, '0', self::CALCULATION_SCALE) === -1) {
            return $this->result($stock, '0', $this->roundQuantity(bcsub('0', $physicalStock, self::CALCULATION_SCALE)), 'stock_negativo', 0, null, 0, 0, 0, []);
        }

        foreach ($observations as $observation) {
            $source = mb_strtolower(trim((string) $observation->source_system));
            $recordId = (string) $observation->source_record_id;
            $isNative = $observation->purchase_id !== null;

            if (! $isNative && $source === 'blunk') {
                if ($nativeProductByRecord->has($recordId)
                    && (int) $nativeProductByRecord->get($recordId) === (int) $observation->product_id) {
                    continue;
                }

                $ambiguousCount++;
                if (bccomp($remaining, '0', self::CALCULATION_SCALE) === 1) {
                    $blockingStatus = 'ambiguous';
                    break;
                }

                continue;
            }

            $quantity = $this->decimal($observation->quantity);
            $unitCost = $this->decimal($observation->unit_cost);

            if ($quantity === null || $unitCost === null
                || bccomp($quantity, '0', self::CALCULATION_SCALE) !== 1
                || bccomp($unitCost, '0', self::CALCULATION_SCALE) === -1) {
                $invalidCount++;
                if (bccomp($remaining, '0', self::CALCULATION_SCALE) === 1) {
                    $blockingStatus = 'datos_invalidos';
                    break;
                }

                continue;
            }

            $taken = bccomp($quantity, $remaining, self::CALCULATION_SCALE) === 1
                ? $remaining
                : $quantity;
            $remaining = bcsub($remaining, $taken, self::CALCULATION_SCALE);
            $covered = bcadd($covered, $taken, self::CALCULATION_SCALE);
            $weightedTotal = bcadd($weightedTotal, bcmul($taken, $unitCost, self::CALCULATION_SCALE), self::CALCULATION_SCALE);
            $acquisitionCount++;
            $sources[$source] = true;
            $selected[] = [
                'acquired_at' => (string) $observation->acquired_at,
                'source' => $source,
                'identifier' => $recordId,
                'historical_quantity' => $this->roundQuantity($quantity),
                'taken_quantity' => $this->roundQuantity($taken),
                'unit_cost' => $this->round($unitCost),
                'partial' => bccomp($taken, $quantity, self::CALCULATION_SCALE) === -1,
            ];

            if (bccomp($remaining, '0', self::CALCULATION_SCALE) === 0) {
                break;
            }
        }

        $status = $blockingStatus
            ?? (bccomp($remaining, '0', self::CALCULATION_SCALE) === 0
                ? 'calculable'
                : ($acquisitionCount === 0 ? 'sin_historial' : 'historial_insuficiente'));

        return $this->result(
            $stock,
            $this->roundQuantity($covered),
            $this->roundQuantity($remaining),
            $status,
            $acquisitionCount,
            $sources === [] ? null : implode(', ', array_keys($sources)),
            $duplicateCount,
            $ambiguousCount,
            $invalidCount,
            $selected,
            $weightedTotal,
        );
    }

    /** @param array<int, array<string, string|bool>> $selected */
    private function result(string $stock, string $covered, string $missing, string $status, int $acquisitionCount, ?string $source, int $duplicates, int $ambiguous, int $invalid, array $selected, string $weightedTotal = '0'): array
    {
        return [
            'weighted_total' => $weightedTotal,
            'physical_stock' => $stock,
            'covered_quantity' => $covered,
            // Compatibility alias for the former audit attribute.
            'weighted_quantity' => $covered,
            'missing_quantity' => $missing,
            'acquisition_count' => $acquisitionCount,
            'source' => $source,
            'status' => $status,
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
