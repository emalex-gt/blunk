<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_tax_lookups')) {
            return;
        }

        Schema::create('customer_tax_lookups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('country', 2);
            $table->string('doc_type', 20);
            $table->string('doc_number');
            $table->string('name');
            $table->string('provider');
            $table->jsonb('raw_response')->nullable();
            $table->timestamp('last_lookup_at');
            $table->timestamps();

            $table->unique(
                ['business_id', 'country', 'doc_type', 'doc_number'],
                'customer_tax_lookups_business_doc_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_tax_lookups');
    }
};
