<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_sales', function (Blueprint $table) {
            if (! Schema::hasColumn('pre_sales', 'picked_at')) {
                $table->timestamp('picked_at')->nullable()->after('processing_user_id');
            }

            if (! Schema::hasColumn('pre_sales', 'picked_by')) {
                $table->foreignId('picked_by')->nullable()->after('picked_at')->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('pre_sale_items', function (Blueprint $table) {
            if (! Schema::hasColumn('pre_sale_items', 'picked_quantity')) {
                $table->decimal('picked_quantity', 14, 4)->nullable()->after('quantity');
            }

            if (! Schema::hasColumn('pre_sale_items', 'picking_note')) {
                $table->text('picking_note')->nullable()->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pre_sale_items', function (Blueprint $table) {
            if (Schema::hasColumn('pre_sale_items', 'picking_note')) {
                $table->dropColumn('picking_note');
            }

            if (Schema::hasColumn('pre_sale_items', 'picked_quantity')) {
                $table->dropColumn('picked_quantity');
            }
        });

        Schema::table('pre_sales', function (Blueprint $table) {
            if (Schema::hasColumn('pre_sales', 'picked_by')) {
                $table->dropConstrainedForeignId('picked_by');
            }

            if (Schema::hasColumn('pre_sales', 'picked_at')) {
                $table->dropColumn('picked_at');
            }
        });
    }
};
