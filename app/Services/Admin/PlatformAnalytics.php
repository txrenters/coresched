<?php

namespace App\Services\Admin;

use App\Enums\SalesInquiryStatus;
use App\Enums\TeamPlan;
use App\Models\Booking;
use App\Models\PageVisit;
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
 * The public demo is left out of every figure: its organization, its sample
 * people and the bookings visitors make in it are not anyone's real use.
 *
 * Time series are grouped by calendar day in SQL (portable across databases)
 * and rolled up into weeks or months here, so a period with nothing in it
 * still appears as a zero rather than going missing from the chart.
 */
class PlatformAnalytics
{
    /**
     * Readable names for the marketing pages RecordPageVisit counts.
     *
     * @var array<string, string>
     */
    protected const PAGE_LABELS = [
        '/' => 'Home',
        '/pricing' => 'Pricing',
        '/termsofservice' => 'Terms of service',
        '/privacy' => 'Privacy policy',
    ];

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
            'traffic' => $this->traffic(),
        ];
    }

    /**
     * Visits to the public marketing pages, the last 30 days.
     *
     * A visitor is one visitor_hash, which changes every day: "visitors" over
     * 30 days is the sum of each day's unique visitors, not unique people.
     *
     * @return array{visitorsLast30Days: int, visitorsPrevious30Days: int, pageViewsLast30Days: int, visitorsByDay: list<array{label: string, value: int}>, topPages: list<array{label: string, value: int}>, topReferrers: list<array{label: string, value: int}>, devices: list<array{label: string, value: int}>}
     */
    public function traffic(): array
    {
        $start = $this->now->startOfDay()->subDays(29);
        $previousStart = $start->subDays(30);

        $visitorsPerDay = PageVisit::query()
            ->where('created_at', '>=', $previousStart)
            ->selectRaw('DATE(created_at) as day, COUNT(DISTINCT visitor_hash) as aggregate')
            ->groupBy('day')
            ->pluck('aggregate', 'day')
            ->map(fn ($count) => (int) $count);

        $recent = fn () => PageVisit::query()->where('created_at', '>=', $start);

        return [
            'visitorsLast30Days' => $this->sumBetween($visitorsPerDay, $start, $this->now->addDay()),
            'visitorsPrevious30Days' => $this->sumBetween($visitorsPerDay, $previousStart, $start),
            'pageViewsLast30Days' => $recent()->count(),
            'visitorsByDay' => array_map(fn (int $offset) => [
                'label' => $start->addDays($offset)->format('M j'),
                'value' => $visitorsPerDay[$start->addDays($offset)->toDateString()] ?? 0,
            ], range(0, 29)),
            'topPages' => $this->ranked($recent()->whereNotNull('path'), 'path', fn (string $path) => self::PAGE_LABELS[$path] ?? $path),
            'topReferrers' => $this->ranked($recent()->whereNotNull('referrer_host'), 'referrer_host'),
            'devices' => $this->ranked($recent(), 'device', fn (string $device) => ucfirst($device)),
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
            'organizations' => Team::query()->excludingDemo()->count(),
            'activeOrganizations' => Team::query()->excludingDemo()
                ->whereHas('bookings', fn (Builder $query) => $query->where('created_at', '>=', $since))
                ->count(),
            'users' => User::query()->realUsers()->count(),
            'bookingsLast30Days' => $this->realBookings()->where('created_at', '>=', $since)->count(),
            'bookingsPrevious30Days' => $this->realBookings()
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
        $perDay = $this->countPerDay($this->realBookings(), $start);

        return array_map(function (int $offset) use ($start, $perDay) {
            $weekStart = $start->addWeeks($offset);

            return [
                'label' => $weekStart->format('M j'),
                'value' => $this->sumBetween($perDay, $weekStart, $weekStart->addWeek()),
            ];
        }, range(0, $weeks - 1));
    }

    /**
     * Organizations created per month, the last 12 months.
     *
     * @return list<array{label: string, value: int}>
     */
    public function organizationsByMonth(int $months = 12): array
    {
        $start = $this->now->startOfMonth()->subMonths($months - 1);
        $perDay = $this->countPerDay(Team::query()->excludingDemo(), $start);

        return array_map(function (int $offset) use ($start, $perDay) {
            $monthStart = $start->addMonths($offset);

            return [
                'label' => $monthStart->format('M y'),
                'value' => $this->sumBetween($perDay, $monthStart, $monthStart->addMonth()),
            ];
        }, range(0, $months - 1));
    }

    /**
     * How many organizations are on each plan, including none recorded.
     *
     * @return list<array{label: string, value: int}>
     */
    public function planMix(): array
    {
        $counts = Team::query()->excludingDemo()
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
        $teams = Team::query()->excludingDemo()
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
     * Bookings in real organizations, leaving out the public demo's.
     *
     * @return Builder<Booking>
     */
    protected function realBookings(): Builder
    {
        // Only the demo is taken out: bookings of a deleted real organization
        // still count, as they always have.
        return Booking::query()->whereNotIn('team_id', Team::withTrashed()->where('is_demo', true)->select('id'));
    }

    /**
     * The five most common values of a column, most first.
     *
     * @param  Builder<PageVisit>  $query
     * @param  (callable(string): string)|null  $label
     * @return list<array{label: string, value: int}>
     */
    protected function ranked(Builder $query, string $column, ?callable $label = null): array
    {
        $counts = $query->toBase()
            ->select($column)
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy($column)
            ->orderByDesc('aggregate')
            ->orderBy($column)
            ->limit(5)
            ->pluck('aggregate', $column);

        $rows = [];

        foreach ($counts as $name => $count) {
            $rows[] = [
                'label' => $label ? $label((string) $name) : (string) $name,
                'value' => (int) $count,
            ];
        }

        return $rows;
    }

    /**
     * Count rows per calendar day from a date onwards.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
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
