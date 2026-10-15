<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Teams\EnsureEventTypeHost;
use App\Http\Controllers\Api\Concerns\ResolvesBookingPage;
use App\Http\Controllers\Api\Concerns\SerialisesHostSchedule;
use App\Http\Controllers\Controller;
use App\Models\HostZone;
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
use Illuminate\Validation\Rule;

class HostController extends Controller
{
    use ResolvesBookingPage, SerialisesHostSchedule;

    /** The longest window one request may ask for. */
    private const MAX_DAYS = 31;

    public function __construct(
        private BookingPageResolver $pages,
        private AvailabilityEngine $availability,
        private ScheduleResolver $schedules,
    ) {}

    /**
     * Put somebody on an event type's rota, making their account if needed.
     *
     * The write TEXRA needs before it can save an agent's hours: the agent
     * has to exist here, in the organization, and in the pool. An address
     * that already has an account is used as it is and never duplicated.
     * Answers 201 when an account was made and 200 when it already existed,
     * and in both cases which layer governs the person's hours, so the
     * caller can warn when personal hours would not apply.
     */
    public function store(Request $request, string $page, string $eventType, EnsureEventTypeHost $ensure): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'timezone' => ['nullable', 'string', Rule::in(timezone_identifiers_list())],
        ]);

        $owner = $this->pageForKey($request, $this->pages, $page);
        $type = $this->pages->eventType($owner, $eventType);

        abort_if($type === null, 404);

        $team = $owner instanceof Team ? $owner : $request->attributes->get('api_key')?->team;

        abort_if($team === null, 404);

        ['user' => $host, 'created' => $created] = $ensure->handle(
            $team,
            $type,
            $validated['name'],
            $validated['email'],
            $validated['timezone'] ?? null,
        );

        return response()->json([
            'host' => [
                'name' => $host->name,
                'email' => $host->email,
                'created' => $created,
                'zones' => $this->zonesByHost($team, [$host->id])[$host->id] ?? [],
            ],
            'governs' => $this->schedules->source($type->fresh(['group']), $host),
        ], $created ? 201 : 200);
    }

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
        $zones = $this->zonesByHost($type->team, $hosts->modelKeys());

        return response()->json([
            'data' => $hosts
                ->map(function (User $host) use ($type, $availability, $zones): array {
                    $entry = $availability->get($host->id);

                    return [
                        'name' => $host->name,
                        'email' => $host->email,
                        'schedule' => $this->schedule($this->schedules->resolve($type, $host)),
                        // The areas they cover, as the caller's own keys.
                        'zones' => $zones[$host->id] ?? [],
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
     * The zones of each host in the organization, sorted, keyed by host id.
     *
     * @param  array<int, int>  $hostIds
     * @return array<int, list<string>>
     */
    private function zonesByHost(Team $team, array $hostIds): array
    {
        $zones = [];

        $rows = HostZone::query()
            ->where('team_id', $team->id)
            ->whereIn('user_id', $hostIds)
            ->orderBy('zone')
            ->get(['user_id', 'zone']);

        foreach ($rows as $row) {
            $zones[$row->user_id][] = $row->zone;
        }

        return $zones;
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
}
