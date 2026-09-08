<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RouteDeliveryRunPersistenceTest extends TestCase
{
    public function test_delivery_run_schema_exists_with_run_stop_and_revision_tables(): void
    {
        $this->assertTrue(Schema::hasTable('route_delivery_runs'));
        $this->assertTrue(Schema::hasTable('route_delivery_stops'));
        $this->assertTrue(Schema::hasTable('route_delivery_stop_revisions'));
        $this->assertTrue(Schema::hasColumn('route_delivery_collections', 'route_delivery_stop_id'));
        $this->assertTrue(Schema::hasColumn('route_delivery_collections', 'delivery_origin'));
    }
}
