<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_branch_collection_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->unique()->constrained('branches')->cascadeOnDelete();
            $table->string('collection_workflow_mode', 32);
            $table->jsonb('allowed_payment_methods');
            $table->string('primary_payment_method', 32);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE route_branch_collection_settings ADD CONSTRAINT route_branch_collection_settings_workflow_check CHECK (collection_workflow_mode IN ('immediate_paid', 'per_order_collection'))");
        DB::statement("ALTER TABLE route_branch_collection_settings ADD CONSTRAINT route_branch_collection_settings_allowed_methods_check CHECK (jsonb_typeof(allowed_payment_methods) = 'array' AND jsonb_array_length(allowed_payment_methods) > 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('route_branch_collection_settings');
    }
};
