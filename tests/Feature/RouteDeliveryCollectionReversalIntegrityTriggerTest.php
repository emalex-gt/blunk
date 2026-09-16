<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Support\BranchInventory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RouteDeliveryCollectionReversalIntegrityTriggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_migration_replaces_the_historical_record_alias_function_before_a_deferred_payment_trigger_commits(): void
    {
        $migrationPath = database_path('migrations/2026_09_15_000031_refresh_route_delivery_collection_reversal_integrity_function.php');

        $this->assertFileExists($migrationPath);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_route_delivery_collection_reversal_integrity() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r record; c record;
BEGIN
    IF EXISTS (
        SELECT 1
        FROM route_delivery_collections c
        WHERE c.status = 'reversed'
          AND NOT EXISTS (
              SELECT 1
              FROM route_delivery_collection_reversals r
              WHERE r.route_delivery_collection_id = c.id
          )
    ) THEN
        RAISE EXCEPTION 'Reversed collection requires reversal ledger';
    END IF;
    RETURN NULL;
END $$
SQL);

        $business = Business::query()->create([
            'name' => 'Trigger repair '.uniqid(),
            'slug' => 'trigger-repair-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $sale = Sale::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'business_number' => random_int(1000, 9999),
            'total' => 10,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);

        try {
            DB::transaction(function () use ($business, $sale): void {
                SalePayment::query()->create([
                    'business_id' => $business->id,
                    'sale_id' => $sale->id,
                    'method' => 'card',
                    'amount' => 10,
                ]);

                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            $this->fail('The stale function must fail when the deferred trigger is evaluated.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('SQLSTATE[55000]', $exception->getMessage());
            $this->assertStringContainsString('registro', $exception->getMessage());
        }

        $migration = require $migrationPath;
        $migration->up();

        DB::transaction(function () use ($business, $sale): void {
            SalePayment::query()->create([
                'business_id' => $business->id,
                'sale_id' => $sale->id,
                'method' => 'card',
                'amount' => 10,
            ]);

            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });

        $this->assertDatabaseCount('sale_payments', 1);
    }
}
