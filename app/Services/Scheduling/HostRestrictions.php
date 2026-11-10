<?php

namespace App\Services\Scheduling;

use App\Models\Team;
use App\Models\TeamClosure;
use App\Support\TimeRange;
use Carbon\CarbonImmutable;

/**
 * Days an organization is closed: the holidays it takes off and the dates it
 * blocks (a company outing, say). Nobody in it can be booked on them for its
 * event types; they are the organization's call, not each host's.
 */
class HostRestrictions
{
    public function __construct(protected HolidayCalendar $holidays)
    {
        //
    }

    /**
     * Get the local dates the organization is closed on near the window.
     *
     * @return array<int, string> Y-m-d dates.
     */
    public function closedDates(Team $organization, TimeRange $window): array
    {
        $fromYear = (int) $window->start->format('Y');
        $toYear = (int) $window->end->format('Y');

        $dates = blank($organization->holiday_country)
            ? []
            : $this->holidays->datesFor($organization->holiday_country, $organization->requiredHolidays(), $fromYear, $toYear);

        return array_values(array_unique([...$dates, ...$this->closureDates($organization, $window)]));
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
