<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\AvailabilitySlot;
use App\Http\Controllers\Api\Concerns\ResolvesBookingPage;
use App\Http\Controllers\Controller;
use App\Services\Scheduling\AvailabilityEngine;
use App\Services\Scheduling\BookingPageResolver;
use App\Support\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SlotController extends Controller
{
    use ResolvesBookingPage;

    /** The longest window one request may ask for. */
    private const MAX_DAYS = 62;

    public function __construct(
        private BookingPageResolver $pages,
        private AvailabilityEngine $availability,
    ) {}

    /**
     * When an event type can actually be booked.
     *
     * The availability engine is the only thing that knows about working
     * hours, buffers, leave, busy calendars and meeting limits, so this asks
     * it rather than reimplementing any of that.
     */
    public function index(Request $request, string $page, string $eventType): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $owner = $this->pageForKey($request, $this->pages, $page);

        $type = $this->pages->eventType($owner, $eventType);

        abort_if($type === null, 404);

        $from = CarbonImmutable::parse($validated['from'])->startOfDay();
        $to = CarbonImmutable::parse($validated['to'])->endOfDay();

        abort_if($from->diffInDays($to) > self::MAX_DAYS, 422, 'That window is too long.');

        $slots = $this->availability->slots($type, new TimeRange($from->utc(), $to->utc()));

        return response()->json([
            'data' => $slots
                ->map(fn (AvailabilitySlot $slot): array => [
                    'startsAt' => $slot->startsAt->toIso8601String(),
                    'endsAt' => $slot->endsAt->toIso8601String(),
                    'seatsRemaining' => $slot->seatsRemaining,
                ])
                ->values(),
            'meta' => [
                'eventType' => $type->slug,
                'durationMinutes' => $type->duration_minutes,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ]);
    }
}
