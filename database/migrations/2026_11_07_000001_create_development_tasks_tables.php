<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('development_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable()->unique();
            $table->string('title', 200);
            foreach (['description', 'acceptance_criteria', 'technical_notes', 'blocked_reason'] as $field) {
                $table->text($field)->nullable();
            }
            $table->string('task_type', 30)->index();
            $table->string('priority', 2)->index();
            $table->string('work_status', 30)->index();
            $table->string('implementation_status', 30)->index();
            $table->string('production_status', 30)->index();
            $table->string('module', 80)->index();
            $table->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $table->string('client_name', 160)->nullable()->index();
            foreach (['assigned_to', 'created_by', 'updated_by'] as $field) {
                $table->foreignId($field)->nullable()->constrained('users')->nullOnDelete();
            }
            $table->string('source_reference', 100)->nullable();
            $table->string('source_key', 120)->nullable();
            $table->unique(['source_reference', 'source_key']);
            $table->string('github_branch')->nullable();
            $table->string('github_commit', 64)->nullable();
            $table->string('github_url', 1000)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            foreach (['completed_at', 'deployed_at', 'production_verified_at', 'archived_at'] as $field) {
                $table->timestamp($field)->nullable();
            }
            $table->timestamps();
            $table->index(['archived_at', 'priority', 'sort_order', 'id'], 'development_tasks_work_queue');
        });
        Schema::create('development_task_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('development_task_id')->constrained()->cascadeOnDelete();
            $table->string('title', 300);
            $table->boolean('is_completed')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('development_task_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('development_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('activity_type', 40);
            $table->json('previous_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['development_task_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('development_task_activities');
        Schema::dropIfExists('development_task_checklist_items');
        Schema::dropIfExists('development_tasks');
    }
};
