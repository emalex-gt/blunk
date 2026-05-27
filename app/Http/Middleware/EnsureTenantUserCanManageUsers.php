<?php

namespace App\Http\Middleware;

use App\Support\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantUserCanManageUsers
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->is_super_admin || ! Permissions::userHas($user, Permissions::USERS_VIEW)) {
            abort(403);
        }

        return $next($request);
    }
}
