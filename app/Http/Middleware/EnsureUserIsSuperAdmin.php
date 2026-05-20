<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) $request->user()?->is_super_admin, 403);

        return $next($request);
    }
}
