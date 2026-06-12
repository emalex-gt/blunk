<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_settings', 'allow_negative_stock')) {
                $table->boolean('allow_negative_stock')->default(false)->after('enable_credit_sales');
            }
        });

        if (! Schema::hasTable('fel_reconciliation_requests')) {
            Schema::create('fel_reconciliation_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
                $table->string('internal_reference');
                $table->dateTime('issued_date')->nullable();
                $table->string('provider')->default('digifact');
                $table->string('environment')->default('test');
                $table->string('status')->default('pending');
                $table->text('last_error')->nullable();
                $table->unsignedInteger('attempts')->default(0);
                $table->jsonb('payload_snapshot')->nullable();
                $table->jsonb('response_snapshot')->nullable();
                $table->foreignId('resolved_sale_id')->nullable()->constrained('sales')->nullOnDelete();
                $table->foreignId('resolved_electronic_document_id')->nullable()->constrained('electronic_documents')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('checked_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->unique(['business_id', 'provider', 'environment', 'internal_reference'], 'fel_reconciliation_unique_reference');
                $table->index(['business_id', 'status']);
                $table->index('sale_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fel_reconciliation_requests');

        Schema::table('tenant_settings', function (Blueprint $table) {
            if (Schema::hasColumn('tenant_settings', 'allow_negative_stock')) {
                $table->dropColumn('allow_negative_stock');
            }
        });
    }
};
