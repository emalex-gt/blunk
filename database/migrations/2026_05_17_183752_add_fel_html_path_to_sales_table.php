<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'fel_html_path')) {
                $table->string('fel_html_path')->nullable()->after('fel_xml_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'fel_html_path')) {
                $table->dropColumn('fel_html_path');
            }
        });
    }
};
