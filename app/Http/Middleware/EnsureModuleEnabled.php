<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module)
    {
        abort_unless(
            module_enabled($module),
            403,
            'Este modulo no esta habilitado para esta empresa.',
        );

        return $next($request);
    }
}
