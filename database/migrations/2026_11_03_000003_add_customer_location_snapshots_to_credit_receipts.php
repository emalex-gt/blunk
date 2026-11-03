<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('credit_receipts')) {
            return;
        }

        Schema::table('credit_receipts', function (Blueprint $table): void {
            if (! Schema::hasColumn('credit_receipts', 'customer_address_reference')) {
                $table->text('customer_address_reference')->nullable();
            }
            if (! Schema::hasColumn('credit_receipts', 'customer_municipality')) {
                $table->string('customer_municipality')->nullable();
            }
            if (! Schema::hasColumn('credit_receipts', 'customer_department')) {
                $table->string('customer_department')->nullable();
            }
            if (! Schema::hasColumn('credit_receipts', 'customer_phone')) {
                $table->string('customer_phone')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('credit_receipts')) {
            return;
        }

        Schema::table('credit_receipts', function (Blueprint $table): void {
            foreach (['customer_address_reference', 'customer_municipality', 'customer_department', 'customer_phone'] as $column) {
                if (Schema::hasColumn('credit_receipts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
