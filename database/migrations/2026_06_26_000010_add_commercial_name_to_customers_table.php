<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'commercial_name')) {
                $table->string('commercial_name')->nullable()->after('name');
                $table->index(['business_id', 'commercial_name']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'commercial_name')) {
                $table->dropIndex(['business_id', 'commercial_name']);
                $table->dropColumn('commercial_name');
            }
        });
    }
};
