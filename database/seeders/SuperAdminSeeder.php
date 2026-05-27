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

        foreach ([
            ['email' => 'emalejo2@gmail.com', 'password' => 'ChangeMe123!'],
            ['email' => 'contacto@maniaestudio.com', 'password' => '123456'],
        ] as $account) {
            $user = User::query()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'business_id' => null,
                    'name' => 'Alejandro Lopez',
                    'password' => Hash::make($account['password']),
                    'role' => 'super_admin',
                    'is_super_admin' => true,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ],
            );

            Permissions::assignRole($user, 'super_admin');
        }
    }
}
