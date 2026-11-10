<?php

namespace App\PortalSso;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePortalEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('portal.enabled'), 404);

        return $next($request);
    }
}
