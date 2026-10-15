<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Teams\SyncHostZones;
use App\Http\Controllers\Api\Concerns\ResolvesBookingPage;
use App\Http\Controllers\Controller;
use App\Models\HostZone;
use App\Models\Team;
use App\Models\User;
use App\Services\Scheduling\BookingPageResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HostZoneController extends Controller
{
    use ResolvesBookingPage;

    public function __construct(
        private BookingPageResolver $pages,
        private SyncHostZones $sync,
    ) {}

    /**
     * Replace the areas one host of an event type takes meetings for.
     *
     * The host is found by email on the event type's rota, like their
     * hours, so a key cannot reach anybody outside the pools it reads. The
     * zones are the caller's own keys (TEXRA's zone slugs); a booking link
     * naming one of them is then offered only to the hosts listing it.
     */
    public function update(Request $request, string $page, string $eventType, string $email): JsonResponse
    {
        $validated = $request->validate([
            'zones' => ['present', 'array', 'max:50'],
            'zones.*' => ['string', 'distinct', 'regex:'.HostZone::PATTERN],
        ]);

        $owner = $this->pageForKey($request, $this->pages, $page);
        $type = $this->pages->eventType($owner, $eventType);

        abort_if($type === null, 404);

        $wanted = mb_strtolower(trim($email));
        $host = $type->hostPool()->first(fn (User $host): bool => mb_strtolower($host->email) === $wanted);

        abort_if($host === null, 404);

        $team = $owner instanceof Team ? $owner : $request->attributes->get('api_key')?->team;

        abort_if($team === null, 404);

        return response()->json([
            'zones' => $this->sync->handle($team, $host, $validated['zones']),
        ]);
    }
}
