<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pre_sales')) {
            return;
        }

        Schema::table('pre_sales', function (Blueprint $table) {
            if (! Schema::hasColumn('pre_sales', 'converted_at')) {
                $table->timestamp('converted_at')->nullable()->after('picked_by');
            }

            if (! Schema::hasColumn('pre_sales', 'converted_by')) {
                $table->foreignId('converted_by')->nullable()->after('converted_at')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('pre_sales', 'converted_sale_id')) {
                $table->foreignId('converted_sale_id')->nullable()->after('converted_by')->constrained('sales')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pre_sales')) {
            return;
        }

        Schema::table('pre_sales', function (Blueprint $table) {
            if (Schema::hasColumn('pre_sales', 'converted_sale_id')) {
                $table->dropConstrainedForeignId('converted_sale_id');
            }

            if (Schema::hasColumn('pre_sales', 'converted_by')) {
                $table->dropConstrainedForeignId('converted_by');
            }

            if (Schema::hasColumn('pre_sales', 'converted_at')) {
                $table->dropColumn('converted_at');
            }
        });
    }
};
