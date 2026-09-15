<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Teams\CreateTeamUser;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\AddOrganizationMemberRequest;
use App\Models\Team;
use App\Models\User;
use App\Policies\TeamPolicy;
use App\Services\Activity\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every organization in the installation for the operator who looks after all
 * of them, and the ones they administer for everyone else.
 */
class OrganizationDirectoryController extends Controller
{
    /**
     * List the organizations and who runs them.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewOrganizations');

        $viewer = $request->user();
        $search = $request->string('search')->toString();

        $organizations = Team::query()
            ->unless($viewer->isSuperAdmin(), fn ($query) => $query->whereIn(
                'id',
                $viewer->teams()->wherePivot('role', TeamRole::Admin->value)->pluck('teams.id'),
            ))
            ->with(['members:id,name,email'])
            ->withCount(['eventTypes', 'bookings', 'groups'])
            ->when(filled($search), fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->get();

        return Inertia::render('organizations/Index', [
            'organizations' => $organizations->map(fn (Team $team) => $this->toPayload($team)),
            'search' => $search,
            'roles' => TeamRole::assignable(),
        ]);
    }

    /**
     * Put someone in the organization.
     *
     * An address that already has an account joins the organization; a new one
     * gets an account created for it, which is CreateTeamUser's job and emails
     * a link to set a password.
     *
     * Only a super admin puts an existing account straight in. An organization
     * admin invites that person instead, as on the organization's settings, so
     * nobody lands in an organization they did not agree to join.
     */
    public function storeMember(AddOrganizationMemberRequest $request, Team $team, CreateTeamUser $createTeamUser): RedirectResponse
    {
        Gate::authorize('addMember', $team);

        $role = TeamRole::from($request->validated('role'));
        $email = mb_strtolower($request->validated('email'));

        $existing = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($existing !== null) {
            if ($existing->belongsToTeam($team)) {
                return back()->withErrors(['email' => __(':name is already in this organization.', ['name' => $existing->name])]);
            }

            if (! $request->user()->isSuperAdmin()) {
                return back()->withErrors(['email' => __(':name already has an account. Invite them from the organization\'s settings instead.', ['name' => $existing->name])]);
            }

            $team->memberships()->create(['user_id' => $existing->id, 'role' => $role]);

            app(ActivityLogger::class)->record(
                $team,
                'member.added',
                'Added '.$existing->name.' as '.$role->label(),
                $existing,
                ['role' => $role->value],
            );

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => __(':name has been added.', ['name' => $existing->name]),
            ]);

            return back();
        }

        Gate::authorize('createMember', $team);

        $user = $createTeamUser->handle($team, $request->validated('name'), $email, $role);

        app(ActivityLogger::class)->record(
            $team,
            'member.created',
            'Created an account for '.$user->name.' as '.$role->label(),
            $user,
            ['role' => $role->value],
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Account created. :name can set a password from the email we just sent.', ['name' => $user->name]),
        ]);

        return back();
    }

    /**
     * Change the role someone holds in the organization.
     */
    public function updateMember(Request $request, Team $team, User $user): RedirectResponse
    {
        Gate::authorize('updateMember', $team);

        $validated = $request->validate([
            'role' => ['required', Rule::enum(TeamRole::class)],
        ]);

        $role = TeamRole::from($validated['role']);

        if ($role !== TeamRole::Admin && app(TeamPolicy::class)->isLastAdmin($user, $team)) {
            return back()->withErrors(['role' => __('The last administrator cannot be demoted.')]);
        }

        $team->memberships()->where('user_id', $user->id)->firstOrFail()->update(['role' => $role]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member role updated.')]);

        return back();
    }

    /**
     * Take someone out of the organization. The account itself stays.
     */
    public function destroyMember(Team $team, User $user): RedirectResponse
    {
        Gate::authorize('removeMember', $team);

        if (app(TeamPolicy::class)->isLastAdmin($user, $team)) {
            return back()->withErrors(['member' => __('The last administrator cannot be removed.')]);
        }

        $team->memberships()->where('user_id', $user->id)->delete();

        if ($user->isCurrentTeam($team)) {
            $next = $user->fallbackTeam();

            $next === null
                ? $user->forceFill(['current_team_id' => null])->save()
                : $user->switchTeam($next);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return back();
    }

    /**
     * Describe one organization for the directory.
     *
     * @return array<string, mixed>
     */
    protected function toPayload(Team $team): array
    {
        return [
            'id' => $team->id,
            'name' => $team->name,
            'slug' => $team->slug,
            'logoUrl' => $team->logoUrl(),
            'eventTypeCount' => $team->event_types_count,
            'bookingCount' => $team->bookings_count,
            'groupCount' => $team->groups_count,
            'createdAt' => $team->created_at?->toFormattedDateString(),
            'settingsUrl' => route('teams.edit', ['team' => $team->slug]),
            'members' => $team->members
                ->map(fn (User $member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    // members() carries the Membership pivot, which casts the
                    // role, unlike the plain pivot on the user side.
                    'role' => $member->getRelation('pivot')->role->value,
                    'roleLabel' => $member->getRelation('pivot')->role->label(),
                ])
                ->values(),
        ];
    }
}
