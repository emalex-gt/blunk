<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchases')) {
            return;
        }

        Schema::table('purchases', function (Blueprint $table) {
            if (! Schema::hasColumn('purchases', 'supplier_invoice_number')) {
                $table->string('supplier_invoice_number', 100)->nullable()->after('purchase_number');
                $table->index(['business_id', 'supplier_invoice_number'], 'purchases_business_supplier_invoice_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('purchases')) {
            return;
        }

        Schema::table('purchases', function (Blueprint $table) {
            if (Schema::hasColumn('purchases', 'supplier_invoice_number')) {
                $table->dropIndex('purchases_business_supplier_invoice_idx');
                $table->dropColumn('supplier_invoice_number');
            }
        });
    }
};
