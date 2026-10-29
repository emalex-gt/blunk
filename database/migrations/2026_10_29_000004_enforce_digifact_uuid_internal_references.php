<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'electronic_documents_digifact_uuid_reference_unique';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('electronic_documents')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS electronic_documents_digifact_uuid_reference_unique
            ON electronic_documents (business_id, provider, environment, lower(internal_reference))
            WHERE internal_reference ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }
};
