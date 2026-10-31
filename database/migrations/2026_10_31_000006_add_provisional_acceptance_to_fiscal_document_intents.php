<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_document_intents', function (Blueprint $table) {
            $table->timestamp('provisional_accepted_at')->nullable();
            $table->foreignId('provisional_accepted_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_document_intents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('provisional_accepted_by');
            $table->dropColumn('provisional_accepted_at');
        });
    }
};
