<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fel_certification_attempts')) {
            return;
        }

        Schema::table('fel_certification_attempts', function (Blueprint $table) {
            if (! Schema::hasColumn('fel_certification_attempts', 'timings')) {
                $table->jsonb('timings')->nullable()->after('response_payload');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('fel_certification_attempts')) {
            return;
        }

        Schema::table('fel_certification_attempts', function (Blueprint $table) {
            if (Schema::hasColumn('fel_certification_attempts', 'timings')) {
                $table->dropColumn('timings');
            }
        });
    }
};
