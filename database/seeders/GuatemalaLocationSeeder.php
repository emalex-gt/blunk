<?php

namespace Database\Seeders;

use App\Support\GuatemalaLocations;
use App\Support\GuatemalaPostalCodes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GuatemalaLocationSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        foreach (GuatemalaLocations::municipalitiesByDepartment() as $department => $municipalities) {
            $departmentId = DB::table('guatemala_departments')
                ->where('name', $department)
                ->value('id');

            if (! $departmentId) {
                $departmentId = DB::table('guatemala_departments')->insertGetId([
                    'name' => $department,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('guatemala_departments')
                    ->where('id', $departmentId)
                    ->update(['name' => $department, 'updated_at' => $now]);
            }

            foreach ($municipalities as $municipality) {
                $values = [
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (Schema::hasColumn('guatemala_municipalities', 'postal_code')) {
                    $values['postal_code'] = GuatemalaPostalCodes::confirmed()[$department][$municipality] ?? null;
                }

                DB::table('guatemala_municipalities')->updateOrInsert(
                    [
                        'guatemala_department_id' => $departmentId,
                        'name' => $municipality,
                    ],
                    $values,
                );
            }
        }
    }
}
