<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->text('message');
            $table->timestamp('visible_from');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('expected_downtime', 160)->nullable();
            $table->boolean('enabled')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['enabled', 'visible_from', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_announcements');
    }
};
