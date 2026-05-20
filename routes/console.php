<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\User;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:diagnose-storage', function () {
    $tmpPath = storage_path('app/tmp');
    $currentUser = get_current_user();

    if (function_exists('exec')) {
        $output = [];
        @exec('whoami', $output);
        $currentUser = $output[0] ?? $currentUser;
    }

    $this->line('base_path: '.base_path());
    $this->line('storage_path: '.storage_path());
    $this->line('temp_path: '.$tmpPath);
    $this->line('sys_get_temp_dir: '.sys_get_temp_dir());
    $this->line('is_writable(storage/app/tmp): '.(is_writable(storage_path('app/tmp')) ? 'yes' : 'no'));
    $this->line('is_writable(storage/logs): '.(is_writable(storage_path('logs')) ? 'yes' : 'no'));
    $this->line('is_writable(bootstrap/cache): '.(is_writable(base_path('bootstrap/cache')) ? 'yes' : 'no'));
    $this->line('current_os_user: '.$currentUser);
})->purpose('Diagnose Laravel storage and temp path permissions');

Artisan::command('app:create-super-admin {email?} {--name=} {--password=}', function (?string $email = null) {
    $email ??= $this->ask('Email');
    $name = $this->option('name') ?: $this->ask('Nombre', 'Super Admin');
    $password = $this->option('password') ?: $this->secret('Contraseña');

    if (! $password) {
        $this->error('La contraseña es obligatoria.');

        return self::FAILURE;
    }

    $user = User::updateOrCreate(
        ['email' => $email],
        [
            'business_id' => null,
            'name' => $name,
            'password' => $password,
            'role' => 'super_admin',
            'is_super_admin' => true,
        ],
    );

    $this->info("Super admin listo: {$user->email}");

    return self::SUCCESS;
})->purpose('Create or update a platform super admin user');
