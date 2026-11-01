<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_cost_recalculation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('executed_at');
            $table->string('status', 32);
            $table->json('metadata')->nullable();

            $table->index(['business_id', 'executed_at']);
        });

        Schema::create('product_cost_recalculation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_cost_recalculation_run_id')
                ->constrained('product_cost_recalculation_runs')
                ->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('previous_cost', 15, 5);
            $table->decimal('proposed_cost', 15, 5);
            $table->decimal('weighted_quantity', 20, 5);
            $table->unsignedInteger('acquisition_count');
            $table->json('sources')->nullable();
            $table->string('calculation_status', 32);

            $table->unique(['product_cost_recalculation_run_id', 'product_id'], 'product_cost_recalculation_item_unique');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_cost_recalculation_items');
        Schema::dropIfExists('product_cost_recalculation_runs');
    }
};
