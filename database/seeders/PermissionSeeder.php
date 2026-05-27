<?php

namespace Database\Seeders;

use App\Support\Permissions;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        Permissions::syncDefaults();
    }
}
