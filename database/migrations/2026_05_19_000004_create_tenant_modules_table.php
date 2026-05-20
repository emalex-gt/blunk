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
        Schema::create('tenant_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('module');
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'module']);
            $table->index(['business_id', 'is_enabled']);
        });

        $modules = ['pos', 'inventory', 'purchases', 'cash_register', 'customers', 'reports', 'fel_gt', 'discounts'];
        $now = now();

        Business::query()->select('id')->chunkById(100, function ($businesses) use ($modules, $now) {
            $rows = [];

            foreach ($businesses as $business) {
                foreach ($modules as $module) {
                    $rows[] = [
                        'business_id' => $business->id,
                        'module' => $module,
                        'is_enabled' => true,
                        'enabled_at' => $now,
                        'disabled_at' => null,
                        'created_by' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if ($rows !== []) {
                DB::table('tenant_modules')->insertOrIgnore($rows);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_modules');
    }
};
