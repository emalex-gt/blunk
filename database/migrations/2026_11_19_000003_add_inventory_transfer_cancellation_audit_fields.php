<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transfers', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_transfers', 'cancelled_at')) {
                $table->timestampTz('cancelled_at')->nullable();
            }
            if (! Schema::hasColumn('inventory_transfers', 'cancelled_by')) {
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('inventory_transfers', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transfers', function (Blueprint $table) {
            if (Schema::hasColumn('inventory_transfers', 'cancelled_by')) {
                $table->dropConstrainedForeignId('cancelled_by');
            }
            foreach (['cancelled_at', 'cancellation_reason'] as $column) {
                if (Schema::hasColumn('inventory_transfers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
