<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_preparation_batches', function (Blueprint $table) {
            $table->jsonb('document_snapshot')->nullable()->after('documents_generated_at');
            $table->unsignedSmallInteger('document_snapshot_version')->nullable()->after('document_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('route_preparation_batches', function (Blueprint $table) {
            $table->dropColumn(['document_snapshot', 'document_snapshot_version']);
        });
    }
};
