<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE route_pending_collection_events DROP CONSTRAINT route_pending_events_type_check');
        DB::statement("ALTER TABLE route_pending_collection_events ADD CONSTRAINT route_pending_events_type_check CHECK (type IN ('note', 'contact', 'visit', 'promise', 'no_response', 'dispute', 'collection_reversed'))");
    }

    public function down(): void
    {
        if (DB::table('route_pending_collection_events')->where('type', 'collection_reversed')->exists()) {
            throw new RuntimeException('Cannot roll back 3E-B1 event type while collection_reversed events exist.');
        }
        DB::statement('ALTER TABLE route_pending_collection_events DROP CONSTRAINT route_pending_events_type_check');
        DB::statement("ALTER TABLE route_pending_collection_events ADD CONSTRAINT route_pending_events_type_check CHECK (type IN ('note', 'contact', 'visit', 'promise', 'no_response', 'dispute'))");
    }
};
