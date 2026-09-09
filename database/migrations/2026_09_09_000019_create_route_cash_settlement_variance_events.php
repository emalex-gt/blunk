<?php
use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('route_cash_settlement_variance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variance_id')->constrained('route_cash_settlement_variances')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->text('note');
            $table->timestamp('occurred_at');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('operation_idempotency_key_id')->nullable()->constrained()->nullOnDelete()->unique();
            $table->timestamps();
            $table->index(['variance_id', 'occurred_at', 'id']);
            $table->index(['business_id', 'branch_id', 'occurred_at']);
        });
        DB::statement("ALTER TABLE route_cash_settlement_variance_events ADD CONSTRAINT route_cash_settlement_variance_events_check CHECK (type IN ('note', 'investigation', 'collector_contact') AND btrim(note) <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('route_cash_settlement_variance_events');
    }
};
