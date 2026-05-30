<?php

use App\Models\Branch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            if (! Schema::hasColumn('purchases', 'payment_method')) {
                $table->string('payment_method')->nullable()->after('paid_from_cash');
            }
        });

        foreach (['cash_register_sessions', 'cash_movements', 'cash_expenses'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'branch_id')) {
                    $table->foreignId('branch_id')
                        ->nullable()
                        ->after('business_id')
                        ->constrained('branches')
                        ->nullOnDelete();
                    $table->index(['business_id', 'branch_id']);
                }
            });
        }

        DB::table('businesses')->orderBy('id')->pluck('id')->each(function (int $businessId) {
            $branch = Branch::query()->firstOrCreate(
                ['business_id' => $businessId, 'code' => 'MAIN'],
                ['name' => 'Principal', 'is_active' => true],
            );

            DB::table('users')
                ->where('business_id', $businessId)
                ->where(function ($query) {
                    $query->whereNull('is_super_admin')->orWhere('is_super_admin', false);
                })
                ->whereNull('current_branch_id')
                ->update(['current_branch_id' => $branch->id, 'updated_at' => now()]);

            DB::table('cash_register_sessions')
                ->where('business_id', $businessId)
                ->whereNull('branch_id')
                ->update(['branch_id' => $branch->id, 'updated_at' => now()]);

            DB::table('cash_movements')
                ->where('business_id', $businessId)
                ->whereNull('branch_id')
                ->update(['branch_id' => $branch->id, 'updated_at' => now()]);

            DB::table('cash_expenses')
                ->where('business_id', $businessId)
                ->whereNull('branch_id')
                ->update(['branch_id' => $branch->id, 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        foreach (['cash_expenses', 'cash_movements', 'cash_register_sessions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'branch_id')) {
                    $table->dropConstrainedForeignId('branch_id');
                }
            });
        }

        Schema::table('purchases', function (Blueprint $table) {
            if (Schema::hasColumn('purchases', 'payment_method')) {
                $table->dropColumn('payment_method');
            }
        });
    }
};
