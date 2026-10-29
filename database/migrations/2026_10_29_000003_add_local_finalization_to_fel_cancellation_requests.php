<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fel_cancellation_requests', function (Blueprint $table) {
            $table->foreignId('finalized_by')->nullable()->after('requested_by')->constrained('users')->nullOnDelete();
            $table->text('finalization_error')->nullable()->after('last_error');
            $table->timestamp('finalized_at')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('fel_cancellation_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropColumn(['finalization_error', 'finalized_at']);
        });
    }
};
