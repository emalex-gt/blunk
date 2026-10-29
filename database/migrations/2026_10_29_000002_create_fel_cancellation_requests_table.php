<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fel_cancellation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('electronic_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider');
            $table->string('environment');
            $table->string('uuid');
            $table->string('reason', 500);
            $table->string('status')->default('pending');
            $table->jsonb('request_payload')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // One DTE can have one durable cancellation lifecycle. The UUID
            // constraint additionally prevents a concurrent duplicate record
            // if an electronic document is ever reconstructed.
            $table->unique('electronic_document_id', 'fel_cancellation_requests_document_unique');
            $table->unique(
                ['business_id', 'provider', 'environment', 'uuid'],
                'fel_cancellation_requests_provider_uuid_unique',
            );
            $table->index(['business_id', 'status']);
            $table->index(['sale_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE fel_cancellation_requests
                ADD CONSTRAINT fel_cancellation_requests_status_check
                CHECK (status IN ('pending', 'confirmed', 'rejected', 'unknown', 'manual_resolution_required'))
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fel_cancellation_requests');
    }
};
