<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_fel_settings')) {
            Schema::create('tenant_fel_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('provider')->default('digifact');
                $table->string('environment')->default('test');
                $table->boolean('enabled')->default(false);
                $table->string('username')->nullable();
                $table->text('password')->nullable();
                $table->text('token')->nullable();
                $table->string('test_base_url')->nullable();
                $table->string('production_base_url')->nullable();
                $table->string('establishment_code')->nullable();
                $table->string('establishment_name')->nullable();
                $table->string('affiliate_type')->nullable();
                $table->string('phrase_type')->nullable();
                $table->string('phrase_scenario')->nullable();
                $table->string('certificate_path')->nullable();
                $table->text('certificate_password')->nullable();
                $table->timestamp('last_successful_connection_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->index('provider');
                $table->index('environment');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_fel_settings');
    }
};
