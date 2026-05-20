<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('electronic_documents')) {
            Schema::create('electronic_documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('sale_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('provider')->default('digifact');
                $table->string('environment')->default('test');
                $table->string('document_type');
                $table->string('status')->default('pending');
                $table->string('uuid')->nullable();
                $table->string('series')->nullable();
                $table->string('number')->nullable();
                $table->timestamp('certification_date')->nullable();
                $table->jsonb('request_payload')->nullable();
                $table->jsonb('response_payload')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->jsonb('cancellation_request_payload')->nullable();
                $table->jsonb('cancellation_response_payload')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['business_id', 'status']);
                $table->index(['business_id', 'created_at']);
                $table->index(['provider', 'environment']);
            });
        }

        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'electronic_document_id')) {
                $table->foreignId('electronic_document_id')->nullable()->after('document_type')->constrained('electronic_documents')->nullOnDelete();
            }

            if (! Schema::hasColumn('sales', 'certification_status')) {
                $table->string('certification_status')->nullable()->after('electronic_document_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'electronic_document_id')) {
                $table->dropConstrainedForeignId('electronic_document_id');
            }

            if (Schema::hasColumn('sales', 'certification_status')) {
                $table->dropColumn('certification_status');
            }
        });

        Schema::dropIfExists('electronic_documents');
    }
};
