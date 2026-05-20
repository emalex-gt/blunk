<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'fel_pdf_url')) {
                $table->string('fel_pdf_url')->nullable()->after('fel_xml_path');
            }

            if (! Schema::hasColumn('sales', 'fel_pdf_path')) {
                $table->string('fel_pdf_path')->nullable()->after('fel_pdf_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            foreach (['fel_pdf_path', 'fel_pdf_url'] as $column) {
                if (Schema::hasColumn('sales', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
