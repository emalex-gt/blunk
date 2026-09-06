<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_external_delivery_reconciliation_item_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_external_delivery_reconciliation_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('previous_values');
            $table->json('new_values');
            $table->text('correction_reason');
            $table->foreignId('corrected_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('corrected_at');
            $table->timestamps();
            $table->unique(['route_external_delivery_reconciliation_item_id', 'version'], 'route_external_reconciliation_revision_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_external_delivery_reconciliation_item_revisions');
    }
};
