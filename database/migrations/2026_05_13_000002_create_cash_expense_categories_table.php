<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cash_expense_categories')) {
            Schema::create('cash_expense_categories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['business_id', 'name']);
            });
        }

        Schema::table('cash_expenses', function (Blueprint $table) {
            if (! Schema::hasColumn('cash_expenses', 'cash_expense_category_id')) {
                $table->foreignId('cash_expense_category_id')
                    ->nullable()
                    ->after('cash_register_session_id')
                    ->constrained('cash_expense_categories')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('cash_expenses', function (Blueprint $table) {
            if (Schema::hasColumn('cash_expenses', 'cash_expense_category_id')) {
                $table->dropConstrainedForeignId('cash_expense_category_id');
            }
        });

        Schema::dropIfExists('cash_expense_categories');
    }
};
