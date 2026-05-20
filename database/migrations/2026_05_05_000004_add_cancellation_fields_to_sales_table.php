<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'status')) {
                $table->string('status')->default('completed')->after('payment_method');
            }

            if (! Schema::hasColumn('sales', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('created_by');
            }

            if (! Schema::hasColumn('sales', 'cancelled_by')) {
                $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('sales', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable()->after('cancelled_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'cancelled_by')) {
                $table->dropConstrainedForeignId('cancelled_by');
            }

            foreach (['status', 'cancelled_at', 'cancellation_reason'] as $column) {
                if (Schema::hasColumn('sales', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
