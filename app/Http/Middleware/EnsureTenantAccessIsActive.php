<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAccessIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->is_super_admin) {
            return $next($request);
        }

        if (! $user->is_active) {
            return Inertia::render('TenantBlocked', [
                'message' => 'Tu usuario está inactivo. Contacta al administrador.',
            ])->toResponse($request)->setStatusCode(403);
        }

        $business = \App\Models\Business::query()
            ->with('latestSubscription')
            ->find(currentBusinessId());

        if (! $business || ! $business->is_active) {
            return Inertia::render('TenantBlocked', [
                'message' => 'Tu cuenta está inactiva. Contacta soporte.',
            ])->toResponse($request)->setStatusCode(403);
        }

        $status = $business->latestSubscription?->status;

        if (in_array($status, ['cancelled', 'expired'], true)) {
            return Inertia::render('TenantBlocked', [
                'message' => 'Tu suscripción no está activa. Contacta soporte.',
            ])->toResponse($request)->setStatusCode(403);
        }

        if ($status === 'paused') {
            return Inertia::render('TenantBlocked', [
                'message' => 'Tu suscripción está pausada. Contacta soporte.',
            ])->toResponse($request)->setStatusCode(403);
        }

        return $next($request);
    }
}
