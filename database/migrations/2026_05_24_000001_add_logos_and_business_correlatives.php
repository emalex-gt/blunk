<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            if (! Schema::hasColumn('businesses', 'logo_url')) {
                $table->string('logo_url')->nullable()->after('email');
            }

            if (! Schema::hasColumn('businesses', 'logo_public_id')) {
                $table->string('logo_public_id')->nullable()->after('logo_url');
            }
        });

        if (Schema::hasTable('branches')) {
            Schema::table('branches', function (Blueprint $table) {
                if (! Schema::hasColumn('branches', 'logo_url')) {
                    $table->string('logo_url')->nullable()->after('phone');
                }

                if (! Schema::hasColumn('branches', 'logo_public_id')) {
                    $table->string('logo_public_id')->nullable()->after('logo_url');
                }
            });
        }

        if (Schema::hasTable('tenant_settings') && Schema::hasColumn('tenant_settings', 'company_logo_url')) {
            DB::table('tenant_settings')
                ->whereNotNull('company_logo_url')
                ->select('business_id', 'company_logo_url', 'company_logo_public_id')
                ->orderBy('business_id')
                ->get()
                ->each(function ($settings) {
                    DB::table('businesses')
                        ->where('id', $settings->business_id)
                        ->whereNull('logo_url')
                        ->update([
                            'logo_url' => $settings->company_logo_url,
                            'logo_public_id' => $settings->company_logo_public_id,
                        ]);
                });
        }

        if (! Schema::hasTable('business_counters')) {
            Schema::create('business_counters', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->string('counter_key');
                $table->unsignedInteger('current_number')->default(0);
                $table->timestamps();

                $table->unique(['business_id', 'counter_key']);
            });
        }

        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'business_number')) {
                $table->unsignedInteger('business_number')->nullable()->after('business_id');
            }
        });

        Schema::table('purchases', function (Blueprint $table) {
            if (! Schema::hasColumn('purchases', 'business_number')) {
                $table->unsignedInteger('business_number')->nullable()->after('business_id');
            }
        });

        $this->backfillBusinessNumbers('sales', 'sales');
        $this->backfillBusinessNumbers('purchases', 'purchases');

        Schema::table('sales', function (Blueprint $table) {
            $table->unique(['business_id', 'business_number'], 'sales_business_number_unique');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->unique(['business_id', 'business_number'], 'purchases_business_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropUnique('purchases_business_number_unique');
            if (Schema::hasColumn('purchases', 'business_number')) {
                $table->dropColumn('business_number');
            }
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique('sales_business_number_unique');
            if (Schema::hasColumn('sales', 'business_number')) {
                $table->dropColumn('business_number');
            }
        });

        Schema::dropIfExists('business_counters');

        if (Schema::hasTable('branches')) {
            Schema::table('branches', function (Blueprint $table) {
                foreach (['logo_public_id', 'logo_url'] as $column) {
                    if (Schema::hasColumn('branches', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::table('businesses', function (Blueprint $table) {
            foreach (['logo_public_id', 'logo_url'] as $column) {
                if (Schema::hasColumn('businesses', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function backfillBusinessNumbers(string $table, string $counterKey): void
    {
        DB::table('businesses')
            ->select('id')
            ->orderBy('id')
            ->get()
            ->each(function ($business) use ($table, $counterKey) {
                $next = (int) DB::table($table)
                    ->where('business_id', $business->id)
                    ->whereNotNull('business_number')
                    ->max('business_number');

                DB::table($table)
                    ->where('business_id', $business->id)
                    ->whereNull('business_number')
                    ->orderBy('id')
                    ->select('id')
                    ->get()
                    ->each(function ($row) use ($table, &$next) {
                        $next++;
                        DB::table($table)->where('id', $row->id)->update(['business_number' => $next]);
                    });

                DB::table('business_counters')->updateOrInsert(
                    ['business_id' => $business->id, 'counter_key' => $counterKey],
                    [
                        'current_number' => $next,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            });
    }
};
