<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EventType;
use App\Services\Scheduling\BookingPageResolver;
use Illuminate\Http\JsonResponse;

class EventTypeController extends Controller
{
    public function __construct(private BookingPageResolver $pages) {}

    /**
     * The meetings a booking page offers.
     *
     * Everything here is already public on the booking page itself, so the
     * API adds no exposure: it exists so another application can show the
     * same list in its own interface.
     */
    public function index(string $page): JsonResponse
    {
        $owner = $this->pages->resolve($page);

        abort_if($owner === null, 404);

        return response()->json([
            'data' => $this->pages->eventTypes($owner)
                ->map(fn (EventType $eventType): array => [
                    'slug' => $eventType->slug,
                    'name' => $eventType->name,
                    'description' => $eventType->description,
                    'color' => $eventType->color,
                    'durationMinutes' => $eventType->duration_minutes,
                    'locationType' => $eventType->location_type->value,
                    'locationLabel' => $eventType->location_type->label(),
                    'url' => route('book.event-type', ['page' => $page, 'eventType' => $eventType->slug]),
                ])
                ->values(),
            'meta' => [
                'page' => $page,
                'name' => $this->pages->name($owner),
            ],
        ]);
    }
}
