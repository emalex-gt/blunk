<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'super.admin' => \App\Http\Middleware\EnsureUserIsSuperAdmin::class,
            'tenant.active' => \App\Http\Middleware\EnsureTenantAccessIsActive::class,
            'tenant.users' => \App\Http\Middleware\EnsureTenantUserCanManageUsers::class,
            'module' => \App\Http\Middleware\EnsureModuleEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
