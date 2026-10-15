<?php

namespace App\Actions\Teams;

use App\Models\HostZone;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Replace the areas a host takes meetings for within one organization.
 *
 * The set given is the whole truth: anything not in it is removed. Keys
 * are normalised and deduplicated here so two writers cannot leave the
 * same zone twice, and the stored list is returned sorted so the caller
 * sees exactly what the slots will be filtered on.
 */
class SyncHostZones
{
    /**
     * @param  list<string>  $zones
     * @return list<string>
     */
    public function handle(Team $team, User $host, array $zones): array
    {
        $wanted = collect($zones)
            ->map(fn (string $zone): ?string => HostZone::normalise($zone))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return DB::transaction(function () use ($team, $host, $wanted): array {
            HostZone::query()
                ->where('team_id', $team->id)
                ->where('user_id', $host->id)
                ->whereNotIn('zone', $wanted->all())
                ->delete();

            $existing = HostZone::query()
                ->where('team_id', $team->id)
                ->where('user_id', $host->id)
                ->pluck('zone');

            foreach ($wanted->diff($existing) as $zone) {
                HostZone::query()->create([
                    'team_id' => $team->id,
                    'user_id' => $host->id,
                    'zone' => $zone,
                ]);
            }

            return array_values($wanted->all());
        });
    }
}
