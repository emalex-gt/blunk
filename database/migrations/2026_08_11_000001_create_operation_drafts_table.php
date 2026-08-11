<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('title')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('destination_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->json('payload');
            $table->unsignedSmallInteger('payload_version')->default(1);
            $table->string('status', 30)->default('active');
            $table->string('converted_type')->nullable();
            $table->unsignedBigInteger('converted_id')->nullable();
            $table->timestamp('discarded_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'type', 'status']);
            $table->index(['business_id', 'branch_id', 'type', 'status']);
            $table->index(['user_id', 'type', 'status']);
            $table->index('last_used_at');
            $table->index(['converted_type', 'converted_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_drafts');
    }
};
