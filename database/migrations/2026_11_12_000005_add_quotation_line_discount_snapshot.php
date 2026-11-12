<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->decimal('line_discount_amount', 12, 2)->default(0)->after('manual_price');
            $table->text('line_discount_reason')->nullable()->after('line_discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->dropColumn(['line_discount_amount', 'line_discount_reason']);
        });
    }
};
