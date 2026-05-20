<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_fel_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_fel_settings', 'issuer_tax_id')) {
                $table->string('issuer_tax_id')->nullable()->after('enabled');
            }

            if (! Schema::hasColumn('tenant_fel_settings', 'token_expires_at')) {
                $table->timestamp('token_expires_at')->nullable()->after('token');
            }

            if (! Schema::hasColumn('tenant_fel_settings', 'certifier_tax_id')) {
                $table->string('certifier_tax_id')->nullable()->after('phrase_scenario');
            }
        });

        Schema::table('electronic_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('electronic_documents', 'xml_base64')) {
                $table->longText('xml_base64')->nullable()->after('response_payload');
            }

            if (! Schema::hasColumn('electronic_documents', 'pdf_base64')) {
                $table->longText('pdf_base64')->nullable()->after('xml_base64');
            }

            if (! Schema::hasColumn('electronic_documents', 'html')) {
                $table->longText('html')->nullable()->after('pdf_base64');
            }
        });

        DB::table('tenant_fel_settings')
            ->whereNull('test_base_url')
            ->update(['test_base_url' => 'https://testnucgt.digifact.com/api']);

        DB::table('tenant_fel_settings')
            ->whereNull('production_base_url')
            ->update(['production_base_url' => 'https://nucgt.digifact.com/gt.com.apinuc/api']);
    }

    public function down(): void
    {
        Schema::table('electronic_documents', function (Blueprint $table) {
            foreach (['html', 'pdf_base64', 'xml_base64'] as $column) {
                if (Schema::hasColumn('electronic_documents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('tenant_fel_settings', function (Blueprint $table) {
            foreach (['certifier_tax_id', 'token_expires_at', 'issuer_tax_id'] as $column) {
                if (Schema::hasColumn('tenant_fel_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
