<?php

namespace App\Http\Controllers\Scheduling;

use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\SaveGroupRequest;
use App\Models\AvailabilitySchedule;
use App\Models\DepartmentPage;
use App\Models\EventType;
use App\Models\Group;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class GroupController extends Controller
{
    /**
     * Display the team's member groups.
     */
    public function index(Request $request, Team $current_team): Response
    {
        Gate::authorize('viewAny', Group::class);

        $groups = $current_team->groups()
            ->with(['members:id,name,email', 'team:id,slug', 'availabilitySchedule:id,name', 'pageEventTypes:id', 'eventTypes:id,group_id'])
            ->orderBy('name')
            ->get();

        return Inertia::render('scheduling/groups/Index', [
            'groups' => $groups->map(fn (Group $group) => $this->toPayload($group)),
            'teamMembers' => $current_team->members()->get(['users.id', 'users.name', 'users.email'])
                ->map(fn (User $member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                ]),
            'canManage' => $request->user()->can('create', [Group::class, $current_team]),
            'organizationUrl' => $current_team->slug === null
                ? null
                : route('book.page', ['page' => $current_team->slug]),
            'departmentPages' => $current_team->departmentPages()
                ->with('groups:id,name')
                ->orderBy('name')
                ->get()
                ->map(fn (DepartmentPage $page) => [
                    'id' => $page->id,
                    'slug' => $page->slug,
                    'name' => $page->name,
                    'description' => $page->description,
                    'groupIds' => $page->groups->pluck('id')->values(),
                    'groupNames' => $page->groups->sortBy('name')->pluck('name')->values(),
                    'url' => route('book.departments', ['page' => $current_team->slug, 'departmentPage' => $page->slug]),
                ]),
            'sharedSchedules' => $current_team->availabilitySchedules()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (AvailabilitySchedule $schedule) => [
                    'value' => $schedule->id,
                    'label' => $schedule->name,
                ]),
            'eventTypes' => $current_team->eventTypes()
                ->with('owner:id,name')
                ->orderBy('name')
                ->get(['id', 'user_id', 'name', 'kind', 'is_active'])
                ->map(fn (EventType $eventType) => [
                    'id' => $eventType->id,
                    'name' => $eventType->name,
                    'kindLabel' => $eventType->kind->label(),
                    'ownerName' => $eventType->owner?->name,
                    'isActive' => $eventType->is_active,
                ]),
        ]);
    }

    /**
     * Store a new group.
     */
    public function store(SaveGroupRequest $request, Team $current_team): RedirectResponse
    {
        Gate::authorize('create', [Group::class, $current_team]);

        DB::transaction(function () use ($request, $current_team) {
            $group = $current_team->groups()->create(
                $request->safe()->only(['name', 'description', 'availability_schedule_id']),
            );

            $this->syncMembers($group, $request->validated('member_ids'));
            $this->syncPageEventTypes($group, $request);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group created.')]);

        return to_route('groups.index', ['current_team' => $current_team->slug]);
    }

    /**
     * Update a group.
     */
    public function update(SaveGroupRequest $request, Team $current_team, Group $group): RedirectResponse
    {
        Gate::authorize('update', $group);

        DB::transaction(function () use ($request, $group) {
            $group->update($request->safe()->only(['name', 'description', 'availability_schedule_id']));

            $this->syncMembers($group, $request->validated('member_ids'));
            $this->syncPageEventTypes($group, $request);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group updated.')]);

        return to_route('groups.index', ['current_team' => $current_team->slug]);
    }

    /**
     * Delete a group.
     *
     * Event types hosting from it fall back to their pinned hosts.
     */
    public function destroy(Team $current_team, Group $group): RedirectResponse
    {
        Gate::authorize('delete', $group);

        $group->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group deleted.')]);

        return to_route('groups.index', ['current_team' => $current_team->slug]);
    }

    /**
     * Sync the members, keeping the order they were given as the priority.
     *
     * @param  array<int, int>  $memberIds
     */
    protected function syncMembers(Group $group, array $memberIds): void
    {
        $payload = [];

        foreach (array_values(array_unique($memberIds)) as $position => $memberId) {
            $payload[$memberId] = ['priority' => $position];
        }

        $group->members()->sync($payload);
    }

    /**
     * Sync the event types shown on the team's page, when the form sent them.
     */
    protected function syncPageEventTypes(Group $group, SaveGroupRequest $request): void
    {
        if (! $request->has('page_event_type_ids')) {
            return;
        }

        $group->pageEventTypes()->sync($request->validated('page_event_type_ids') ?? []);
    }

    /**
     * Present a group for the listing.
     *
     * @return array<string, mixed>
     */
    protected function toPayload(Group $group): array
    {
        return [
            'id' => $group->id,
            'slug' => $group->slug,
            'name' => $group->name,
            'description' => $group->description,
            'availabilityScheduleId' => $group->availability_schedule_id,
            'availabilityScheduleName' => $group->availabilitySchedule?->name,
            /*
             * Everything the team's page lists: event types hosting from the
             * team plus those picked for its page, each counted once.
             */
            'eventTypeCount' => $group->eventTypes->pluck('id')
                ->merge($group->pageEventTypes->pluck('id'))
                ->unique()
                ->count(),
            /*
             * Each team has its own public landing page listing just its event
             * types. Built here rather than in the component so the URL shape
             * lives with the route that serves it.
             */
            'bookingUrl' => route('book.event-type', [
                'page' => $group->team->slug,
                'eventType' => $group->slug,
            ]),
            'members' => $group->members->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
            ])->values(),
            'memberIds' => $group->members->pluck('id')->values(),
            'pageEventTypeIds' => $group->pageEventTypes->pluck('id')->values(),
        ];
    }
}
