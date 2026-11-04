<?php

namespace App\Services\Scheduling;

use App\Data\AvailabilitySlot;
use App\Data\HostAvailability;
use App\Enums\DateRangeType;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use App\Support\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AvailabilityEngine
{
    public function __construct(
        protected ScheduleResolver $scheduleResolver,
        protected WorkingHoursCalculator $workingHours,
        protected BusyTimeRepository $busyTimes,
        protected HostRestrictions $restrictions,
    ) {
        //
    }

    /**
     * Get every bookable slot for an event type inside the requested window.
     *
     * A zone narrows the hosts to those who cover it (see
     * EventType::schedulingHosts), so every slot returned can be taken by
     * someone who works where the invitee is.
     *
     * @return Collection<int, AvailabilitySlot>
     */
    public function slots(EventType $eventType, TimeRange $requested, ?int $ignoringBookingId = null, ?string $zone = null): Collection
    {
        $window = $this->bookableWindow($eventType)?->intersect($requested);

        if ($window === null || $window->isEmpty()) {
            return new Collection;
        }

        $hosts = $eventType->schedulingHosts($zone);

        if ($hosts->isEmpty()) {
            return new Collection;
        }

        // Working hours are expanded from the start of the day so that slot
        // start times stay anchored to the schedule's own grid, rather than to
        // whatever ragged moment the minimum notice happens to fall on.
        $expanded = new TimeRange($window->start->startOfDay(), $window->end);

        $availability = $this->freeRangesByHost($eventType, $hosts, $expanded, $ignoringBookingId);

        if ($availability->isEmpty()) {
            return new Collection;
        }

        $freeByHost = $availability->map(fn (HostAvailability $entry) => $entry->free);
        $anchors = TimeRange::merge(
            $availability->reduce(
                fn (Collection $carry, HostAvailability $entry) => $carry->merge($entry->windows),
                new Collection,
            )
        );

        $slots = $eventType->kind->requiresEveryHost()
            ? $this->collectiveSlots($eventType, $freeByHost, $window, $anchors, $hosts->count())
            : $this->pooledSlots($eventType, $freeByHost, $window, $anchors);

        $slots = $this->applyHostLimits($eventType, $slots, $expanded, $ignoringBookingId);

        if ($eventType->kind->allowsMultipleInvitees()) {
            $slots = $this->withoutOverlappingSessions($eventType, $slots, $ignoringBookingId);
        }

        return $this->applyBookingLimits($eventType, $slots, $ignoringBookingId);
    }

    /**
     * Find the slot matching an exact start time, or null when it is not bookable.
     */
    public function findSlot(EventType $eventType, CarbonImmutable $startsAt, ?int $ignoringBookingId = null, ?string $zone = null): ?AvailabilitySlot
    {
        $start = $startsAt->utc();
        $window = new TimeRange(
            $start->subDay()->startOfDay(),
            $start->addDays(2)->endOfDay(),
        );

        return $this->slots($eventType, $window, $ignoringBookingId, $zone)
            ->first(fn (AvailabilitySlot $slot) => $slot->startsAt->equalTo($start));
    }

    /**
     * Get the window an event type may be booked in, honouring notice and date range.
     */
    public function bookableWindow(EventType $eventType, ?CarbonImmutable $now = null): ?TimeRange
    {
        $now ??= CarbonImmutable::now('UTC');

        $start = $now->addMinutes($eventType->effectiveMinimumNoticeMinutes());

        $end = match ($eventType->date_range_type) {
            DateRangeType::RollingDays => $now->addDays(max(1, $eventType->rolling_days))->endOfDay(),
            DateRangeType::FixedRange => $eventType->range_ends_on !== null
                ? CarbonImmutable::parse($eventType->range_ends_on)->endOfDay()
                : $now->addYear(),
            DateRangeType::Indefinite => $now->addYear(),
        };

        if ($eventType->date_range_type === DateRangeType::FixedRange && $eventType->range_starts_on !== null) {
            $start = $start->max(CarbonImmutable::parse($eventType->range_starts_on)->startOfDay());
        }

        $window = new TimeRange($start, $end);

        return $window->isEmpty() ? null : $window;
    }

    /**
     * Work out when each host is free inside the window.
     *
     * @param  Collection<int, User>  $hosts
     * @return Collection<int, HostAvailability> Keyed by user id.
     */
    protected function freeRangesByHost(EventType $eventType, Collection $hosts, TimeRange $window, ?int $ignoringBookingId): Collection
    {
        // A host with no open time has nothing to offer a slot.
        return $this->hostAvailability($eventType, $hosts, $window, $ignoringBookingId)
            ->filter(fn (HostAvailability $entry) => $entry->free->isNotEmpty());
    }

    /**
     * Work out each host's working windows and open time inside the window.
     *
     * Unlike the slots, this keeps a host whose day is fully booked: their
     * working windows are still when they are on duty, which is what a
     * coverage view needs to tell "booked up" from "not working". A host
     * with no schedule at all is left out.
     *
     * @param  Collection<int, User>  $hosts
     * @return Collection<int, HostAvailability> Keyed by user id.
     */
    public function hostAvailability(EventType $eventType, Collection $hosts, TimeRange $window, ?int $ignoringBookingId = null): Collection
    {
        $userIds = $hosts->pluck('id')->all();

        $busyByHost = $this->busyTimes->forHosts(
            $userIds,
            $window->pad($eventType->buffer_before_minutes, $eventType->buffer_after_minutes),
            // A group event may still have seats left in a slot it already owns,
            // so its own bookings must not block it.
            $eventType->kind->allowsMultipleInvitees() ? $eventType : null,
            $ignoringBookingId,
        );

        /** @var Collection<int, HostAvailability> $available */
        $available = new Collection;

        foreach ($hosts as $host) {
            $schedule = $this->scheduleResolver->resolve($eventType, $host);

            if ($schedule === null) {
                continue;
            }

            $windows = $this->workingHours->windowsFor($schedule, $window);
            $windows = $this->withoutHolidays($windows, $host, $window, $schedule->timezone ?: 'UTC');
            $busy = $busyByHost->get($host->id) ?? new Collection;

            $ranges = $windows
                ->flatMap(fn (TimeRange $range) => $range->subtract($busy))
                ->values();

            $available->put($host->id, new HostAvailability($ranges, $windows));
        }

        return $available;
    }

    /**
     * Drop any working window falling on a holiday the host takes off.
     *
     * @param  Collection<int, TimeRange>  $windows
     * @return Collection<int, TimeRange>
     */
    protected function withoutHolidays(Collection $windows, User $host, TimeRange $window, string $timezone): Collection
    {
        $dates = $this->restrictions->holidayDates($host, $window);

        if ($dates === []) {
            return $windows;
        }

        return $windows->reject(fn (TimeRange $range) => in_array(
            $range->start->setTimezone($timezone)->toDateString(),
            $dates,
            true,
        ))->values();
    }

    /**
     * Build slots offered when any single host being free is enough.
     *
     * @param  Collection<int, Collection<int, TimeRange>>  $freeByHost
     * @param  Collection<int, TimeRange>  $anchors
     * @return Collection<int, AvailabilitySlot>
     */
    protected function pooledSlots(EventType $eventType, Collection $freeByHost, TimeRange $window, Collection $anchors): Collection
    {
        /** @var Collection<string, array{start: CarbonImmutable, hosts: array<int, int>}> $byStart */
        $byStart = new Collection;

        foreach ($freeByHost as $userId => $ranges) {
            foreach ($this->startTimes($eventType, $ranges, $window, $anchors) as $start) {
                $key = $start->toIso8601String();
                $entry = $byStart->get($key, ['start' => $start, 'hosts' => []]);
                $entry['hosts'][] = (int) $userId;
                $byStart->put($key, $entry);
            }
        }

        return $byStart
            ->sortBy(fn (array $entry) => $entry['start']->getTimestamp())
            ->map(fn (array $entry) => new AvailabilitySlot(
                startsAt: $entry['start'],
                endsAt: $entry['start']->addMinutes($eventType->duration_minutes),
                hostIds: $entry['hosts'],
                seatsRemaining: $eventType->seats(),
            ))
            ->values();
    }

    /**
     * Build slots offered only when every host is free at once.
     *
     * @param  Collection<int, Collection<int, TimeRange>>  $freeByHost
     * @param  Collection<int, TimeRange>  $anchors
     * @return Collection<int, AvailabilitySlot>
     */
    protected function collectiveSlots(EventType $eventType, Collection $freeByHost, TimeRange $window, Collection $anchors, int $hostCount): Collection
    {
        if ($freeByHost->count() < $hostCount) {
            return new Collection;
        }

        $shared = TimeRange::intersectAll($freeByHost->values());
        $hostIds = $freeByHost->keys()->map(fn ($id) => (int) $id)->all();

        return $this->startTimes($eventType, $shared, $window, $anchors)
            ->map(fn (CarbonImmutable $start) => new AvailabilitySlot(
                startsAt: $start,
                endsAt: $start->addMinutes($eventType->duration_minutes),
                hostIds: $hostIds,
                seatsRemaining: $eventType->seats(),
            ))
            ->values();
    }

    /**
     * Step through free ranges producing candidate start times.
     *
     * @param  Collection<int, TimeRange>  $ranges
     * @param  Collection<int, TimeRange>  $anchors
     * @return Collection<int, CarbonImmutable>
     */
    protected function startTimes(EventType $eventType, Collection $ranges, TimeRange $window, Collection $anchors): Collection
    {
        $interval = max(5, $eventType->slotInterval());
        $duration = $eventType->duration_minutes;

        /** @var Collection<int, CarbonImmutable> $starts */
        $starts = new Collection;

        foreach ($ranges as $range) {
            $cursor = $this->firstStartFor($range, $anchors, $interval);

            while ($cursor->addMinutes($duration) <= $range->end) {
                if ($cursor >= $window->start) {
                    $starts->push($cursor);
                }

                $cursor = $cursor->addMinutes($interval);
            }
        }

        return $starts
            ->unique(fn (CarbonImmutable $start) => $start->getTimestamp())
            ->sortBy(fn (CarbonImmutable $start) => $start->getTimestamp())
            ->values();
    }

    /**
     * Get the first start time inside a free range that sits on the grid of
     * the working window it belongs to.
     *
     * @param  Collection<int, TimeRange>  $anchors
     */
    protected function firstStartFor(TimeRange $range, Collection $anchors, int $interval): CarbonImmutable
    {
        $anchor = $anchors
            ->filter(fn (TimeRange $window) => $window->start <= $range->start && $range->start < $window->end)
            ->map(fn (TimeRange $window) => $window->start)
            ->first() ?? $range->start;

        if ($anchor >= $range->start) {
            return $anchor;
        }

        $steps = (int) ceil($anchor->diffInMinutes($range->start) / $interval);

        return $anchor->addMinutes($steps * $interval);
    }

    /**
     * Drop slots where a host has hit their own cap on meetings.
     *
     * A slot survives if any of its hosts still has room; a collective event
     * needs every host to have room, since all of them attend.
     *
     * @param  Collection<int, AvailabilitySlot>  $slots
     * @return Collection<int, AvailabilitySlot>
     */
    protected function applyHostLimits(EventType $eventType, Collection $slots, TimeRange $window, ?int $ignoringBookingId): Collection
    {
        if ($slots->isEmpty()) {
            return $slots;
        }

        $timezone = $this->limitTimezone($eventType);
        $userIds = $slots->flatMap(fn (AvailabilitySlot $slot) => $slot->hostIds)->unique()->values()->all();

        $filledByHost = $this->restrictions->filledPeriods($userIds, $window, $timezone, $ignoringBookingId);

        if ($filledByHost->isEmpty()) {
            return $slots;
        }

        $needsEveryHost = $eventType->kind->requiresEveryHost();

        return $slots
            ->map(function (AvailabilitySlot $slot) use ($filledByHost, $timezone, $needsEveryHost) {
                $localStart = $slot->startsAt->setTimezone($timezone);

                $withRoom = array_values(array_filter(
                    $slot->hostIds,
                    fn (int $hostId) => $this->restrictions->hasRoom(
                        $filledByHost->get($hostId, []),
                        $localStart,
                    ),
                ));

                if ($needsEveryHost && count($withRoom) !== count($slot->hostIds)) {
                    return null;
                }

                return $withRoom === []
                    ? null
                    : new AvailabilitySlot(
                        startsAt: $slot->startsAt,
                        endsAt: $slot->endsAt,
                        hostIds: $withRoom,
                        seatsRemaining: $slot->seatsRemaining,
                    );
            })
            ->filter()
            ->values();
    }

    /**
     * Drop group slots that would overlap a session already booked at another time.
     *
     * A group event's own bookings are left out of busy time so a session with
     * seats left stays offered, but that must only hold for the session's own
     * start. When the interval is shorter than the duration, or buffers are
     * set, a start that overlaps an existing session would put the host in two
     * at once.
     *
     * @param  Collection<int, AvailabilitySlot>  $slots
     * @return Collection<int, AvailabilitySlot>
     */
    protected function withoutOverlappingSessions(EventType $eventType, Collection $slots, ?int $ignoringBookingId): Collection
    {
        if ($slots->isEmpty()) {
            return $slots;
        }

        $sessions = Booking::query()
            ->active()
            ->where('event_type_id', $eventType->id)
            ->where('starts_at', '<', $slots->last()->endsAt->addMinutes($eventType->buffer_before_minutes))
            ->where('ends_at', '>', $slots->first()->startsAt->subMinutes($eventType->buffer_after_minutes))
            ->when($ignoringBookingId !== null, fn ($query) => $query->whereKeyNot($ignoringBookingId))
            ->get(['id', 'starts_at', 'ends_at']);

        if ($sessions->isEmpty()) {
            return $slots;
        }

        return $slots
            ->reject(fn (AvailabilitySlot $slot) => $sessions->contains(
                fn (Booking $session) => ! $slot->startsAt->equalTo($session->starts_at)
                    && TimeRange::make($session->starts_at, $session->ends_at)
                        ->pad($eventType->buffer_before_minutes, $eventType->buffer_after_minutes)
                        ->overlaps(new TimeRange($slot->startsAt, $slot->endsAt)),
            ))
            ->values();
    }

    /**
     * Drop slots that would breach the daily limit or that have no seats left.
     *
     * @param  Collection<int, AvailabilitySlot>  $slots
     * @return Collection<int, AvailabilitySlot>
     */
    protected function applyBookingLimits(EventType $eventType, Collection $slots, ?int $ignoringBookingId = null): Collection
    {
        if ($slots->isEmpty()) {
            return $slots;
        }

        $timezone = $this->limitTimezone($eventType);
        $seats = $eventType->seats();

        // Count across whole local days, otherwise a booking sitting before the
        // first free slot would not count towards that day's limit.
        $from = $slots->first()->startsAt->setTimezone($timezone)->startOfDay()->utc();
        $to = $slots->last()->startsAt->setTimezone($timezone)->endOfDay()->utc();

        $existing = Booking::query()
            ->active()
            ->where('event_type_id', $eventType->id)
            ->whereBetween('starts_at', [$from, $to])
            ->when($ignoringBookingId !== null, fn ($query) => $query->whereKeyNot($ignoringBookingId))
            ->get(['id', 'starts_at']);

        $perDay = $existing
            ->groupBy(fn (Booking $booking) => $booking->starts_at->setTimezone($timezone)->toDateString())
            ->map->count();

        $perStart = $existing
            ->groupBy(fn (Booking $booking) => $booking->starts_at->utc()->toIso8601String())
            ->map->count();

        $limit = $eventType->daily_booking_limit;

        // Seats only gate group events. For every other kind a slot is already
        // gated by whether a host is free, and a booked host has been removed
        // from the slot's host list by the busy time pass.
        return $slots
            ->when($eventType->kind->allowsMultipleInvitees(), fn (Collection $slots) => $slots
                ->map(fn (AvailabilitySlot $slot) => new AvailabilitySlot(
                    startsAt: $slot->startsAt,
                    endsAt: $slot->endsAt,
                    hostIds: $slot->hostIds,
                    seatsRemaining: $seats - $perStart->get($slot->startsAt->toIso8601String(), 0),
                ))
                ->filter(fn (AvailabilitySlot $slot) => $slot->seatsRemaining > 0))
            ->when($limit !== null, fn (Collection $slots) => $slots->filter(
                fn (AvailabilitySlot $slot) => $perDay->get($slot->startsAt->setTimezone($timezone)->toDateString(), 0) < $limit
            ))
            ->values();
    }

    /**
     * Get the timezone that daily booking limits are counted in.
     */
    protected function limitTimezone(EventType $eventType): string
    {
        $owner = $eventType->owner;

        $schedule = $this->scheduleResolver->resolve($eventType, $owner);

        return $schedule?->timezone ?: ($owner->timezone ?: config('scheduling.default_timezone'));
    }
}
