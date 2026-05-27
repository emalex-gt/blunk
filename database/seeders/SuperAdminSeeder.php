<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'development', 'testing'])) {
            return;
        }

        $user = User::query()->updateOrCreate(
            ['email' => 'contacto@maniaestudio.com'],
            [
                'business_id' => null,
                'name' => 'Alejandro Lopez',
                'password' => Hash::make('123456'),
                'role' => 'super_admin',
                'is_super_admin' => true,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        Permissions::assignRole($user, 'super_admin');
    }
}
