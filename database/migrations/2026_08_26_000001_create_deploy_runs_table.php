<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deploy_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('local_commit_before', 80)->nullable();
            $table->string('remote_commit_target', 80)->nullable();
            $table->string('local_commit_after', 80)->nullable();
            $table->string('branch', 80)->default('main');
            $table->string('output_log_path')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('exit_code')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deploy_runs');
    }
};
