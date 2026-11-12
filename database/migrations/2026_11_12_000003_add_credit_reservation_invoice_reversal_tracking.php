<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_receipt_line_invoice', function (Blueprint $table): void {
            $table->timestamp('reversed_at')->nullable()->after('amount');
            $table->index(['sale_id', 'reversed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('credit_receipt_line_invoice', function (Blueprint $table): void {
            $table->dropIndex(['sale_id', 'reversed_at']);
            $table->dropColumn('reversed_at');
        });
    }
};
