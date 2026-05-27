<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'fel_internal_reference')) {
                $table->string('fel_internal_reference')->nullable()->after('fel_status')->index();
            }
        });

        Schema::table('electronic_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('electronic_documents', 'internal_reference')) {
                $table->string('internal_reference')->nullable()->after('document_type');
                $table->index(['business_id', 'internal_reference']);
            }

            if (! Schema::hasColumn('electronic_documents', 'issued_at')) {
                $table->timestamp('issued_at')->nullable()->after('certification_date');
            }
        });

        if (! Schema::hasTable('fel_certification_attempts')) {
            Schema::create('fel_certification_attempts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
                $table->foreignId('electronic_document_id')->nullable()->constrained('electronic_documents')->nullOnDelete();
                $table->string('provider')->default('digifact');
                $table->string('environment');
                $table->string('internal_reference');
                $table->timestamp('issued_at')->nullable();
                $table->string('status')->default('pending');
                $table->jsonb('request_payload')->nullable();
                $table->jsonb('response_payload')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('started_at');
                $table->timestamp('finished_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['business_id', 'internal_reference']);
                $table->index(['business_id', 'status']);
                $table->index('sale_id');
            });
        }

        if (! Schema::hasTable('fel_incidents')) {
            Schema::create('fel_incidents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
                $table->string('internal_reference');
                $table->string('type')->default('possible_duplicate');
                $table->string('severity')->default('warning');
                $table->string('status')->default('open');
                $table->text('message');
                $table->jsonb('metadata')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'status']);
                $table->index(['business_id', 'internal_reference']);
                $table->index('sale_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fel_incidents');
        Schema::dropIfExists('fel_certification_attempts');

        Schema::table('electronic_documents', function (Blueprint $table) {
            if (Schema::hasColumn('electronic_documents', 'internal_reference')) {
                $table->dropIndex(['business_id', 'internal_reference']);
                $table->dropColumn('internal_reference');
            }

            if (Schema::hasColumn('electronic_documents', 'issued_at')) {
                $table->dropColumn('issued_at');
            }
        });

        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'fel_internal_reference')) {
                $table->dropColumn('fel_internal_reference');
            }
        });
    }
};
