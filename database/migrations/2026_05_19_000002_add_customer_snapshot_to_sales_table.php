<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'customer_name')) {
                $table->string('customer_name')->nullable();
            }

            if (! Schema::hasColumn('sales', 'customer_doc_type')) {
                $table->string('customer_doc_type')->nullable();
            }

            if (! Schema::hasColumn('sales', 'customer_doc_number')) {
                $table->string('customer_doc_number')->nullable();
            }

            if (! Schema::hasColumn('sales', 'customer_address')) {
                $table->string('customer_address')->nullable();
            }

            if (! Schema::hasColumn('sales', 'customer_phone')) {
                $table->string('customer_phone')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            foreach (['customer_name', 'customer_doc_type', 'customer_doc_number', 'customer_address', 'customer_phone'] as $column) {
                if (Schema::hasColumn('sales', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
