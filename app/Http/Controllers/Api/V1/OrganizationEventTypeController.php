<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventTypeKind;
use App\Http\Controllers\Api\Concerns\ResolvesBookingPage;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\EventType;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationEventTypeController extends Controller
{
    use ResolvesBookingPage;

    /**
     * Every meeting an organization offers, across all of its booking pages.
     *
     * An organization does not keep its meetings in one place: shared ones sit
     * on the organization's page and a person's own sit on theirs, so anybody
     * asking a single page sees only part of what is on offer. Somewhere like
     * a client portal wants the whole list, with each meeting carrying the
     * page to book it on.
     */
    public function index(Request $request, string $organization): JsonResponse
    {
        // Teams bind by slug, and an id is what survives a rename, so both
        // are accepted here as they are for a booking page.
        $organization = ctype_digit($organization)
            ? Team::query()->whereKey((int) $organization)->first()
            : Team::query()->where('slug', $organization)->first();

        abort_if($organization === null, 404);

        $key = $request->attributes->get('api_key');

        abort_if($key instanceof ApiKey && ! $key->canRead($organization), 404);

        $eventTypes = EventType::query()
            ->bookable()
            ->with(['owner', 'team'])
            ->where('team_id', $organization->id)
            ->where('is_hidden', false)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $eventTypes
                ->map(function (EventType $eventType) use ($organization): ?array {
                    // Shared meetings are advertised on the organization's
                    // page; a person's own are advertised on theirs.
                    $page = in_array($eventType->kind, EventTypeKind::shared(), true)
                        ? $organization->slug
                        : $eventType->owner?->booking_slug;

                    if (blank($page)) {
                        return null;
                    }

                    return [
                        'slug' => $eventType->slug,
                        'page' => $page,
                        'name' => $eventType->name,
                        'description' => $eventType->description,
                        'color' => $eventType->color,
                        'durationMinutes' => $eventType->duration_minutes,
                        'locationType' => $eventType->location_type->value,
                        'locationLabel' => $eventType->location_type->label(),
                        'host' => $eventType->owner?->name,
                        'url' => route('book.event-type', [
                            'page' => $page,
                            'eventType' => $eventType->slug,
                        ]),
                    ];
                })
                ->filter()
                ->values(),
            'meta' => [
                'organization' => $organization->name,
            ],
        ]);
    }
}
