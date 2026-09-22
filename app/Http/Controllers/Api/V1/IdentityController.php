<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentityController extends Controller
{
    /**
     * What the calling key is, and which booking page it speaks for.
     *
     * A key is issued for an organization, so the caller should not have to
     * be told its slug as well: it asks here once and caches the answer. A
     * system-wide key has no organization of its own, so it gets none, and
     * whoever configured it has to say which page they mean.
     */
    public function show(Request $request): JsonResponse
    {
        $key = $request->attributes->get('api_key');

        abort_unless($key instanceof ApiKey, 401);

        $team = $key->team;

        return response()->json([
            'data' => [
                'name' => $key->name,
                'systemWide' => $team === null,
                'organization' => $team === null ? null : [
                    'id' => $team->id,
                    'name' => $team->name,
                    'slug' => $team->slug,
                    // What to pass as {page}; the id survives a rename.
                    'page' => (string) $team->id,
                    'bookingUrl' => $team->slug === null
                        ? null
                        : route('book.page', ['page' => $team->slug]),
                ],
            ],
        ]);
    }
}
