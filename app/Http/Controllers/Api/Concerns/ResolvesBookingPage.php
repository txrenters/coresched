<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\ApiKey;
use App\Models\Team;
use App\Models\User;
use App\Services\Scheduling\BookingPageResolver;
use Illuminate\Http\Request;

/**
 * Finds a booking page for an API caller, by slug or by id.
 *
 * The public pages are reached by slug, and the API keeps that so a URL can
 * be copied straight from the browser. But a slug follows the organization's
 * name and changes when somebody renames it, which would quietly break a
 * configured integration — so an id works too, and is what another
 * application should store.
 */
trait ResolvesBookingPage
{
    /**
     * Resolve a page the calling key is allowed to read.
     *
     * A page outside the key's organization is reported as not found rather
     * than forbidden: whether it exists is not this caller's business.
     */
    protected function pageForKey(Request $request, BookingPageResolver $pages, string $page): User|Team
    {
        $resolved = $this->resolvePage($pages, $page);

        abort_if($resolved === null, 404);

        $key = $request->attributes->get('api_key');

        abort_if($key instanceof ApiKey && ! $key->canRead($resolved), 404);

        return $resolved;
    }

    protected function resolvePage(BookingPageResolver $pages, string $page): User|Team|null
    {
        // Slug first, so a numeric slug still means the page it always did.
        $resolved = $pages->resolve($page);

        if ($resolved !== null) {
            return $resolved;
        }

        return ctype_digit($page) ? Team::query()->whereKey((int) $page)->first() : null;
    }
}
