<?php

use App\Support\IdempotencyConflictException;
use Illuminate\Auth\Access\AuthorizationException;
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
        $middleware->redirectUsersTo(fn (Request $request) => \App\Support\AuthRedirect::pathFor($request->user()));

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
        $forbiddenResponse = function (Request $request) {
            if ($request->header('X-Inertia') !== 'true' && $request->expectsJson()) {
                return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
            }

            return Inertia::render('Errors/Forbidden', [
                'message' => 'No tienes permiso para acceder a esta sección.',
            ])->toResponse($request)->setStatusCode(403);
        };
        $sessionExpiredResponse = function (Request $request) {
            $message = 'Por seguridad, tu sesión expiró. Inicia sesión nuevamente para continuar.';

            if ($request->header('X-Inertia') === 'true') {
                return Inertia::location(route('login'));
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                    'session_expired' => true,
                ], 419);
            }

            return redirect()->guest(route('login'))->with('status', 'Tu sesión expiró. Inicia sesión nuevamente.');
        };

        $exceptions->render(function (TokenMismatchException $exception, Request $request) use ($sessionExpiredResponse) {
            return $sessionExpiredResponse($request);
        });

        $exceptions->render(function (IdempotencyConflictException $exception, Request $request) {
            if (
                $request->header('X-Inertia') !== 'true'
                || ! $request->routeIs('sales.store', 'credits.receipts.store')
            ) {
                return null;
            }

            $message = $exception->kind === IdempotencyConflictException::PAYLOAD_MISMATCH
                ? 'La venta cambió después del intento anterior. Intenta confirmar nuevamente.'
                : $exception->getMessage();

            return back()->withErrors(['idempotency_key' => $message]);
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) use ($forbiddenResponse) {
            return $forbiddenResponse($request);
        });

        $exceptions->render(function (HttpException $exception, Request $request) use ($sessionExpiredResponse, $forbiddenResponse) {
            if ($exception->getStatusCode() === 403) {
                return $forbiddenResponse($request);
            }
            if ($exception->getStatusCode() !== 419) {
                return null;
            }

            return $sessionExpiredResponse($request);
        });
    })->create();
