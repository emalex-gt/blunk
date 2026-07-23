<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('route_work_days')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE route_work_days DROP CONSTRAINT IF EXISTS route_work_days_unique_day');
            DB::statement('CREATE INDEX IF NOT EXISTS route_work_days_start_lookup_index ON route_work_days (business_id, branch_id, route_zone_id, seller_id, status)');
            $this->closeDuplicateOpenWorkDays();
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS route_work_days_unique_open ON route_work_days (business_id, branch_id, route_zone_id, seller_id) WHERE status = 'open'");

            return;
        }

        if ($driver === 'sqlite') {
            try {
                Schema::table('route_work_days', fn ($table) => $table->dropUnique('route_work_days_unique_day'));
            } catch (Throwable) {
                DB::statement('DROP INDEX IF EXISTS route_work_days_unique_day');
            }

            DB::statement('CREATE INDEX IF NOT EXISTS route_work_days_start_lookup_index ON route_work_days (business_id, branch_id, route_zone_id, seller_id, status)');
            $this->closeDuplicateOpenWorkDays();
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS route_work_days_unique_open ON route_work_days (business_id, branch_id, route_zone_id, seller_id) WHERE status = 'open'");

            return;
        }

        try {
            Schema::table('route_work_days', fn ($table) => $table->dropUnique('route_work_days_unique_day'));
        } catch (Throwable) {
            // Legacy installs may already have this constraint removed.
        }

        $this->closeDuplicateOpenWorkDays();

        try {
            Schema::table('route_work_days', function ($table) {
                $table->index(['business_id', 'branch_id', 'route_zone_id', 'seller_id', 'status'], 'route_work_days_start_lookup_index');
            });
        } catch (Throwable) {
            // Legacy installs may already have this index from a partial migration run.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('route_work_days')) {
            return;
        }

        $driver = DB::getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS route_work_days_unique_open');
            DB::statement('DROP INDEX IF EXISTS route_work_days_start_lookup_index');

            return;
        }

        try {
            Schema::table('route_work_days', fn ($table) => $table->dropIndex('route_work_days_start_lookup_index'));
        } catch (Throwable) {
            // Ignore missing indexes on legacy installs.
        }
    }

    private function closeDuplicateOpenWorkDays(): void
    {
        if (
            ! Schema::hasColumn('route_work_days', 'status') ||
            ! Schema::hasColumn('route_work_days', 'business_id') ||
            ! Schema::hasColumn('route_work_days', 'branch_id') ||
            ! Schema::hasColumn('route_work_days', 'route_zone_id') ||
            ! Schema::hasColumn('route_work_days', 'seller_id')
        ) {
            return;
        }

        $groups = DB::table('route_work_days')
            ->select('business_id', 'branch_id', 'route_zone_id', 'seller_id')
            ->where('status', 'open')
            ->groupBy('business_id', 'branch_id', 'route_zone_id', 'seller_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $hasClosedAt = Schema::hasColumn('route_work_days', 'closed_at');

        foreach ($groups as $group) {
            $duplicateIds = DB::table('route_work_days')
                ->where('business_id', $group->business_id)
                ->where('branch_id', $group->branch_id)
                ->where('route_zone_id', $group->route_zone_id)
                ->where('seller_id', $group->seller_id)
                ->where('status', 'open')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->pluck('id')
                ->skip(1)
                ->values();

            if ($duplicateIds->isEmpty()) {
                continue;
            }

            $updates = [
                'status' => 'closed',
                'updated_at' => now(),
            ];

            if ($hasClosedAt) {
                $updates['closed_at'] = now();
            }

            DB::table('route_work_days')
                ->whereIn('id', $duplicateIds)
                ->update($updates);
        }
    }
};
