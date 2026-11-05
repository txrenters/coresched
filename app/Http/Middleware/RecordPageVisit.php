<?php

namespace App\Http\Middleware;

use App\Models\PageVisit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use function Illuminate\Support\defer;

/**
 * Count a view of a public marketing page for the admin dashboard.
 *
 * Server side, so there is no tracking script, no cookie and nothing an ad
 * blocker strips. Inertia's client-side navigation between marketing pages
 * still reaches the server, so those views count too; prefetches, partial
 * reloads, bots and signed-in people do not. The insert runs after the
 * response is sent.
 */
class RecordPageVisit
{
    /**
     * User agents that are not people: crawlers, link previews, uptime checks
     * and command-line clients.
     */
    protected const BOT_PATTERN = '/bot|crawl|spider|slurp|preview|facebookexternalhit|monitor|uptime|lighthouse|headless|curl|wget|python|httpclient|go-http|java\//i';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->shouldRecord($request, $response)) {
            $visit = $this->visitFrom($request);

            defer(fn () => PageVisit::query()->create($visit));
        }

        return $response;
    }

    protected function shouldRecord(Request $request, Response $response): bool
    {
        $userAgent = (string) $request->userAgent();

        return $request->isMethod('GET')
            && $response->getStatusCode() === 200
            && $request->user() === null
            && ! $request->prefetch()
            && $request->header('Sec-Purpose') === null
            && ! $request->hasHeader('X-Inertia-Partial-Component')
            && $userAgent !== ''
            && preg_match(self::BOT_PATTERN, $userAgent) !== 1;
    }

    /**
     * @return array{path: string, referrer_host: string|null, visitor_hash: string, device: string}
     */
    protected function visitFrom(Request $request): array
    {
        $userAgent = (string) $request->userAgent();

        return [
            'path' => '/'.ltrim($request->path(), '/'),
            'referrer_host' => $this->externalReferrerHost($request),
            'visitor_hash' => hash('sha256', implode('|', [
                now()->toDateString(),
                config('app.key'),
                $request->ip(),
                $userAgent,
            ])),
            'device' => match (true) {
                preg_match('/ipad|tablet/i', $userAgent) === 1 => 'tablet',
                preg_match('/mobi|android|iphone/i', $userAgent) === 1 => 'mobile',
                default => 'desktop',
            },
        ];
    }

    /**
     * The site the visitor came from, or null for direct visits and clicks
     * within this site.
     */
    protected function externalReferrerHost(Request $request): ?string
    {
        $host = parse_url((string) $request->header('Referer'), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower((string) preg_replace('/^www\./i', '', $host));

        return $host === strtolower((string) preg_replace('/^www\./i', '', $request->getHost())) ? null : $host;
    }
}
