<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_credit_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->decimal('credit_limit', 12, 2)->nullable();
            $table->decimal('current_balance', 12, 2)->default(0);
            $table->boolean('is_blocked')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'customer_id']);
            $table->index(['business_id', 'current_balance']);
        });

        Schema::create('customer_credit_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_credit_account_id')->constrained('customer_credit_accounts')->cascadeOnDelete();
            $table->unsignedInteger('payment_number')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('payment_method');
            $table->boolean('paid_from_cash_register')->default(false);
            $table->foreignId('cash_register_session_id')->nullable()->constrained('cash_register_sessions')->nullOnDelete();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('completed');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'payment_number']);
            $table->index(['business_id', 'customer_id', 'created_at']);
        });

        Schema::create('customer_account_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_credit_account_id')->constrained('customer_credit_accounts')->cascadeOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('customer_credit_payments')->nullOnDelete();
            $table->foreignId('credit_receipt_id')->nullable()->constrained('credit_receipts')->nullOnDelete();
            $table->string('type');
            $table->string('direction');
            $table->string('description')->nullable();
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->string('payment_method')->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'customer_id', 'created_at']);
            $table->index(['business_id', 'branch_id']);
            $table->index('sale_id');
            $table->index('payment_id');
        });

        Schema::create('customer_credit_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('customer_credit_payments')->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->unique(['payment_id', 'sale_id']);
            $table->index(['business_id', 'sale_id']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->string('payment_status')->default('paid')->after('payment_method');
            $table->decimal('amount_paid', 12, 2)->default(0)->after('payment_status');
            $table->decimal('credit_balance', 12, 2)->default(0)->after('amount_paid');
            $table->boolean('is_credit_sale')->default(false)->after('credit_balance');
            $table->date('due_date')->nullable()->after('is_credit_sale');

            $table->index(['business_id', 'customer_id', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'customer_id', 'payment_status']);
            $table->dropColumn(['payment_status', 'amount_paid', 'credit_balance', 'is_credit_sale', 'due_date']);
        });

        Schema::dropIfExists('customer_credit_payment_allocations');
        Schema::dropIfExists('customer_account_movements');
        Schema::dropIfExists('customer_credit_payments');
        Schema::dropIfExists('customer_credit_accounts');
    }
};
