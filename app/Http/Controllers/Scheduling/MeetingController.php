<?php

namespace App\Http\Controllers\Scheduling;

use App\Actions\Bookings\ApproveBooking;
use App\Actions\Bookings\CancelBooking;
use App\Actions\Bookings\DeclineBooking;
use App\Actions\Bookings\HandOffBooking;
use App\Actions\Bookings\RecordNoShow;
use App\Enums\BookingStatus;
use App\Enums\TeamPermission;
use App\Exceptions\SlotUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Team;
use App\Models\User;
use App\Services\Scheduling\ScopeFilter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MeetingController extends Controller
{
    /**
     * The date ranges offered as chips above the list.
     *
     * @var array<int, array{value: string, label: string}>
     */
    protected array $ranges = [
        ['value' => 'today', 'label' => 'Today'],
        ['value' => 'upcoming', 'label' => 'Upcoming'],
        ['value' => 'this_week', 'label' => 'This week'],
        ['value' => 'last_week', 'label' => 'Last week'],
        ['value' => 'past', 'label' => 'Past'],
    ];

    /**
     * The number of meetings loaded per page.
     */
    protected int $perPage = 20;

    /**
     * Display the meetings for the current team.
     */
    public function index(Request $request, Team $current_team, ScopeFilter $scopes): Response
    {
        $user = $request->user();
        $timezone = $user->timezone ?: config('scheduling.default_timezone');

        $defaultScope = $scopes->defaultScope($current_team, $user);
        $scope = $request->string('scope', $defaultScope)->toString();

        if (! $scopes->validValues($current_team, $user)->contains($scope)) {
            $scope = $defaultScope;
        }

        $range = $request->string('filter', 'today')->toString();

        if (! collect($this->ranges)->pluck('value')->contains($range)) {
            $range = 'today';
        }

        $status = $request->string('status', 'active')->toString();

        if (! in_array($status, ['active', 'pending', 'canceled', 'host_away'], true)) {
            $status = 'active';
        }

        $search = $request->string('search')->toString();
        $eventTypeId = $request->integer('event_type');
        $page = max(1, $request->integer('page', 1));

        $query = $this->baseQuery($current_team, $user, $scopes, $scope);

        $this->applyRange($query, $range, $timezone, $status);
        $this->applySearch($query, $search);

        if ($eventTypeId > 0) {
            $query->where('event_type_id', $eventTypeId);
        }

        $total = (clone $query)->count();

        $hostAwayCount = $this->baseQuery($current_team, $user, $scopes, $scope)
            ->active()
            ->where('ends_at', '>', now())
            ->withHostAway()
            ->count();

        $meetings = $query
            ->with(['eventType', 'eventType.owner:id,name', 'eventType.hosts:id,name', 'eventType.group.members:id,name', 'hosts:id,name', 'hosts.leavePeriods', 'host:id,name', 'host.leavePeriods', 'guests', 'answers', 'reminders'])
            ->forPage($page, $this->perPage)
            ->get();

        // One query for the page's history rather than one per meeting.
        $history = ActivityLog::query()
            ->where('subject_type', Booking::class)
            ->whereIn('subject_id', $meetings->pluck('id'))
            ->with('user:id,name')
            ->orderBy('created_at')
            ->get()
            ->groupBy('subject_id');

        return Inertia::render('scheduling/meetings/Index', [
            'meetings' => Inertia::merge(
                fn () => $meetings
                    ->map(fn (Booking $booking) => $this->toPayload(
                        $booking,
                        $timezone,
                        $history->get($booking->id) ?? collect(),
                    ))
                    ->all(),
            ),
            'total' => $total,
            'hostAwayCount' => $hostAwayCount,
            'page' => $page,
            'hasMore' => $total > $page * $this->perPage,
            'filter' => $range,
            'ranges' => $this->ranges,
            'status' => $status,
            'scope' => $scope,
            'scopeOptions' => $scopes->options($current_team, $user),
            'search' => $search,
            'eventTypeId' => $eventTypeId > 0 ? $eventTypeId : null,
            'eventTypeOptions' => $current_team->eventTypes()->orderBy('name')->get(['id', 'name'])
                ->map(fn (EventType $eventType) => ['value' => $eventType->id, 'label' => $eventType->name]),
            'viewerTimezone' => $timezone,
            'bookableEventTypes' => fn () => $this->bookableEventTypes($current_team, $user),
        ]);
    }

    /**
     * Get the event types this viewer may book on an invitee's behalf.
     *
     * @return array<int, array{id: int, name: string, url: string, hosts: array<int, array{id: int, name: string}>}>
     */
    protected function bookableEventTypes(Team $team, User $user): array
    {
        return $team->eventTypes()
            ->bookable()
            ->with(['team', 'owner:id,name,booking_slug', 'hosts:id,name', 'group.members:id,name'])
            ->orderBy('name')
            ->get()
            ->filter(fn (EventType $eventType) => $eventType->canBeBookedOnBehalfBy($user))
            ->map(fn (EventType $eventType) => [
                'id' => $eventType->id,
                'name' => $eventType->name,
                'url' => $eventType->publicUrl(),
                'hosts' => $eventType->assignableHosts()
                    ->map(fn (User $host) => ['id' => $host->id, 'name' => $host->name])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Cancel a meeting as one of its hosts.
     */
    public function destroy(Request $request, Team $current_team, Booking $booking, CancelBooking $cancelBooking): RedirectResponse
    {
        Gate::authorize('cancel', $booking);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $cancelBooking->handle($booking, $validated['reason'] ?? null, canceledBy: 'host');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Meeting canceled.')]);

        return back();
    }

    /**
     * Build the meeting's history, newest first.
     *
     * Three sources, because no single one knows the whole story: what people
     * did to the booking (the activity log), what the app sent about it
     * (reminders), and what the clock did (started, ended).
     *
     * @param  Collection<int, ActivityLog>  $history
     * @return array<int, array{label: string, detail: string|null, at: string, atLabel: string}>
     */
    protected function timeline(Booking $booking, string $timezone, Collection $history): array
    {
        /** @var Collection<int, array{label: string, detail: string|null, at: CarbonInterface}> $entries */
        $entries = new Collection;

        foreach ($history as $entry) {
            $entries->push([
                'label' => $entry->description,
                'detail' => $entry->user->name ?? $entry->actor_name,
                'at' => $entry->created_at,
            ]);
        }

        // The booking's own creation, for meetings made before the activity
        // log existed or by the public page.
        if ($history->isEmpty() && $booking->created_at !== null) {
            $entries->push([
                'label' => 'Meeting booked',
                'detail' => $booking->name,
                'at' => $booking->created_at,
            ]);
        }

        foreach ($booking->reminders->whereNotNull('sent_at') as $reminder) {
            $entries->push([
                'label' => 'Reminder sent',
                'detail' => $reminder->minutes_before >= 60
                    ? (int) round($reminder->minutes_before / 60).' hours before'
                    : $reminder->minutes_before.' minutes before',
                'at' => $reminder->sent_at,
            ]);
        }

        $now = CarbonImmutable::now();

        if ($booking->canceled_at !== null) {
            // Cancelling is already in the activity log; the clock milestones
            // below would otherwise claim a cancelled meeting went ahead.
            $entries->push([
                'label' => 'Meeting cancelled',
                'detail' => $booking->cancellation_reason,
                'at' => $booking->canceled_at,
            ]);
        } else {
            if ($booking->starts_at->isPast()) {
                $entries->push(['label' => 'Meeting started', 'detail' => null, 'at' => $booking->starts_at]);
            }

            if ($booking->ends_at->isPast()) {
                $entries->push(['label' => 'Meeting ended', 'detail' => null, 'at' => $booking->ends_at]);
            }
        }

        return $entries
            ->unique(fn (array $entry) => $entry['label'].$entry['at']->toIso8601String())
            ->sortByDesc(fn (array $entry) => $entry['at']->getTimestamp())
            ->map(fn (array $entry) => [
                'label' => $entry['label'],
                'detail' => $entry['detail'],
                'at' => $entry['at']->toIso8601String(),
                'atLabel' => $entry['at']->setTimezone($timezone)->isoFormat('D MMMM [at] h:mma'),
            ])
            ->values()
            ->all();
    }

    /**
     * Build the query of meetings this viewer is allowed to see.
     *
     * @return Builder<Booking>
     */
    protected function baseQuery(Team $team, User $user, ScopeFilter $scopes, string $scope): Builder
    {
        $visibleTo = $user->can('viewAny', Booking::class)
            && $user->hasTeamPermission($team, TeamPermission::ManageTeamBookings)
            ? null
            : [$user->id];

        $query = Booking::query()->where('team_id', $team->id);

        // Members only ever see their own meetings; the scope picker narrows
        // further within whatever they are allowed to see.
        $scopes->applyToHosts($query, $visibleTo, 'hosts');
        $scopes->applyToHosts($query, $scopes->userIds($scope, $team, $user), 'hosts');

        return $query;
    }

    /**
     * Narrow the query to the chosen date range.
     *
     * @param  Builder<Booking>  $query
     */
    protected function applyRange(Builder $query, string $range, string $timezone, string $status): void
    {
        $now = CarbonImmutable::now($timezone);

        // Cancelled meetings are a status, not a date range, so they can be
        // combined with any of the chips above the list. Active includes
        // pending requests so hosts cannot miss them.
        match ($status) {
            'canceled' => $query->whereIn('status', [BookingStatus::Canceled, BookingStatus::Rescheduled]),
            'pending' => $query->where('status', BookingStatus::Pending),
            'host_away' => $query->active()->withHostAway(),
            default => $query->active(),
        };

        match ($range) {
            'today' => $query
                ->whereBetween('starts_at', [$now->startOfDay()->utc(), $now->endOfDay()->utc()])
                ->tap(fn (Builder $query) => $this->orderComingUpFirst($query, $now)),
            'this_week' => $query
                ->whereBetween('starts_at', [$now->startOfWeek()->utc(), $now->endOfWeek()->utc()])
                ->tap(fn (Builder $query) => $this->orderComingUpFirst($query, $now)),
            'last_week' => $query
                ->whereBetween('starts_at', [
                    $now->subWeek()->startOfWeek()->utc(),
                    $now->subWeek()->endOfWeek()->utc(),
                ])
                ->orderBy('starts_at'),
            // A meeting is not past until it has ENDED, so one running right
            // now stays under Upcoming, where its host is looking for it,
            // rather than dropping into Past the minute it starts.
            //
            // ->utc() like the bounds above: $now carries the reader's zone,
            // and the binding would otherwise send that wall clock time to
            // MySQL to compare against UTC columns.
            'past' => $query->where('ends_at', '<=', $now->utc())->orderByDesc('starts_at'),
            default => $query->where('ends_at', '>', $now->utc())->orderBy('starts_at'),
        };
    }

    /**
     * Put meetings that have not ended yet first, soonest first, then the
     * finished ones, most recent first, so a range that is partly over opens
     * on what is coming up rather than on meetings already done.
     *
     * @param  Builder<Booking>  $query
     */
    protected function orderComingUpFirst(Builder $query, CarbonImmutable $now): void
    {
        $query
            ->orderByRaw('case when ends_at > ? then 0 else 1 end', [$now->utc()])
            ->orderByRaw('case when ends_at > ? then starts_at end', [$now->utc()])
            ->orderByDesc('starts_at');
    }

    /**
     * Narrow the query by a free text search.
     *
     * @param  Builder<Booking>  $query
     */
    protected function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(function (Builder $query) use ($search) {
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhereHas('eventType', fn (Builder $eventType) => $eventType->where('name', 'like', "%{$search}%"));
        });
    }

    /**
     * Present a meeting for the listing.
     *
     * @param  Collection<int, ActivityLog>|null  $history
     * @return array<string, mixed>
     */
    protected function toPayload(Booking $booking, string $timezone, ?Collection $history = null): array
    {
        $localStart = $booking->starts_at->setTimezone($timezone);
        $hosts = $booking->hosts->isNotEmpty() ? $booking->hosts : collect([$booking->host])->filter();

        return [
            'uid' => $booking->uid,
            'status' => $booking->status->value,
            'statusLabel' => $booking->status->label(),
            'startsAt' => $booking->starts_at->toIso8601String(),
            // The list marks a meeting in progress against the reader's own
            // clock, which keeps ticking while the page sits open.
            'endsAt' => $booking->ends_at->toIso8601String(),
            'dateKey' => $localStart->toDateString(),
            'dateLabel' => $this->dateLabel($localStart, $timezone),
            'dayLabel' => $localStart->format('D'),
            'dayDateLabel' => $localStart->format('j M'),
            'isToday' => $localStart->isSameDay(CarbonImmutable::now($timezone)),
            'fullDateLabel' => $localStart->format('l, j F'),
            'timeLabel' => $localStart->format('g:i a').' - '
                .$booking->ends_at->setTimezone($timezone)->format('g:i a'),
            'timeWithZone' => $localStart->format('g:i a').' - '
                .$booking->ends_at->setTimezone($timezone)->format('g:i a')
                .' ('.$localStart->format('T').')',
            'inviteeName' => $booking->name,
            'inviteeEmail' => $booking->email,
            'inviteeTimezone' => $booking->invitee_timezone,
            'notes' => $booking->notes,
            'hostNotes' => $booking->host_notes,
            'inviteeInitials' => $this->initials($booking->name),
            'inviteePhone' => $booking->location_type->requiresInviteeInput()
                ? $booking->location_detail
                : null,
            'rescheduleUrl' => route('booking.reschedule', ['booking' => $booking->uid]),
            'meetingUrl' => $booking->meeting_url,
            'locationDetail' => $booking->location_detail,
            'locationLabel' => $booking->location_type->label(),
            'cancellationReason' => $booking->cancellation_reason,
            'eventTypeName' => $booking->eventType->name,
            'color' => $booking->eventType->color,
            'hostNames' => $hosts->map(fn (User $host) => $host->name)->values(),
            'hostIds' => $hosts->map(fn (User $host) => $host->id)->values(),
            'guests' => $booking->guests->map(fn ($guest) => $guest->email)->values(),
            'timeline' => $this->timeline($booking, $timezone, $history ?? new Collection),
            'answers' => $booking->answers->map(fn ($answer) => [
                'label' => $answer->label,
                'answer' => $answer->answer,
            ])->values(),
            'canCancel' => $booking->isChangeable(),
            'reassignableHosts' => $booking->isChangeable()
                ? $booking->reassignableHosts()
                    ->map(fn (User $host) => ['id' => $host->id, 'name' => $host->name])
                    ->values()
                : [],
            'awayHostNames' => $booking->isChangeable()
                ? $booking->awayHosts()->map(fn (User $host) => $host->name)->values()
                : [],
            'noShow' => $booking->no_show_at !== null,
            'canMarkNoShow' => $booking->canBeMarkedNoShow() && Gate::allows('cancel', $booking),
            'canApprove' => $booking->status === BookingStatus::Pending
                && $booking->starts_at->isFuture()
                && Gate::allows('approve', $booking),
        ];
    }

    /**
     * Confirm a pending booking request as one of its hosts.
     */
    public function approve(Request $request, Team $current_team, Booking $booking, ApproveBooking $approveBooking): RedirectResponse
    {
        Gate::authorize('approve', $booking);

        $approveBooking->handle($booking, $request->user());

        $booking->refresh()->status === BookingStatus::Confirmed
            ? Inertia::flash('toast', ['type' => 'success', 'message' => __('Meeting confirmed.')])
            : Inertia::flash('toast', ['type' => 'error', 'message' => __('This booking is no longer awaiting approval.')]);

        return back();
    }

    /**
     * Turn down a pending booking request as one of its hosts.
     */
    public function decline(Request $request, Team $current_team, Booking $booking, DeclineBooking $declineBooking): RedirectResponse
    {
        Gate::authorize('cancel', $booking);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $declineBooking->handle($booking, $request->user(), $validated['reason'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Request declined.')]);

        return back();
    }

    /**
     * Hand a meeting whose host is away to someone else free at the same time.
     */
    public function handOff(Team $current_team, Booking $booking, HandOffBooking $handOffBooking): RedirectResponse
    {
        Gate::authorize('cancel', $booking);

        try {
            $replacement = $handOffBooking->handle($booking);
        } catch (SlotUnavailableException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Moved to :name.', ['name' => $replacement->host->name])]);

        return back();
    }

    /**
     * Hand every upcoming meeting whose host is away to someone free.
     *
     * Meetings nobody else can take are left where they are and counted, so
     * the viewer knows how many still need rescheduling or cancelling.
     */
    public function handOffAll(Request $request, Team $current_team, ScopeFilter $scopes, HandOffBooking $handOffBooking): RedirectResponse
    {
        $user = $request->user();

        $bookings = $this->baseQuery($current_team, $user, $scopes, $scopes->defaultScope($current_team, $user))
            ->active()
            ->where('starts_at', '>', now())
            ->withHostAway()
            ->orderBy('starts_at')
            ->get();

        $moved = 0;
        $stuck = 0;

        foreach ($bookings as $booking) {
            if (Gate::denies('cancel', $booking)) {
                continue;
            }

            try {
                $handOffBooking->handle($booking);
                $moved++;
            } catch (SlotUnavailableException) {
                $stuck++;
            }
        }

        $message = trans_choice('{0} No meetings could be moved.|{1} Moved 1 meeting.|[2,*] Moved :count meetings.', $moved, ['count' => $moved]);

        if ($stuck > 0) {
            $message .= ' '.trans_choice('{1} 1 has nobody free to take it.|[2,*] :count have nobody free to take them.', $stuck, ['count' => $stuck]);
        }

        Inertia::flash('toast', ['type' => $stuck > 0 ? 'error' : 'success', 'message' => $message]);

        return back();
    }

    /**
     * Record that the invitee did not turn up.
     */
    public function markNoShow(Team $current_team, Booking $booking, RecordNoShow $recordNoShow): RedirectResponse
    {
        Gate::authorize('cancel', $booking);

        if (! $booking->canBeMarkedNoShow()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Only a confirmed meeting that has started can be marked a no-show.')]);

            return back();
        }

        $recordNoShow->handle($booking, noShow: true);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Marked as a no-show.')]);

        return back();
    }

    /**
     * Take back a no-show recorded by mistake.
     */
    public function undoNoShow(Team $current_team, Booking $booking, RecordNoShow $recordNoShow): RedirectResponse
    {
        Gate::authorize('cancel', $booking);

        $recordNoShow->handle($booking, noShow: false);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('No-show removed.')]);

        return back();
    }

    /**
     * Save a host's private notes against a meeting.
     */
    public function updateNotes(Request $request, Team $current_team, Booking $booking): RedirectResponse
    {
        Gate::authorize('view', $booking);

        $validated = $request->validate([
            'host_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $booking->update(['host_notes' => $validated['host_notes'] ?? null]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notes saved.')]);

        return back();
    }

    /**
     * Get someone's initials for the invitee avatar.
     */
    protected function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return strtoupper(mb_substr($parts[0] ?? '', 0, 1).mb_substr($parts[1] ?? '', 0, 1));
    }

    /**
     * Get the heading a meeting is listed under.
     */
    protected function dateLabel(CarbonInterface $localStart, string $timezone): string
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $date = CarbonImmutable::parse($localStart)->startOfDay();

        return match (true) {
            $date->equalTo($today) => 'Today',
            $date->equalTo($today->addDay()) => 'Tomorrow',
            $date->equalTo($today->subDay()) => 'Yesterday',
            default => $localStart->format('l, j F Y'),
        };
    }
}
