<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales', 'amount_paid') || ! Schema::hasColumn('sales', 'payment_status')) {
            return;
        }

        DB::table('sales')
            ->where('is_credit_sale', false)
            ->update([
                'payment_status' => 'paid',
                'amount_paid' => DB::raw('total'),
                'credit_balance' => 0,
            ]);
    }

    public function down(): void
    {
        // Historical paid-sale normalization is intentionally not reversed.
    }
};
