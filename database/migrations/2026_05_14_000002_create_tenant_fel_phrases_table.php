<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_fel_phrases')) {
            Schema::create('tenant_fel_phrases', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('tenant_fel_setting_id')->constrained()->cascadeOnDelete();
                $table->string('type_data')->nullable();
                $table->string('type_value')->nullable();
                $table->string('scenario_data')->nullable();
                $table->string('scenario_value')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'tenant_fel_setting_id']);
            });
        }

        if (Schema::hasColumn('tenant_fel_settings', 'phrase_type')
            && Schema::hasColumn('tenant_fel_settings', 'phrase_scenario')
        ) {
            DB::table('tenant_fel_settings')
                ->whereNotNull('phrase_type')
                ->orWhereNotNull('phrase_scenario')
                ->orderBy('id')
                ->get()
                ->each(function ($setting): void {
                    $exists = DB::table('tenant_fel_phrases')
                        ->where('tenant_fel_setting_id', $setting->id)
                        ->exists();

                    if ($exists) {
                        return;
                    }

                    DB::table('tenant_fel_phrases')->insert([
                        'business_id' => $setting->business_id,
                        'tenant_fel_setting_id' => $setting->id,
                        'type_data' => '1',
                        'type_value' => $setting->phrase_type ?: '1',
                        'scenario_data' => '1',
                        'scenario_value' => $setting->phrase_scenario ?: '1',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_fel_phrases');
    }
};
