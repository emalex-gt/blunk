<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('route_cash_settlement_variances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_cash_settlement_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('collector_user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('difference_amount', 14, 2);
            $table->string('status', 16)->default('open');
            $table->string('reason_code', 32);
            $table->text('explanation');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'branch_id', 'status', 'opened_at']);
            $table->index(['business_id', 'branch_id', 'collector_user_id', 'status']);
            $table->index(['business_id', 'branch_id', 'assigned_to', 'status']);
        });

        DB::statement("ALTER TABLE route_cash_settlement_variances ADD CONSTRAINT route_cash_settlement_variances_check CHECK (
            difference_amount <> 0
            AND status IN ('open', 'resolved')
            AND btrim(explanation) <> ''
            AND ((difference_amount < 0 AND reason_code IN ('counting_difference', 'collector_reported_loss', 'missing_cash', 'other')) OR (difference_amount > 0 AND reason_code IN ('counting_difference', 'unidentified_extra_cash', 'other')))
            AND ((status = 'open' AND resolved_by IS NULL AND resolved_at IS NULL AND resolution_note IS NULL) OR (status = 'resolved' AND resolved_by IS NOT NULL AND resolved_at IS NOT NULL AND btrim(resolution_note) <> ''))
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('route_cash_settlement_variances');
    }
};
