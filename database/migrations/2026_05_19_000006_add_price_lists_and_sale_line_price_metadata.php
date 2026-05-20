<?php

use App\Models\Business;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('price_types')) {
            Schema::create('price_types', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['business_id', 'is_active']);
                $table->index(['business_id', 'name']);
            });
        } else {
            Schema::table('price_types', function (Blueprint $table) {
                if (! Schema::hasColumn('price_types', 'is_default')) {
                    $table->boolean('is_default')->default(false);
                }

                if (! Schema::hasColumn('price_types', 'is_active')) {
                    $table->boolean('is_active')->default(true);
                }
            });
        }

        if (! Schema::hasTable('product_prices')) {
            Schema::create('product_prices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('price_type_id')->constrained('price_types')->cascadeOnDelete();
                $table->decimal('price', 12, 2);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['business_id', 'product_id', 'price_type_id']);
                $table->index(['business_id', 'price_type_id']);
            });
        }

        Schema::table('tenant_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_settings', 'allow_manual_price')) {
                $table->boolean('allow_manual_price')->default(false);
            }

            if (! Schema::hasColumn('tenant_settings', 'remember_last_customer_product_price')) {
                $table->boolean('remember_last_customer_product_price')->default(false);
            }
        });

        Schema::table('sale_items', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_items', 'price_type_id')) {
                $table->foreignId('price_type_id')->nullable()->after('product_id')->constrained('price_types')->nullOnDelete();
            }

            if (! Schema::hasColumn('sale_items', 'price_source')) {
                $table->string('price_source')->nullable()->after('unit_price');
            }

            if (! Schema::hasColumn('sale_items', 'original_price')) {
                $table->decimal('original_price', 12, 2)->nullable()->after('unit_price');
            }

            if (! Schema::hasColumn('sale_items', 'manual_price')) {
                $table->boolean('manual_price')->default(false)->after('price_source');
            }
        });

        $this->seedDefaultPriceTypes();
        $this->createDefaultUniqueIndex();
    }

    public function down(): void
    {
        if (Schema::hasTable('sale_items')) {
            Schema::table('sale_items', function (Blueprint $table) {
                if (Schema::hasColumn('sale_items', 'price_type_id')) {
                    $table->dropConstrainedForeignId('price_type_id');
                }

                foreach (['price_source', 'original_price', 'manual_price'] as $column) {
                    if (Schema::hasColumn('sale_items', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::table('tenant_settings', function (Blueprint $table) {
            foreach (['allow_manual_price', 'remember_last_customer_product_price'] as $column) {
                if (Schema::hasColumn('tenant_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('price_types');
    }

    private function seedDefaultPriceTypes(): void
    {
        Business::query()->select('id')->chunkById(100, function ($businesses) {
            foreach ($businesses as $business) {
                $activeCount = DB::table('price_types')
                    ->where('business_id', $business->id)
                    ->where('is_active', true)
                    ->count();

                if ($activeCount === 0) {
                    DB::table('price_types')->insert([
                        'business_id' => $business->id,
                        'name' => 'General',
                        'is_default' => true,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    continue;
                }

                $defaultExists = DB::table('price_types')
                    ->where('business_id', $business->id)
                    ->where('is_active', true)
                    ->where('is_default', true)
                    ->exists();

                if (! $defaultExists || $activeCount === 1) {
                    $firstActive = DB::table('price_types')
                        ->where('business_id', $business->id)
                        ->where('is_active', true)
                        ->orderBy('id')
                        ->value('id');

                    DB::table('price_types')
                        ->where('business_id', $business->id)
                        ->update(['is_default' => false, 'updated_at' => now()]);

                    DB::table('price_types')
                        ->where('id', $firstActive)
                        ->update(['is_default' => true, 'updated_at' => now()]);
                }
            }
        });
    }

    private function createDefaultUniqueIndex(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS price_types_one_default_active_per_business
             ON price_types (business_id)
             WHERE is_default = true AND is_active = true'
        );
    }
};
