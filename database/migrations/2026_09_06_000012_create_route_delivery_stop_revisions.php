<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_delivery_stop_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_delivery_stop_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('previous_values');
            $table->json('new_values');
            $table->text('correction_reason');
            $table->foreignId('corrected_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('corrected_at');
            $table->timestamps();
            $table->unique(['route_delivery_stop_id', 'version'], 'route_delivery_stop_revisions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_delivery_stop_revisions');
    }
};
