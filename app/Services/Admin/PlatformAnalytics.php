<?php

namespace App\Services\Admin;

use App\Enums\SalesInquiryStatus;
use App\Enums\TeamPlan;
use App\Models\Booking;
use App\Models\SalesInquiry;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The numbers behind the super admin dashboard, across every organization.
 *
 * Time series are grouped by calendar day in SQL (portable across databases)
 * and rolled up into weeks or months here, so a period with nothing in it
 * still appears as a zero rather than going missing from the chart.
 */
class PlatformAnalytics
{
    public function __construct(protected ?CarbonImmutable $now = null)
    {
        $this->now ??= CarbonImmutable::now();
    }

    /**
     * Everything the dashboard shows.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'stats' => $this->stats(),
            'bookingsByWeek' => $this->bookingsByWeek(),
            'organizationsByMonth' => $this->organizationsByMonth(),
            'planMix' => $this->planMix(),
            'setupFunnel' => $this->setupFunnel(),
        ];
    }

    /**
     * The headline figures, with bookings compared to the 30 days before.
     *
     * @return array<string, int>
     */
    public function stats(): array
    {
        $since = $this->now->subDays(30);

        return [
            'organizations' => Team::query()->count(),
            'activeOrganizations' => Team::query()
                ->whereHas('bookings', fn (Builder $query) => $query->where('created_at', '>=', $since))
                ->count(),
            'users' => User::query()->where('is_super_admin', false)->count(),
            'bookingsLast30Days' => Booking::query()->where('created_at', '>=', $since)->count(),
            'bookingsPrevious30Days' => Booking::query()
                ->whereBetween('created_at', [$this->now->subDays(60), $since])
                ->count(),
            'newInquiries' => SalesInquiry::query()->where('status', SalesInquiryStatus::New)->count(),
        ];
    }

    /**
     * Bookings made per week (Monday start), the last 12 weeks.
     *
     * @return list<array{label: string, value: int}>
     */
    public function bookingsByWeek(int $weeks = 12): array
    {
        $start = $this->now->startOfWeek()->subWeeks($weeks - 1);
        $perDay = $this->countPerDay(Booking::query(), $start);

        return collect(range(0, $weeks - 1))
            ->map(function (int $offset) use ($start, $perDay) {
                $weekStart = $start->addWeeks($offset);

                return [
                    'label' => $weekStart->format('M j'),
                    'value' => $this->sumBetween($perDay, $weekStart, $weekStart->addWeek()),
                ];
            })
            ->all();
    }

    /**
     * Organizations created per month, the last 12 months.
     *
     * @return list<array{label: string, value: int}>
     */
    public function organizationsByMonth(int $months = 12): array
    {
        $start = $this->now->startOfMonth()->subMonths($months - 1);
        $perDay = $this->countPerDay(Team::query(), $start);

        return collect(range(0, $months - 1))
            ->map(function (int $offset) use ($start, $perDay) {
                $monthStart = $start->addMonths($offset);

                return [
                    'label' => $monthStart->format('M y'),
                    'value' => $this->sumBetween($perDay, $monthStart, $monthStart->addMonth()),
                ];
            })
            ->all();
    }

    /**
     * How many organizations are on each plan, including none recorded.
     *
     * @return list<array{label: string, value: int}>
     */
    public function planMix(): array
    {
        $counts = Team::query()
            ->selectRaw('plan, COUNT(*) as aggregate')
            ->groupBy('plan')
            ->pluck('aggregate', 'plan');

        return [
            ...array_map(fn (TeamPlan $plan) => [
                'label' => $plan->label(),
                'value' => (int) ($counts[$plan->value] ?? 0),
            ], TeamPlan::cases()),
            ['label' => 'No plan', 'value' => (int) ($counts[''] ?? 0)],
        ];
    }

    /**
     * How many organizations have reached each setup step.
     *
     * @return list<array{label: string, value: int}>
     */
    public function setupFunnel(): array
    {
        $teams = Team::query()
            ->withCount([
                'eventTypes',
                'bookings',
                'members as calendar_members_count' => fn (Builder $query) => $query->whereHas('calendarAccounts'),
            ])
            ->get(['id', 'sms_from_number', 'twilio_account_sid', 'twilio_auth_token']);

        return [
            ['label' => 'Calendar connected', 'value' => $teams->where('calendar_members_count', '>', 0)->count()],
            ['label' => 'Event types', 'value' => $teams->where('event_types_count', '>', 0)->count()],
            ['label' => 'Twilio', 'value' => $teams->filter(fn (Team $team) => $team->hasTwilio())->count()],
            ['label' => 'SMS number', 'value' => $teams->filter(fn (Team $team) => filled($team->sms_from_number))->count()],
            ['label' => 'First booking', 'value' => $teams->where('bookings_count', '>', 0)->count()],
        ];
    }

    /**
     * Count rows per calendar day from a date onwards.
     *
     * @param  Builder<Model>  $query
     * @return Collection<string, int>
     */
    protected function countPerDay(Builder $query, CarbonImmutable $start): Collection
    {
        return $query
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as aggregate')
            ->groupBy('day')
            ->pluck('aggregate', 'day')
            ->map(fn ($count) => (int) $count);
    }

    /**
     * Sum day counts within [from, to).
     *
     * @param  Collection<string, int>  $perDay
     */
    protected function sumBetween(Collection $perDay, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return $perDay
            ->filter(fn (int $count, string $day) => $day >= $from->toDateString() && $day < $to->toDateString())
            ->sum();
    }
}
