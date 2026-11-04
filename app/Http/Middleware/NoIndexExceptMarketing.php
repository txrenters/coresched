<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NoIndexExceptMarketing
{
    /**
     * The public marketing pages: the only routes search engines may index.
     * The sitemap lists exactly these.
     *
     * @var list<string>
     */
    public const INDEXABLE_ROUTES = ['home', 'pricing', 'terms', 'privacy'];

    /**
     * Keep every response except the marketing pages out of search indexes.
     *
     * Booking, cancel and reschedule pages carry a renter's name and meeting
     * time, and everything else sits behind a login. This is a header rather
     * than a robots.txt Disallow on purpose: a crawler that is blocked from a
     * URL never sees its noindex, and can still list the bare URL if it finds
     * a link to it elsewhere.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->routeIs(...self::INDEXABLE_ROUTES)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
