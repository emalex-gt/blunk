<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'customer_postal_code')) {
                $table->string('customer_postal_code')->nullable();
            }

            if (! Schema::hasColumn('sales', 'customer_municipality')) {
                $table->string('customer_municipality')->nullable();
            }

            if (! Schema::hasColumn('sales', 'customer_department')) {
                $table->string('customer_department')->nullable();
            }

            if (! Schema::hasColumn('sales', 'customer_country')) {
                $table->string('customer_country', 2)->nullable()->default('GT');
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'postal_code')) {
                $table->string('postal_code')->nullable();
            }

            if (! Schema::hasColumn('customers', 'municipality')) {
                $table->string('municipality')->nullable();
            }

            if (! Schema::hasColumn('customers', 'department')) {
                $table->string('department')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            foreach (['postal_code', 'municipality', 'department'] as $column) {
                if (Schema::hasColumn('customers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('sales', function (Blueprint $table) {
            foreach (['customer_postal_code', 'customer_municipality', 'customer_department', 'customer_country'] as $column) {
                if (Schema::hasColumn('sales', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
