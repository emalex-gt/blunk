<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_delivery_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('delivery_tracking_snapshot', 32)->default('in_app');
            $table->string('collection_responsibility_snapshot', 32);
            $table->timestamps();
            $table->index(['business_id', 'branch_id', 'delivery_user_id', 'status'], 'route_delivery_runs_scope_status');
        });
        DB::statement("ALTER TABLE route_delivery_runs ADD CONSTRAINT route_delivery_runs_state_check CHECK (status IN ('draft', 'open', 'closed') AND delivery_tracking_snapshot = 'in_app' AND collection_responsibility_snapshot IN ('pre_seller', 'delivery_agent'))");
        DB::statement("CREATE UNIQUE INDEX route_delivery_runs_one_active_user_branch ON route_delivery_runs (business_id, branch_id, delivery_user_id) WHERE status IN ('draft', 'open')");
    }

    public function down(): void
    {
        Schema::dropIfExists('route_delivery_runs');
    }
};
