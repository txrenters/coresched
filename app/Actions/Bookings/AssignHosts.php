<?php

namespace App\Actions\Bookings;

use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Support\Collection;

class AssignHosts
{
    /**
     * Choose which of the available hosts should take a slot.
     *
     * Collective events use every host. Pooled events go to the highest
     * priority host who is free: the pool is ordered by the priority set on the
     * event type (or on its group), and only hosts sharing the same priority
     * fall through to load balancing, so a tier of equals still spreads evenly.
     *
     * A host who is busy is simply not in $availableHostIds, so the next
     * priority down picks the slot up.
     *
     * A zone keeps the choice to the hosts covering it, the same narrowing
     * the slots were offered with. The owner fallback is for a pool with
     * nobody in it, never a way round the zone: with a zone somebody covers
     * and no covering host free, the slot is not available.
     *
     * @param  array<int, int>  $availableHostIds
     * @return Collection<int, User>
     *
     * @throws SlotUnavailableException
     */
    public function handle(EventType $eventType, array $availableHostIds, ?string $zone = null): Collection
    {
        $candidates = $eventType->schedulingHosts($zone)
            ->whereIn('id', $availableHostIds)
            ->values();

        if ($candidates->isEmpty()) {
            if ($zone !== null && $eventType->poolCoversZone($zone)) {
                throw SlotUnavailableException::taken();
            }

            return new Collection([$eventType->owner]);
        }

        if ($eventType->kind->requiresEveryHost()) {
            return $candidates;
        }

        $loads = Booking::query()
            ->active()
            ->upcoming()
            ->where('event_type_id', $eventType->id)
            ->whereIn('user_id', $candidates->pluck('id'))
            ->selectRaw('user_id, count(*) as aggregate')
            ->groupBy('user_id')
            ->pluck('aggregate', 'user_id');

        /*
         * One comparator rather than sortBy([...]): Laravel treats callables in
         * that array as two-argument comparators, not key extractors, so the
         * chained form silently compares the wrong thing.
         */
        $chosen = $candidates
            ->sort(fn (User $a, User $b) => [
                $this->priorityOf($a),
                (int) $loads->get($a->id, 0),
                $a->id,
            ] <=> [
                $this->priorityOf($b),
                (int) $loads->get($b->id, 0),
                $b->id,
            ])
            ->first();

        return new Collection([$chosen]);
    }

    /**
     * Read a host's priority off whichever pivot brought them into the pool.
     *
     * Lower sorts first, matching the stored position. A host with no pivot at
     * all — the owner fallback — ranks top.
     */
    protected function priorityOf(User $host): int
    {
        $pivot = $host->getRelationValue('pivot');

        return $pivot === null ? 0 : (int) ($pivot->priority ?? 0);
    }
}
