<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE roles DROP CONSTRAINT IF EXISTS roles_business_id_key_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS roles_global_key_unique ON roles (key) WHERE business_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS roles_business_key_unique ON roles (business_id, key) WHERE business_id IS NOT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles') || DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS roles_business_key_unique');
        DB::statement('DROP INDEX IF EXISTS roles_global_key_unique');
        DB::statement('ALTER TABLE roles ADD CONSTRAINT roles_business_id_key_unique UNIQUE (business_id, key)');
    }
};
