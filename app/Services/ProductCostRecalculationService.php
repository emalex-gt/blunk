<?php

namespace App\Services;

use App\Exceptions\ProductCostRecalculationSnapshotChanged;
use App\Exceptions\ProductCostRecalculationRequiresOwnTransaction;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductCostRecalculationItem;
use App\Models\ProductCostRecalculationRun;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductCostRecalculationService
{
    private const RESULT_SCALE = 5;
    private const CHUNK_SIZE = 200;

    public function __construct(
        private readonly HistoricalProductCostAverageService $averages,
    ) {
    }

    /**
     * Applies only the exact server-calculated preview identified by $expected.
     * A run is retained even if validation or the atomic application fails.
     *
     * @param array{fingerprint:string,different:int} $expected
     */
    public function apply(Business $business, User $actor, array $expected): ProductCostRecalculationRun
    {
        // A nested transaction is a savepoint on PostgreSQL. It cannot raise
        // isolation, so refusing it is safer than silently using the caller's
        // weaker isolation level for a cost-changing operation.
        if (DB::transactionLevel() > 0) {
            throw new ProductCostRecalculationRequiresOwnTransaction;
        }

        $run = ProductCostRecalculationRun::query()->create([
            'business_id' => $business->id,
            'executed_by' => $actor->id,
            'executed_at' => now(),
            'status' => ProductCostRecalculationRun::STATUS_RUNNING,
            'metadata' => [
                'algorithm' => 'current_stock_fifo_or_historical_weighted_average',
                'expected_fingerprint' => $expected['fingerprint'],
            ],
        ]);

        try {
            return DB::transaction(function () use ($business, $expected, $run): ProductCostRecalculationRun {
                // PostgreSQL serialization additionally detects writes by an
                // importer that does not participate in these application locks.
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
                }

                // Locking the tenant row serializes recalculations for this tenant,
                // including the initial case where no product needs an update.
                Business::query()->lockForUpdate()->findOrFail($business->id);

                // Acquire the cooperating application locks before calculating the
                // definitive fingerprint. This covers products, physical stock,
                // native purchases and imported acquisition history.
                $this->lockSimulationInputs($business->id);

                $summary = $this->averages->summary($business->id);

                if (! hash_equals($expected['fingerprint'], $summary['fingerprint'])
                    || (int) $expected['different'] !== (int) $summary['different']) {
                    throw new ProductCostRecalculationSnapshotChanged;
                }

                $applied = 0;

                Product::query()
                    ->where('business_id', $business->id)
                    ->orderBy('id')
                    ->chunkById(self::CHUNK_SIZE, function (Collection $products) use ($business, $run, &$applied): void {
                        $simulation = $this->averages->simulateProducts(
                            $business->id,
                            $products->pluck('id')->all(),
                            $products,
                        );
                        $lockedProducts = Product::query()
                            ->where('business_id', $business->id)
                            ->whereIn('id', $products->pluck('id'))
                            ->lockForUpdate()
                            ->get()
                            ->keyBy('id');

                        foreach ($simulation as $row) {
                            if ($row['status'] !== 'calculable'
                                || $row['proposed_cost'] === null
                                || bccomp($row['current_cost'], $row['proposed_cost'], self::RESULT_SCALE) === 0) {
                                continue;
                            }

                            /** @var Product|null $product */
                            $product = $lockedProducts->get($row['product_id']);
                            if (! $product || bccomp((string) $product->cost_price, $row['current_cost'], self::RESULT_SCALE) !== 0) {
                                throw new ProductCostRecalculationSnapshotChanged;
                            }

                            $product->update(['cost_price' => $row['proposed_cost']]);

                            ProductCostRecalculationItem::query()->create([
                                'product_cost_recalculation_run_id' => $run->id,
                                'product_id' => $product->id,
                                'previous_cost' => $row['current_cost'],
                                'proposed_cost' => $row['proposed_cost'],
                                'physical_stock' => $row['physical_stock'],
                                'covered_quantity' => $row['covered_quantity'],
                                'missing_quantity' => $row['missing_quantity'],
                                'weighted_quantity' => $row['weighted_quantity'],
                                'acquisition_count' => $row['acquisition_count'],
                                'sources' => $row['source'] === null ? [] : explode(', ', $row['source']),
                                'selected_acquisitions' => $row['selected_acquisitions'],
                                'calculation_method' => $row['calculation_method'],
                                'calculation_status' => $row['status'],
                            ]);
                            $applied++;
                        }
                    }, 'id');

                $run->update([
                    'status' => ProductCostRecalculationRun::STATUS_COMPLETED,
                    'metadata' => [
                        'algorithm' => 'current_stock_fifo_or_historical_weighted_average',
                        'fingerprint' => $summary['fingerprint'],
                        'summary' => $summary,
                        'applied_count' => $applied,
                    ],
                ]);

                return $run->fresh();
            });
        } catch (\Throwable $exception) {
            $run->update([
                'status' => ProductCostRecalculationRun::STATUS_FAILED,
                'metadata' => [
                    'expected_fingerprint' => $expected['fingerprint'],
                    'algorithm' => 'current_stock_fifo_or_historical_weighted_average',
                    'failure' => $exception instanceof ProductCostRecalculationSnapshotChanged
                        ? 'snapshot_changed'
                        : 'application_failed',
                ],
            ]);

            throw $exception;
        }
    }

    private function lockSimulationInputs(int $businessId): void
    {
        Product::query()
            ->where('business_id', $businessId)
            ->select('id')
            ->lockForUpdate()
            ->eachById(static fn () => null, self::CHUNK_SIZE);

        DB::table('product_branch_stocks')
            ->where('business_id', $businessId)
            ->select('id')
            ->lockForUpdate()
            ->orderBy('id')
            ->eachById(static fn () => null, self::CHUNK_SIZE);

        DB::table('historical_product_purchase_costs')
            ->where('business_id', $businessId)
            ->select('id')
            ->lockForUpdate()
            ->orderBy('id')
            ->eachById(static fn () => null, self::CHUNK_SIZE);

        DB::table('purchase_items')
            ->join('purchases', function ($join) {
                $join->on('purchases.id', '=', 'purchase_items.purchase_id')
                    ->whereColumn('purchases.business_id', 'purchase_items.business_id');
            })
            ->where('purchase_items.business_id', $businessId)
            ->select('purchase_items.id')
            ->orderBy('purchase_items.id')
            ->lock('FOR UPDATE OF purchase_items, purchases')
            ->eachById(static fn () => null, self::CHUNK_SIZE, 'purchase_items.id', 'id');
    }
}
