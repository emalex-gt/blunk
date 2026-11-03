<?php

use App\Support\GuatemalaPostalCodes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('guatemala_municipalities')) {
            return;
        }

        if (! Schema::hasColumn('guatemala_municipalities', 'postal_code')) {
            Schema::table('guatemala_municipalities', function (Blueprint $table) {
                $table->char('postal_code', 5)->nullable();
            });
        }

        $this->canonicalizeElQuiche();

        foreach (GuatemalaPostalCodes::confirmed() as $department => $municipalities) {
            $departmentId = DB::table('guatemala_departments')->where('name', $department)->value('id');

            if (! $departmentId) {
                continue;
            }

            foreach ($municipalities as $municipality => $postalCode) {
                DB::table('guatemala_municipalities')
                    ->where('guatemala_department_id', $departmentId)
                    ->where('name', $municipality)
                    ->update(['postal_code' => $postalCode, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('guatemala_municipalities') && Schema::hasColumn('guatemala_municipalities', 'postal_code')) {
            Schema::table('guatemala_municipalities', function (Blueprint $table) {
                $table->dropColumn('postal_code');
            });
        }
    }

    private function canonicalizeElQuiche(): void
    {
        if (! Schema::hasTable('guatemala_departments')) {
            return;
        }

        $canonical = DB::table('guatemala_departments')->where('name', 'El Quiche')->first();
        $aliases = DB::table('guatemala_departments')
            ->whereIn('name', ['Quiché', 'Quiche'])
            ->get();

        if (! $canonical && $aliases->isNotEmpty()) {
            $canonical = $aliases->shift();
            DB::table('guatemala_departments')->where('id', $canonical->id)->update(['name' => 'El Quiche', 'updated_at' => now()]);
        }

        if (! $canonical) {
            return;
        }

        foreach ($aliases as $alias) {
            DB::table('guatemala_municipalities')->where('guatemala_department_id', $alias->id)->orderBy('id')->get()->each(function ($municipality) use ($canonical) {
                $exists = DB::table('guatemala_municipalities')
                    ->where('guatemala_department_id', $canonical->id)
                    ->where('name', $municipality->name)
                    ->exists();

                if ($exists) {
                    throw new \RuntimeException(sprintf(
                        'No se puede consolidar de forma segura el municipio heredado "%s" de "%s"; ya existe bajo "El Quiche".',
                        $municipality->name,
                        $alias->name,
                    ));
                } else {
                    DB::table('guatemala_municipalities')->where('id', $municipality->id)->update(['guatemala_department_id' => $canonical->id, 'updated_at' => now()]);
                }
            });

            DB::table('guatemala_departments')->where('id', $alias->id)->delete();
        }
    }
};
