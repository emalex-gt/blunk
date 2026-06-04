<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
            'permission' => \App\Http\Middleware\EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $sessionExpiredResponse = function (Request $request) {
            $message = 'Por seguridad, tu sesión expiró. Inicia sesión nuevamente para continuar.';
            $loginUrl = route('login');

            if ($request->header('X-Inertia') === 'true') {
                $request->session()->flash('error', 'Tu sesión expiró. Inicia sesión nuevamente para continuar.');

                return Inertia::location($loginUrl);
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                    'session_expired' => true,
                ], 419);
            }

            return redirect()->guest($loginUrl)->with('error', 'Tu sesión expiró. Inicia sesión nuevamente para continuar.');
        };

        $exceptions->render(function (TokenMismatchException $exception, Request $request) use ($sessionExpiredResponse) {
            return $sessionExpiredResponse($request);
        });

        $exceptions->render(function (HttpException $exception, Request $request) use ($sessionExpiredResponse) {
            if ($exception->getStatusCode() !== 419) {
                return null;
            }

            return $sessionExpiredResponse($request);
        });
    })->create();
