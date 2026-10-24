<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_document_intents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            // No draft Sale exists before fiscal certainty. This relation is
            // populated exactly once by the local finalizer.
            $table->foreignId('finalized_sale_id')->nullable()->unique()->constrained('sales')->nullOnDelete();
            $table->foreignId('cash_register_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('credit_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->decimal('credit_hold_amount', 14, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('country', 2);
            $table->string('provider');
            $table->string('environment');
            $table->string('document_type');
            $table->string('operation_type');
            $table->string('internal_reference');
            $table->string('idempotency_key')->nullable();
            $table->string('status')->default('pending');
            $table->jsonb('operation_payload');
            $table->jsonb('fiscal_payload')->nullable();
            $table->jsonb('provider_response')->nullable();
            $table->unsignedInteger('negative_reconciliation_count')->default(0);
            $table->timestamp('last_reconciliation_at')->nullable();
            $table->timestamp('next_reconciliation_at')->nullable();
            $table->jsonb('last_reconciliation_result')->nullable();
            $table->text('last_reconciliation_error')->nullable();
            $table->text('last_finalization_error')->nullable();
            $table->timestamp('certified_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'provider', 'environment', 'internal_reference'], 'fiscal_document_intent_reference_unique');
            $table->index(['status', 'next_reconciliation_at']);
            $table->index(['business_id', 'status']);
            $table->index(['credit_customer_id', 'status']);
        });

        // Conciliation is no longer a tenant capability. Remove the orphaned
        // grant rather than merely hiding its navigation entry.
        if (Schema::hasTable('permissions')) {
            $permissionId = DB::table('permissions')->where('key', 'fel.reconcile')->value('id');
            if ($permissionId) {
                if (Schema::hasTable('role_has_permissions')) {
                    DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
                }
                if (Schema::hasTable('model_has_permissions')) {
                    DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
                }
                DB::table('permissions')->where('id', $permissionId)->delete();
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_intents');
    }
};
