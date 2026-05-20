<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->integer('previous_stock')->nullable()->after('quantity');
            $table->integer('new_stock')->nullable()->after('previous_stock');
            $table->foreignId('user_id')
                ->nullable()
                ->after('created_by')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn(['previous_stock', 'new_stock', 'user_id']);
        });
    }
};
