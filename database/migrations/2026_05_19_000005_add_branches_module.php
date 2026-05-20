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
        if (! Schema::hasTable('branches')) {
            Schema::create('branches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('code')->nullable();
                $table->string('address')->nullable();
                $table->string('phone')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['business_id', 'is_active']);
                $table->index(['business_id', 'name']);
            });
        }

        Schema::table('tenant_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_settings', 'use_branches')) {
                $table->boolean('use_branches')->default(false);
            }

            if (! Schema::hasColumn('tenant_settings', 'products_shared_across_branches')) {
                $table->boolean('products_shared_across_branches')->default(true);
            }

            if (! Schema::hasColumn('tenant_settings', 'pricing_scope')) {
                $table->string('pricing_scope')->default('global');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'current_branch_id')) {
                $table->foreignId('current_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            }
        });

        Schema::create('product_branch_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('stock', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'branch_id', 'product_id']);
            $table->index(['business_id', 'product_id']);
        });

        Schema::create('product_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'product_id', 'branch_id']);
        });

        Schema::create('branch_product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('price_type_id')->nullable();
            $table->decimal('price', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'branch_id', 'product_id', 'price_type_id']);
            $table->index(['business_id', 'product_id']);
        });

        if (Schema::hasTable('product_variant_prices')) {
            Schema::create('branch_product_variant_prices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('product_variant_id');
                $table->unsignedBigInteger('price_type_id')->nullable();
                $table->decimal('price', 12, 2);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['business_id', 'branch_id', 'product_variant_id', 'price_type_id'], 'branch_variant_price_unique');
                $table->index(['business_id', 'product_variant_id']);
            });
        }

        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('status')->default('completed');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'created_at']);
            $table->index(['business_id', 'from_branch_id']);
            $table->index(['business_id', 'to_branch_id']);
        });

        Schema::create('inventory_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->integer('quantity');
            $table->timestamps();

            $table->index(['business_id', 'inventory_transfer_id']);
            $table->index(['business_id', 'product_id']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_movements', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()->after('business_id')->constrained('branches')->nullOnDelete();
                $table->index(['business_id', 'branch_id']);
            }
        });

        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()->after('business_id')->constrained('branches')->nullOnDelete();
                $table->index(['business_id', 'branch_id']);
            }
        });

        Schema::table('purchases', function (Blueprint $table) {
            if (! Schema::hasColumn('purchases', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()->after('business_id')->constrained('branches')->nullOnDelete();
                $table->index(['business_id', 'branch_id']);
            }
        });

        $this->seedDefaultBranches();
        $this->seedModuleRows();
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            if (Schema::hasColumn('purchases', 'branch_id')) {
                $table->dropConstrainedForeignId('branch_id');
            }
        });

        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'branch_id')) {
                $table->dropConstrainedForeignId('branch_id');
            }
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            if (Schema::hasColumn('stock_movements', 'branch_id')) {
                $table->dropConstrainedForeignId('branch_id');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'current_branch_id')) {
                $table->dropConstrainedForeignId('current_branch_id');
            }
        });

        Schema::table('tenant_settings', function (Blueprint $table) {
            foreach (['use_branches', 'products_shared_across_branches', 'pricing_scope'] as $column) {
                if (Schema::hasColumn('tenant_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('inventory_transfer_lines');
        Schema::dropIfExists('inventory_transfers');
        Schema::dropIfExists('branch_product_variant_prices');
        Schema::dropIfExists('branch_product_prices');
        Schema::dropIfExists('product_branches');
        Schema::dropIfExists('product_branch_stocks');
        Schema::dropIfExists('branches');
    }

    private function seedDefaultBranches(): void
    {
        if (! Schema::hasTable('businesses')) {
            return;
        }

        Business::query()->select('id')->chunkById(100, function ($businesses) {
            foreach ($businesses as $business) {
                $branchId = DB::table('branches')
                    ->where('business_id', $business->id)
                    ->where('code', 'MAIN')
                    ->value('id');

                if (! $branchId) {
                    $branchId = DB::table('branches')->insertGetId([
                        'business_id' => $business->id,
                        'name' => 'Principal',
                        'code' => 'MAIN',
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('products')
                    ->where('business_id', $business->id)
                    ->orderBy('id')
                    ->select('id', 'stock')
                    ->chunkById(500, function ($products) use ($business, $branchId) {
                        foreach ($products as $product) {
                            DB::table('product_branch_stocks')->updateOrInsert(
                                [
                                    'business_id' => $business->id,
                                    'branch_id' => $branchId,
                                    'product_id' => $product->id,
                                ],
                                [
                                    'stock' => $product->stock ?? 0,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ],
                            );
                        }
                    });

                DB::table('sales')
                    ->where('business_id', $business->id)
                    ->whereNull('branch_id')
                    ->update(['branch_id' => $branchId]);

                DB::table('purchases')
                    ->where('business_id', $business->id)
                    ->whereNull('branch_id')
                    ->update(['branch_id' => $branchId]);

                DB::table('stock_movements')
                    ->where('business_id', $business->id)
                    ->whereNull('branch_id')
                    ->update(['branch_id' => $branchId]);
            }
        });
    }

    private function seedModuleRows(): void
    {
        if (! Schema::hasTable('tenant_modules')) {
            return;
        }

        Business::query()->select('id')->chunkById(100, function ($businesses) {
            foreach ($businesses as $business) {
                DB::table('tenant_modules')->updateOrInsert(
                    ['business_id' => $business->id, 'module' => 'branches'],
                    [
                        'is_enabled' => false,
                        'enabled_at' => null,
                        'disabled_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        });
    }
};
