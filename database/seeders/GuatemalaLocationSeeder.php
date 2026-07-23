<?php

namespace Database\Seeders;

use App\Support\GuatemalaLocations;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

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
                DB::table('guatemala_municipalities')->updateOrInsert(
                    [
                        'guatemala_department_id' => $departmentId,
                        'name' => $municipality,
                    ],
                    [
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        }
    }
}
