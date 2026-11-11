<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_monitor_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20);
            $table->json('checks');
            $table->unsignedInteger('active_incident_count')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('finished_at');
            $table->unsignedInteger('duration_ms');
            $table->timestamps();

            $table->index(['finished_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_monitor_runs');
    }
};
