<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RouteCashSettlementPersistenceTest extends TestCase
{
    public function test_cash_settlement_tables_and_origin_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('route_cash_settlements'));
        $this->assertTrue(Schema::hasTable('route_cash_settlement_items'));
        $this->assertTrue(Schema::hasColumn('route_cash_settlements', 'cash_movement_id'));
        $this->assertTrue(Schema::hasColumn('route_cash_settlement_items', 'route_pre_sale_collection_id'));
        $this->assertTrue(Schema::hasColumn('route_cash_settlement_items', 'route_delivery_collection_id'));
    }
}
