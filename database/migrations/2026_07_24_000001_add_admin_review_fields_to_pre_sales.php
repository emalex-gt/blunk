<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_sales', function (Blueprint $table) {
            if (! Schema::hasColumn('pre_sales', 'processing_started_at')) {
                $table->timestamp('processing_started_at')->nullable()->after('submitted_at');
            }

            if (! Schema::hasColumn('pre_sales', 'processing_user_id')) {
                $table->foreignId('processing_user_id')->nullable()->after('processing_started_at')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('pre_sales', 'cancelled_by')) {
                $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('pre_sales', 'cancellation_reason')) {
                $table->string('cancellation_reason')->nullable()->after('cancelled_by');
            }

            if (! Schema::hasColumn('pre_sales', 'cancellation_note')) {
                $table->text('cancellation_note')->nullable()->after('cancellation_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pre_sales', function (Blueprint $table) {
            if (Schema::hasColumn('pre_sales', 'cancellation_note')) {
                $table->dropColumn('cancellation_note');
            }

            if (Schema::hasColumn('pre_sales', 'cancellation_reason')) {
                $table->dropColumn('cancellation_reason');
            }

            if (Schema::hasColumn('pre_sales', 'cancelled_by')) {
                $table->dropConstrainedForeignId('cancelled_by');
            }

            if (Schema::hasColumn('pre_sales', 'processing_user_id')) {
                $table->dropConstrainedForeignId('processing_user_id');
            }

            if (Schema::hasColumn('pre_sales', 'processing_started_at')) {
                $table->dropColumn('processing_started_at');
            }
        });
    }
};
