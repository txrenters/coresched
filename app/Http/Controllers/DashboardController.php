<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\EventType;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Scheduling\ScopeFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The number of upcoming meetings listed on the dashboard.
     *
     * The first feeds the next-meeting hero; the rest fill today's schedule,
     * so a busy day is not truncated after a handful of rows.
     */
    protected int $upcomingLimit = 8;

    public function __invoke(Request $request, Team $current_team, ScopeFilter $scopes): Response
    {
        $user = $request->user();

        // Owners and admins land on the whole organization; a member's scope
        // always resolves to themselves, whatever ?scope= asks for.
        $defaultScope = $scopes->defaultScope($current_team, $user);
        $scope = $request->string('scope', $defaultScope)->toString();

        if (! $scopes->validValues($current_team, $user)->contains($scope)) {
            $scope = $defaultScope;
        }

        $hostIds = $scopes->userIds($scope, $current_team, $user);
        $email = strtolower($user->email);

        $pendingInvitations = TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation) => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter->name,
                'team' => [
                    'name' => $invitation->team->name,
                    'slug' => $invitation->team->slug,
                ],
            ]);

        return Inertia::render('Dashboard', [
            'pendingInvitations' => $pendingInvitations,
            'greetingName' => str($user->name)->before(' ')->toString(),
            'bookingUrl' => $user->booking_slug
                ? route('book.page', ['page' => $user->booking_slug])
                : null,
            'scope' => $scope,
            'scopeOptions' => $scopes->options($current_team, $user),
            'showsOthers' => $hostIds !== [$user->id],
            // Both need aggregate queries, so the shell paints first and these
            // stream in behind their skeletons.
            'stats' => Inertia::defer(fn () => $this->stats($current_team, $scopes, $hostIds)),
            'upcoming' => Inertia::defer(fn () => $this->upcoming($user, $current_team, $scopes, $hostIds)),
        ]);
    }

    /**
     * Build the headline counters.
     *
     * @param  array<int, int>|null  $hostIds
     * @return array{upcoming: int, thisWeek: int, eventTypes: int}
     */
    protected function stats(Team $team, ScopeFilter $scopes, ?array $hostIds): array
    {
        $now = CarbonImmutable::now();

        return [
            'upcoming' => $this->hosted($team, $scopes, $hostIds)->active()->upcoming()->count(),
            'thisWeek' => $this->hosted($team, $scopes, $hostIds)
                ->active()
                ->whereBetween('starts_at', [$now->startOfWeek(), $now->endOfWeek()])
                ->count(),
            'eventTypes' => EventType::query()
                ->where('team_id', $team->id)
                ->where('is_active', true)
                ->count(),
        ];
    }

    /**
     * Get the next few meetings in the chosen scope.
     *
     * @param  array<int, int>|null  $hostIds
     * @return array<int, array<string, mixed>>
     */
    protected function upcoming(User $user, Team $team, ScopeFilter $scopes, ?array $hostIds): array
    {
        $timezone = $user->timezone ?: config('scheduling.default_timezone');

        return $this->hosted($team, $scopes, $hostIds)
            ->active()
            ->upcoming()
            ->with(['eventType:id,name,color', 'host:id,name'])
            ->orderBy('starts_at')
            ->limit($this->upcomingLimit)
            ->get()
            ->map(fn (Booking $booking) => [
                'uid' => $booking->uid,
                'name' => $booking->name,
                'hostName' => $booking->host?->name,
                'eventTypeName' => $booking->eventType?->name,
                'color' => $booking->eventType?->color,
                'startsAt' => $booking->starts_at->toIso8601String(),
                'dayLabel' => $booking->starts_at->setTimezone($timezone)->isoFormat('ddd D MMM'),
                'timeLabel' => $booking->starts_at->setTimezone($timezone)->isoFormat('h:mm a'),
                'endTimeLabel' => $booking->ends_at->setTimezone($timezone)->isoFormat('h:mm a'),
                'durationMinutes' => (int) $booking->starts_at->diffInMinutes($booking->ends_at),
                'status' => $booking->status->value,
                'statusLabel' => $booking->status->label(),
                'meetingUrl' => $booking->meeting_url,
                'isToday' => $booking->starts_at->setTimezone($timezone)->isToday(),
            ])
            ->all();
    }

    /**
     * Scope the team's bookings to the hosts the chosen scope resolves to.
     *
     * Null means everyone, which ScopeFilter only returns to a viewer allowed
     * to manage the organization's bookings.
     *
     * @param  array<int, int>|null  $hostIds
     * @return Builder<Booking>
     */
    protected function hosted(Team $team, ScopeFilter $scopes, ?array $hostIds): Builder
    {
        return $scopes->applyToHosts(
            Booking::query()->where('team_id', $team->id),
            $hostIds,
            'hosts',
        );
    }
}
