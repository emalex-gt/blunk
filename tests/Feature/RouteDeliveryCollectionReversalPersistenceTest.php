<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RouteDeliveryCollectionReversalPersistenceTest extends TestCase
{
    public function test_b1_reversal_schema_exposes_statuses_and_append_only_ledger(): void
    {
        $this->assertTrue(Schema::hasColumn('route_delivery_collections', 'status'));
        $this->assertTrue(Schema::hasColumn('sale_payments', 'status'));
        $this->assertTrue(Schema::hasTable('route_delivery_collection_reversals'));
    }

    public function test_postgresql_schema_enforces_captured_partial_uniques_and_reversal_ledger_constraints(): void
    {
        $collectionDefault = DB::table('information_schema.columns')
            ->where('table_schema', 'public')->where('table_name', 'route_delivery_collections')->where('column_name', 'status')
            ->value('column_default');
        $paymentDefault = DB::table('information_schema.columns')
            ->where('table_schema', 'public')->where('table_name', 'sale_payments')->where('column_name', 'status')
            ->value('column_default');
        $this->assertStringContainsString('captured', (string) $collectionDefault);
        $this->assertStringContainsString('captured', (string) $paymentDefault);

        $indexes = collect(DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'route_delivery_collections'"))->keyBy('indexname');
        foreach ([
            'route_delivery_collections_sale_captured_unique',
            'route_delivery_collections_external_captured_unique',
            'route_delivery_collections_stop_captured_unique',
        ] as $index) {
            $this->assertTrue($indexes->has($index));
            $this->assertStringContainsString(' WHERE ', $indexes->get($index)->indexdef);
            $this->assertStringContainsString("'captured'", $indexes->get($index)->indexdef);
        }
        $this->assertFalse($indexes->has('route_delivery_collections_sale_unique'));
        $this->assertFalse($indexes->has('route_delivery_collections_item_unique'));

        $constraints = collect(DB::select("SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid = 'route_delivery_collection_reversals'::regclass"))->keyBy('conname');
        $reversalIndexes = collect(DB::select("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'route_delivery_collection_reversals'"))->pluck('indexdef');
        $this->assertTrue($reversalIndexes->contains(fn (string $definition) => str_contains($definition, 'UNIQUE') && str_contains($definition, 'route_delivery_collection_id')));
        $this->assertTrue($reversalIndexes->contains(fn (string $definition) => str_contains($definition, 'UNIQUE') && str_contains($definition, 'sale_payment_id')));
        foreach ([
            'route_delivery_reversals_reason_check',
            'route_delivery_reversals_cash_check',
            'route_delivery_reversals_case_check',
        ] as $constraint) {
            $this->assertTrue($constraints->has($constraint));
        }

        $triggers = collect(DB::select("SELECT tgname FROM pg_trigger WHERE tgrelid IN ('route_delivery_collections'::regclass, 'sale_payments'::regclass, 'route_delivery_collection_reversals'::regclass, 'route_cash_settlement_items'::regclass, 'route_cash_settlements'::regclass) AND NOT tgisinternal"))->pluck('tgname');
        $this->assertCount(5, $triggers->filter(fn (string $name) => str_starts_with($name, 'route_delivery_reversal_')));
    }
}
