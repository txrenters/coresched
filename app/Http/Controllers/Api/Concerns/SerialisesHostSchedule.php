<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\AvailabilityOverride;
use App\Models\AvailabilityRule;
use App\Models\AvailabilitySchedule;
use Carbon\CarbonImmutable;

/**
 * A host's schedule as its owner set it: wall-clock hours in its own
 * timezone, in the one shape every hosts endpoint answers with, so another
 * application reads the same thing back after it writes.
 */
trait SerialisesHostSchedule
{
    /** Date overrides further out than this many are not worth sending. */
    private const MAX_OVERRIDES = 60;

    /**
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

    /** "09:00:00" as stored, "09:00" as anybody would read it. */
    private function clock(string $time): string
    {
        return substr($time, 0, 5);
    }
}
