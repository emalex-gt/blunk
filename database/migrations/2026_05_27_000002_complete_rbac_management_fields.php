<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('roles')) {
            Schema::table('roles', function (Blueprint $table) {
                if (! Schema::hasColumn('roles', 'is_active')) {
                    $table->boolean('is_active')->default(true)->after('is_system');
                }
            });
        }

        if (Schema::hasTable('permissions')) {
            Schema::table('permissions', function (Blueprint $table) {
                if (! Schema::hasColumn('permissions', 'is_system')) {
                    $table->boolean('is_system')->default(false)->after('description');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions') && Schema::hasColumn('permissions', 'is_system')) {
            Schema::table('permissions', function (Blueprint $table) {
                $table->dropColumn('is_system');
            });
        }

        if (Schema::hasTable('roles') && Schema::hasColumn('roles', 'is_active')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropColumn('is_active');
            });
        }
    }
};
