<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_expenses', function (Blueprint $table) {
            $table->string('status')->default('active')->after('amount');
            $table->timestamp('cancelled_at')->nullable()->after('status');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->text('cancellation_note')->nullable()->after('cancelled_by');
            $table->index(['cash_register_session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('cash_expenses', function (Blueprint $table) {
            $table->dropIndex(['cash_register_session_id', 'status']);
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['status', 'cancelled_at', 'cancellation_note']);
        });
    }
};
