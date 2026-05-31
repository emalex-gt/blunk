<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branches')) {
            Schema::table('branches', function (Blueprint $table) {
                if (! Schema::hasColumn('branches', 'fel_establishment_code')) {
                    $table->string('fel_establishment_code')->nullable();
                }

                if (! Schema::hasColumn('branches', 'fel_establishment_name')) {
                    $table->string('fel_establishment_name')->nullable();
                }

                if (! Schema::hasColumn('branches', 'fel_address')) {
                    $table->string('fel_address')->nullable();
                }

                if (! Schema::hasColumn('branches', 'fel_postal_code')) {
                    $table->string('fel_postal_code')->nullable();
                }

                if (! Schema::hasColumn('branches', 'fel_municipality')) {
                    $table->string('fel_municipality')->nullable();
                }

                if (! Schema::hasColumn('branches', 'fel_department')) {
                    $table->string('fel_department')->nullable();
                }

                if (! Schema::hasColumn('branches', 'fel_country')) {
                    $table->string('fel_country', 2)->default('GT');
                }
            });

            $this->ensureDefaultBranches();
            $this->backfillBranchFelData();
        }

        if (Schema::hasTable('electronic_documents') && ! Schema::hasColumn('electronic_documents', 'metadata')) {
            Schema::table('electronic_documents', function (Blueprint $table) {
                if (DB::connection()->getDriverName() === 'pgsql') {
                    $table->jsonb('metadata')->nullable();
                } else {
                    $table->json('metadata')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('electronic_documents') && Schema::hasColumn('electronic_documents', 'metadata')) {
            Schema::table('electronic_documents', function (Blueprint $table) {
                $table->dropColumn('metadata');
            });
        }

        if (Schema::hasTable('branches')) {
            Schema::table('branches', function (Blueprint $table) {
                foreach ([
                    'fel_establishment_code',
                    'fel_establishment_name',
                    'fel_address',
                    'fel_postal_code',
                    'fel_municipality',
                    'fel_department',
                    'fel_country',
                ] as $column) {
                    if (Schema::hasColumn('branches', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function ensureDefaultBranches(): void
    {
        if (! Schema::hasTable('businesses')) {
            return;
        }

        DB::table('businesses')
            ->select(['id'])
            ->orderBy('id')
            ->chunkById(100, function ($businesses) {
                foreach ($businesses as $business) {
                    $exists = DB::table('branches')
                        ->where('business_id', $business->id)
                        ->where('code', 'MAIN')
                        ->exists();

                    if (! $exists) {
                        DB::table('branches')->insert([
                            'business_id' => $business->id,
                            'name' => 'Sucursal Principal',
                            'code' => 'MAIN',
                            'is_active' => true,
                            'fel_country' => 'GT',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            });
    }

    private function backfillBranchFelData(): void
    {
        if (! Schema::hasTable('tenant_fel_settings')) {
            return;
        }

        DB::table('tenant_fel_settings')
            ->select([
                'business_id',
                'establishment_code',
                'establishment_name',
                'establishment_address',
                'establishment_postal_code',
                'establishment_municipality',
                'establishment_department',
                'establishment_country',
            ])
            ->orderBy('business_id')
            ->chunk(100, function ($settingsRows) {
                foreach ($settingsRows as $settings) {
                    $branch = DB::table('branches')
                        ->where('business_id', $settings->business_id)
                        ->where('code', 'MAIN')
                        ->first()
                        ?: DB::table('branches')
                            ->where('business_id', $settings->business_id)
                            ->orderByDesc('is_active')
                            ->orderBy('id')
                            ->first();

                    if (! $branch) {
                        continue;
                    }

                    DB::table('branches')
                        ->where('id', $branch->id)
                        ->update([
                            'fel_establishment_code' => $branch->fel_establishment_code ?: $settings->establishment_code,
                            'fel_establishment_name' => $branch->fel_establishment_name ?: $settings->establishment_name,
                            'fel_address' => $branch->fel_address ?: $settings->establishment_address,
                            'fel_postal_code' => $branch->fel_postal_code ?: $settings->establishment_postal_code,
                            'fel_municipality' => $branch->fel_municipality ?: $settings->establishment_municipality,
                            'fel_department' => $branch->fel_department ?: $settings->establishment_department,
                            'fel_country' => $branch->fel_country ?: ($settings->establishment_country ?: 'GT'),
                            'updated_at' => now(),
                        ]);
                }
            });
    }
};
