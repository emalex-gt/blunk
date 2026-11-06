<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_incidents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('fingerprint', 191)->unique();
            $table->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 100);
            $table->string('severity', 2);
            $table->string('status', 20)->default('open');
            $table->string('message', 500);
            $table->json('metadata')->nullable();
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('detected_at');
            $table->timestamp('last_occurred_at');
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamp('last_reminded_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'severity', 'last_occurred_at']);
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_incidents');
    }
};
