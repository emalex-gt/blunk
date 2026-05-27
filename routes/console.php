<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use App\Models\Sale;
use App\Models\TenantFelSetting;
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

Artisan::command('fel:diagnose-speed {sale_id?}', function (?string $sale_id = null) {
    $query = Sale::query()
        ->where('document_type', 'invoice')
        ->with(['electronicDocument', 'felCertificationAttempts' => fn ($builder) => $builder->latest('id')]);

    $sale = $sale_id
        ? $query->find($sale_id)
        : $query->latest('id')->first();

    if (! $sale) {
        $this->error('No se encontró una venta FEL para diagnosticar.');

        return self::FAILURE;
    }

    $settings = TenantFelSetting::query()->where('business_id', $sale->business_id)->first();
    $attempt = $sale->felCertificationAttempts->first();
    $tokenExpiresAt = $settings?->token_expires_at;
    $tokenCached = filled($settings?->token) && $tokenExpiresAt?->gt(now()->addMinutes(2));

    $this->info("Diagnóstico FEL venta #{$sale->id}");
    $this->table(['Dato', 'Valor'], [
        ['business_id', (string) $sale->business_id],
        ['estado venta FEL', (string) ($sale->certification_status ?: $sale->fel_status ?: '-')],
        ['ambiente', (string) ($settings?->environment ?: '-')],
        ['base_url activa', (string) ($settings?->baseUrl() ?: '-')],
        ['token en cache vigente', $tokenCached ? 'sí' : 'no'],
        ['token expira', $tokenExpiresAt?->toIso8601String() ?: '-'],
        ['intento', $attempt ? '#'.$attempt->id.' / '.$attempt->status : '-'],
    ]);

    if (! Schema::hasColumn('fel_certification_attempts', 'timings')) {
        $this->warn('Ejecuta las migraciones para habilitar métricas persistidas.');

        return self::SUCCESS;
    }

    if (! $attempt || ! $attempt->timings) {
        $this->warn('La venta no tiene métricas FEL registradas.');

        return self::SUCCESS;
    }

    $this->table(['Métrica', 'Valor'], collect($attempt->timings)
        ->map(fn ($value, $key) => [(string) $key, is_scalar($value) ? (string) $value : json_encode($value)])
        ->values()
        ->all());

    return self::SUCCESS;
})->purpose('Show stored performance timings for the latest or selected FEL sale');
