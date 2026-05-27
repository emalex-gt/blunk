<?php

namespace App\Http\Middleware;

use App\Support\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $allowSigned = false;
        $required = [];

        foreach ($permissions as $permission) {
            if ($permission === 'signed') {
                $allowSigned = true;
                continue;
            }

            $required[] = $permission;
        }

        if ($allowSigned && $request->hasValidSignature()) {
            return $next($request);
        }

        $user = $request->user();

        abort_unless($user, 403);

        foreach ($required as $permission) {
            abort_unless(Permissions::userHas($user, $permission), 403);
        }

        return $next($request);
    }
}
