<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_settings', 'max_users')) {
                $table->unsignedInteger('max_users')->default(1)->after('use_product_images');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('is_super_admin');
            }
        });

        DB::table('tenant_settings')
            ->whereNull('max_users')
            ->update(['max_users' => 1]);
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            if (Schema::hasColumn('tenant_settings', 'max_users')) {
                $table->dropColumn('max_users');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }
};
