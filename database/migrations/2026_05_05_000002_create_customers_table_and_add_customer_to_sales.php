<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('doc_type')->nullable();
                $table->string('doc_number')->nullable();
                $table->string('tax_condition')->nullable();
                $table->string('address')->nullable();
                $table->string('phone')->nullable();
                $table->string('country', 2)->default('GT');
                $table->timestamps();

                $table->index(['business_id', 'name']);
                $table->index(['business_id', 'doc_number']);
            });
        }

        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'customer_id')) {
                $table->foreignId('customer_id')->nullable()->after('business_id')->constrained('customers')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'customer_id')) {
                $table->dropConstrainedForeignId('customer_id');
            }
        });

        Schema::dropIfExists('customers');
    }
};
