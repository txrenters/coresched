<?php

namespace App\Services\Scheduling;

use App\Models\Team;
use App\Models\TeamClosure;
use App\Models\User;
use App\Support\TimeRange;
use Carbon\CarbonImmutable;

/**
 * Restrictions that apply to a host across every event type they run: the
 * holidays they take off, and the holidays and blocked dates their
 * organization requires of everyone.
 */
class HostRestrictions
{
    public function __construct(protected HolidayCalendar $holidays)
    {
        //
    }

    /**
     * Get the local dates a host is unavailable on: the holidays they take off,
     * plus the holidays and blocked dates the organization requires.
     *
     * @return array<int, string> Y-m-d dates.
     */
    public function holidayDates(User $host, TimeRange $window, ?Team $organization = null): array
    {
        $fromYear = (int) $window->start->format('Y');
        $toYear = (int) $window->end->format('Y');

        $dates = blank($host->holiday_country)
            ? []
            : $this->holidays->datesFor($host->holiday_country, $host->enabledHolidays(), $fromYear, $toYear);

        if ($organization !== null) {
            if (filled($organization->holiday_country)) {
                $dates = [
                    ...$dates,
                    ...$this->holidays->datesFor($organization->holiday_country, $organization->requiredHolidays(), $fromYear, $toYear),
                ];
            }

            $dates = [...$dates, ...$this->closureDates($organization, $window)];
        }

        return array_values(array_unique($dates));
    }

    /**
     * Get every day the organization has blocked near the window.
     *
     * Padded by a day either side so a window that starts late in UTC still
     * sees the local date it lands on.
     *
     * @return array<int, string> Y-m-d dates.
     */
    protected function closureDates(Team $organization, TimeRange $window): array
    {
        $from = $window->start->subDay()->toDateString();
        $to = $window->end->addDay()->toDateString();

        $dates = [];

        $organization->closures()
            ->whereDate('starts_on', '<=', $to)
            ->whereDate('ends_on', '>=', $from)
            ->get(['starts_on', 'ends_on'])
            ->each(function (TeamClosure $closure) use (&$dates, $from, $to) {
                $day = CarbonImmutable::parse(max($closure->starts_on->toDateString(), $from));
                $last = min($closure->ends_on->toDateString(), $to);

                while ($day->toDateString() <= $last) {
                    $dates[] = $day->toDateString();
                    $day = $day->addDay();
                }
            });

        return $dates;
    }
}
