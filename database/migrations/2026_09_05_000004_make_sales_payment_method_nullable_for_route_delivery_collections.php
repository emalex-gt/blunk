<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', fn (Blueprint $table) => $table->string('payment_method')->nullable()->change());
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $table) => $table->string('payment_method')->nullable(false)->change());
    }
};
