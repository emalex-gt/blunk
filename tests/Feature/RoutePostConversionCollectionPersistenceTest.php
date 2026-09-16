<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class RoutePostConversionCollectionPersistenceTest extends TestCase
{
    public function test_schema_exposes_the_post_conversion_collection_financial_trace(): void
    {
        $this->assertTrue(Schema::hasTable('route_post_conversion_collections'));

        foreach ([
            'business_id', 'branch_id', 'route_delivery_batch_pre_sale_id', 'pre_sale_id', 'sale_id',
            'collected_by', 'recorded_by', 'amount', 'payment_method', 'collected_at',
            'cash_custody_policy_snapshot', 'custody_status', 'cash_posting_state',
            'cash_register_session_id', 'cash_movement_id', 'operation_idempotency_key_id', 'status',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('route_post_conversion_collections', $column), $column);
        }

        $this->assertTrue(Schema::hasTable('route_post_conversion_collection_reversals'));
        $this->assertTrue(Schema::hasColumn('sale_payments', 'route_post_conversion_collection_id'));
        $this->assertTrue(Schema::hasColumn('route_cash_settlement_items', 'route_post_conversion_collection_id'));
    }

    public function test_database_constraints_reject_partial_or_duplicate_post_conversion_financial_provenance(): void
    {
        $this->assertTrue(Schema::hasColumn('sale_payments', 'route_post_conversion_collection_id'));
        $this->assertTrue(Schema::hasColumn('route_cash_settlement_items', 'route_post_conversion_collection_id'));
        $this->assertTrue(Schema::hasTable('route_post_conversion_collection_reversals'));

        $this->expectException(QueryException::class);
        \Illuminate\Support\Facades\DB::table('route_cash_settlement_items')->insert([
            'route_cash_settlement_id' => 0,
            'route_pre_sale_collection_id' => null,
            'route_delivery_collection_id' => null,
            'route_post_conversion_collection_id' => null,
            'amount_snapshot' => '1.00',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
