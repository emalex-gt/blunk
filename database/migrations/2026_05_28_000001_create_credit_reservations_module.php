<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_settings', 'enable_credit_sales')) {
                $table->boolean('enable_credit_sales')->default(false)->after('allow_invoices');
            }
        });

        if (! Schema::hasTable('credit_receipts')) {
            Schema::create('credit_receipts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('customer_id')->constrained()->restrictOnDelete();
                $table->string('customer_name');
                $table->string('customer_doc_type')->nullable();
                $table->string('customer_doc_number');
                $table->string('customer_address')->nullable();
                $table->unsignedInteger('receipt_number');
                $table->string('status')->default('pending');
                $table->decimal('subtotal', 12, 2);
                $table->decimal('discount_amount', 12, 2)->default(0);
                $table->decimal('total', 12, 2);
                $table->decimal('pending_total', 12, 2);
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();

                $table->unique(['business_id', 'receipt_number']);
                $table->index(['business_id', 'customer_id', 'status']);
            });
        }

        if (! Schema::hasTable('credit_receipt_lines')) {
            Schema::create('credit_receipt_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('credit_receipt_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->restrictOnDelete();
                $table->unsignedBigInteger('variant_id')->nullable();
                $table->string('product_name');
                $table->string('sku')->nullable();
                $table->unsignedInteger('quantity');
                $table->unsignedInteger('qty_reserved');
                $table->unsignedInteger('qty_invoiced')->default(0);
                $table->unsignedInteger('qty_cancelled')->default(0);
                $table->unsignedInteger('qty_pending');
                $table->decimal('unit_price', 12, 2);
                $table->decimal('discount_amount', 12, 2)->default(0);
                $table->decimal('line_total', 12, 2);
                $table->decimal('pending_total', 12, 2);
                $table->string('status')->default('pending');
                $table->timestamps();

                $table->index(['business_id', 'product_id', 'branch_id', 'status']);
                $table->index(['business_id', 'credit_receipt_id']);
            });
        }

        if (! Schema::hasTable('credit_receipt_line_invoice')) {
            Schema::create('credit_receipt_line_invoice', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('credit_receipt_line_id')->constrained()->cascadeOnDelete();
                $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
                $table->foreignId('sale_line_id')->nullable()->constrained('sale_items')->nullOnDelete();
                $table->unsignedInteger('quantity');
                $table->decimal('amount', 12, 2);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('credit_customer_transfers')) {
            Schema::create('credit_customer_transfers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('from_customer_id')->constrained('customers')->restrictOnDelete();
                $table->foreignId('to_customer_id')->constrained('customers')->restrictOnDelete();
                $table->foreignId('transferred_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('reason')->nullable();
                if (DB::getDriverName() === 'pgsql') {
                    $table->jsonb('metadata')->nullable();
                } else {
                    $table->json('metadata')->nullable();
                }
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_customer_transfers');
        Schema::dropIfExists('credit_receipt_line_invoice');
        Schema::dropIfExists('credit_receipt_lines');
        Schema::dropIfExists('credit_receipts');

        Schema::table('tenant_settings', function (Blueprint $table) {
            if (Schema::hasColumn('tenant_settings', 'enable_credit_sales')) {
                $table->dropColumn('enable_credit_sales');
            }
        });
    }
};
