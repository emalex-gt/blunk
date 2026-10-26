<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('quotation_id')->nullable()->after('branch_id')->constrained()->nullOnDelete();
            $table->unique('quotation_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['quotation_id']);
            $table->dropConstrainedForeignId('quotation_id');
        });
    }
};
