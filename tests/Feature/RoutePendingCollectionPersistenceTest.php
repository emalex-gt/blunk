<?php

namespace Tests\Feature;

use App\Models\RoutePendingCollectionCase;
use App\Models\RoutePendingCollectionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoutePendingCollectionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_collection_case_and_event_storage_are_available(): void
    {
        $this->assertTrue(class_exists(RoutePendingCollectionCase::class));
        $this->assertTrue(class_exists(RoutePendingCollectionEvent::class));
        $this->assertTrue(Schema::hasTable('route_pending_collection_cases'));
        $this->assertTrue(Schema::hasTable('route_pending_collection_events'));
        $this->assertTrue(Schema::hasColumn('route_pending_collection_cases', 'resolution_route_delivery_collection_id'));
        $this->assertTrue(Schema::hasColumn('route_pending_collection_events', 'operation_idempotency_key_id'));
    }
}
