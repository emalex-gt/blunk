<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_visits', function (Blueprint $table) {
            if (! Schema::hasColumn('route_visits', 'no_sale_reason')) {
                $table->string('no_sale_reason')->nullable()->after('status');
            }

            if (! Schema::hasColumn('route_visits', 'no_sale_note')) {
                $table->text('no_sale_note')->nullable()->after('no_sale_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('route_visits', function (Blueprint $table) {
            if (Schema::hasColumn('route_visits', 'no_sale_note')) {
                $table->dropColumn('no_sale_note');
            }

            if (Schema::hasColumn('route_visits', 'no_sale_reason')) {
                $table->dropColumn('no_sale_reason');
            }
        });
    }
};
