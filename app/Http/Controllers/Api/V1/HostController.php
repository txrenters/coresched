<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ResolvesBookingPage;
use App\Http\Controllers\Controller;
use App\Models\AvailabilityOverride;
use App\Models\AvailabilityRule;
use App\Models\AvailabilitySchedule;
use App\Models\Team;
use App\Models\User;
use App\Services\Scheduling\AvailabilityEngine;
use App\Services\Scheduling\BookingPageResolver;
use App\Services\Scheduling\ScheduleResolver;
use App\Support\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class HostController extends Controller
{
    use ResolvesBookingPage;

    /** The longest window one request may ask for. */
    private const MAX_DAYS = 31;

    /** Date overrides further out than this many are not worth sending. */
    private const MAX_OVERRIDES = 60;

    public function __construct(
        private BookingPageResolver $pages,
        private AvailabilityEngine $availability,
        private ScheduleResolver $schedules,
    ) {}

    /**
     * The hours each host of an event type keeps.
     *
     * Two layers, for two readers. The schedule is what the host typed, in
     * their own timezone, so another application can show it back to them.
     * The windows and free time come from the availability engine, so a
     * coverage view honours overrides, holidays, leave and whichever
     * schedule actually governs this event type without reimplementing any
     * of it. Unlike the slots, a host is listed even with nothing to offer.
     *
     * Per-host hours are not on the public booking pages, so this endpoint
     * is the one place the token guards more than scraping.
     */
    public function index(Request $request, string $page, string $eventType): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'email' => ['nullable', 'string', 'max:255'],
        ]);

        $owner = $this->pageForKey($request, $this->pages, $page);
        $type = $this->pages->eventType($owner, $eventType);

        abort_if($type === null, 404);

        $from = CarbonImmutable::parse($validated['from'])->startOfDay();
        $to = CarbonImmutable::parse($validated['to'])->endOfDay();

        abort_if($from->diffInDays($to) > self::MAX_DAYS, 422, 'That window is too long.');

        $hosts = $type->hostPool();

        if (filled($validated['email'] ?? null)) {
            $email = mb_strtolower(trim($validated['email']));

            $hosts = $hosts
                ->filter(fn (User $host): bool => mb_strtolower($host->email) === $email)
                ->values();
        }

        $window = new TimeRange($from->utc(), $to->utc());
        $availability = $this->availability->hostAvailability($type, $hosts, $window);

        return response()->json([
            'data' => $hosts
                ->map(function (User $host) use ($type, $availability): array {
                    $entry = $availability->get($host->id);

                    return [
                        'name' => $host->name,
                        'email' => $host->email,
                        'schedule' => $this->schedule($this->schedules->resolve($type, $host)),
                        'windows' => $this->ranges($entry?->windows),
                        'free' => $this->ranges($entry?->free),
                    ];
                })
                ->values()
                ->all(),
            'meta' => [
                'eventType' => $type->slug,
                'eventName' => $type->name,
                'kind' => $type->kind->value,
                'durationMinutes' => $type->duration_minutes,
                'pool' => $type->group?->name,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                // Where a host changes their own hours, for a link out.
                'availabilityUrl' => $owner instanceof Team ? url("/{$owner->slug}/availability") : null,
            ],
        ]);
    }

    /**
     * The schedule as its owner set it: wall-clock hours in its own timezone.
     *
     * @return array{name: string, kind: string, timezone: string, weekly: list<array{dayOfWeek: int, startsAt: string, endsAt: string}>, overrides: list<array{date: string, isUnavailable: bool, startsAt: string|null, endsAt: string|null}>}|null
     */
    private function schedule(?AvailabilitySchedule $schedule): ?array
    {
        if ($schedule === null) {
            return null;
        }

        $timezone = $schedule->timezone ?: 'UTC';
        $today = CarbonImmutable::now($timezone)->toDateString();

        return [
            'name' => $schedule->name,
            // Shared hours belong to the organization: the host cannot edit them.
            'kind' => $schedule->isShared() ? 'shared' : 'personal',
            'timezone' => $timezone,
            'weekly' => array_values($schedule->rules
                ->sortBy(fn (AvailabilityRule $rule): string => $rule->day_of_week.$rule->starts_at)
                ->map(fn (AvailabilityRule $rule): array => [
                    'dayOfWeek' => $rule->day_of_week,
                    'startsAt' => $this->clock($rule->starts_at),
                    'endsAt' => $this->clock($rule->ends_at),
                ])
                ->all()),
            'overrides' => array_values($schedule->overrides
                ->filter(fn (AvailabilityOverride $override): bool => $override->date->toDateString() >= $today)
                ->sortBy(fn (AvailabilityOverride $override): string => $override->date->toDateString())
                ->take(self::MAX_OVERRIDES)
                ->map(fn (AvailabilityOverride $override): array => [
                    'date' => $override->date->toDateString(),
                    'isUnavailable' => $override->is_unavailable,
                    'startsAt' => $override->starts_at === null ? null : $this->clock($override->starts_at),
                    'endsAt' => $override->ends_at === null ? null : $this->clock($override->ends_at),
                ])
                ->all()),
        ];
    }

    /**
     * @param  Collection<int, TimeRange>|null  $ranges
     * @return list<array{startsAt: string, endsAt: string}>
     */
    private function ranges(?Collection $ranges): array
    {
        return array_values(($ranges ?? new Collection)
            ->map(fn (TimeRange $range): array => [
                'startsAt' => $range->start->toIso8601String(),
                'endsAt' => $range->end->toIso8601String(),
            ])
            ->all());
    }

    /** "09:00:00" as stored, "09:00" as anybody would read it. */
    private function clock(string $time): string
    {
        return substr($time, 0, 5);
    }
}
