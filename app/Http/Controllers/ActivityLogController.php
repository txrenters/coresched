<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ActivityLogController extends Controller
{
    /**
     * The event families offered as filters.
     *
     * @var array<int, array{value: string, label: string}>
     */
    protected array $kinds = [
        ['value' => 'all', 'label' => 'Everything'],
        ['value' => 'booking', 'label' => 'Bookings'],
        ['value' => 'event_type', 'label' => 'Event types'],
        ['value' => 'member', 'label' => 'Members'],
        ['value' => 'invitation', 'label' => 'Invitations'],
        ['value' => 'organization', 'label' => 'Organization'],
    ];

    /**
     * The number of entries loaded per page.
     */
    protected int $perPage = 25;

    /**
     * Show the organization's audit trail.
     */
    public function index(Request $request, Team $current_team): Response
    {
        Gate::authorize('viewActivity', $current_team);

        $user = $request->user();
        $timezone = $user->timezone ?: config('scheduling.default_timezone');

        $kind = $request->string('kind', 'all')->toString();

        if (! collect($this->kinds)->pluck('value')->contains($kind)) {
            $kind = 'all';
        }

        $entries = ActivityLog::query()
            ->where('team_id', $current_team->id)
            ->when($kind !== 'all', fn ($query) => $query->ofKind($kind))
            ->with('user:id,name')
            ->latest()
            ->paginate($this->perPage)
            ->withQueryString()
            ->through(fn (ActivityLog $entry) => [
                'id' => $entry->id,
                'event' => $entry->event,
                'kind' => str($entry->event)->before('.')->toString(),
                'description' => $entry->description,
                // The stored name survives a rename or a deleted account.
                'actorName' => $entry->user->name ?? $entry->actor_name,
                'isSystem' => $entry->user_id === null && $entry->actor_name === null,
                'properties' => $entry->properties,
                'at' => $entry->created_at->setTimezone($timezone)->isoFormat('D MMM YYYY, h:mm a'),
                'atIso' => $entry->created_at->toIso8601String(),
            ]);

        return Inertia::render('Activity', [
            'entries' => $entries,
            'kinds' => $this->kinds,
            'kind' => $kind,
        ]);
    }
}
