<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_pending_collection_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_pending_collection_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->text('note')->nullable();
            $table->timestamp('occurred_at');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('operation_idempotency_key_id')->nullable()->constrained()->nullOnDelete()->unique();
            $table->timestamps();

            $table->index(['route_pending_collection_case_id', 'occurred_at', 'id'], 'route_pending_events_case_occurred');
            $table->index(['business_id', 'branch_id', 'occurred_at'], 'route_pending_events_scope_occurred');
        });

        DB::statement("ALTER TABLE route_pending_collection_events ADD CONSTRAINT route_pending_events_type_check CHECK (type IN ('note', 'contact', 'visit', 'promise', 'no_response', 'dispute'))");
        DB::statement("ALTER TABLE route_pending_collection_events ADD CONSTRAINT route_pending_events_note_check CHECK ((type NOT IN ('note', 'promise', 'dispute')) OR (note IS NOT NULL AND btrim(note) <> ''))");
    }

    public function down(): void
    {
        Schema::dropIfExists('route_pending_collection_events');
    }
};
