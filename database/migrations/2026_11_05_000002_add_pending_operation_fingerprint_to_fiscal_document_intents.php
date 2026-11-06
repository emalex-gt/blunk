<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_document_intents', function (Blueprint $table) {
            $table->string('operation_fingerprint', 64)->nullable()->index();
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX fiscal_document_intents_pending_operation_unique
            ON fiscal_document_intents (business_id, COALESCE(created_by, 0), operation_type, operation_fingerprint)
            WHERE operation_fingerprint IS NOT NULL
              AND status IN ('pending', 'unknown', 'certified', 'retryable')
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS fiscal_document_intents_pending_operation_unique');

        Schema::table('fiscal_document_intents', function (Blueprint $table) {
            $table->dropColumn('operation_fingerprint');
        });
    }
};
