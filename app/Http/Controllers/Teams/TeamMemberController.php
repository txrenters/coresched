<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\CreateTeamUser;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\CreateTeamMemberRequest;
use App\Http\Requests\Teams\UpdateTeamMemberRequest;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use App\Policies\TeamPolicy;
use App\Services\Activity\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TeamMemberController extends Controller
{
    /**
     * Show the organization's members and pending invitations.
     */
    public function index(Request $request, Team $team): Response
    {
        $user = $request->user();
        $adminCount = $team->memberships()->where('role', TeamRole::Admin)->count();

        return Inertia::render('teams/Members', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            // Super admins operate across organizations rather than belonging
            // to one, so they stay off the roster people manage here. They keep
            // their place in the scheduling pickers, where they may host.
            'members' => $team->members()->where('users.is_super_admin', false)->get()->map(function (User $member) use ($adminCount) {
                /** @var Membership $membership */
                $membership = $member->getRelation('pivot');

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'avatar' => $member->avatar ?? null,
                    'role' => $membership->role->value,
                    'role_label' => $membership->role->label(),
                    // The screen hides demote and remove for this member: an
                    // organization has to keep an administrator, and the
                    // controller refuses it anyway.
                    'isLastAdmin' => $membership->role === TeamRole::Admin && $adminCount === 1,
                ];
            }),
            /*
             * Only people who manage invitations see them. Each row carries its
             * code, and the code alone is enough to join through the emailed
             * link with the role the invitation grants, so a plain member
             * shown this list could take an admin invitation meant for someone else.
             */
            'invitations' => $user->can('inviteMember', $team) || $user->can('cancelInvitation', $team)
                ? $team->invitations()
                    ->whereNull('accepted_at')
                    ->get()
                    ->map(fn ($invitation) => [
                        'code' => $invitation->code,
                        'email' => $invitation->email,
                        'role' => $invitation->role->value,
                        'role_label' => $invitation->role->label(),
                        'created_at' => $invitation->created_at->toISOString(),
                    ])
                : [],
            'permissions' => $user->toTeamPermissions($team),
            'canCreateMember' => $user->can('createMember', $team),
            'availableRoles' => TeamRole::assignable(),
        ]);
    }

    /**
     * Create a user account directly inside the organization.
     *
     * Super admin only — see TeamPolicy::createMember.
     */
    public function store(CreateTeamMemberRequest $request, Team $team, CreateTeamUser $createTeamUser): RedirectResponse
    {
        Gate::authorize('createMember', $team);

        $role = TeamRole::from($request->validated('role'));

        $user = $createTeamUser->handle(
            $team,
            $request->validated('name'),
            $request->validated('email'),
            $role,
        );

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

        return to_route('teams.members.index', ['team' => $team->slug]);
    }

    /**
     * Update the specified team member's role.
     */
    public function update(UpdateTeamMemberRequest $request, Team $team, User $user): RedirectResponse
    {
        Gate::authorize('updateMember', $team);

        $newRole = TeamRole::from($request->validated('role'));

        $membership = $team->memberships()
            ->where('user_id', $user->id)
            ->firstOrFail();

        // Demoting the last administrator would leave the organization with
        // nobody able to run it.
        abort_if(
            $newRole !== TeamRole::Admin && app(TeamPolicy::class)->isLastAdmin($user, $team),
            403,
            __('The last administrator cannot be demoted.'),
        );

        $membership->update(['role' => $newRole]);

        app(ActivityLogger::class)->record(
            $team,
            'member.role_changed',
            'Changed '.$user->name."'s role to ".$newRole->label(),
            $user,
            ['role' => $newRole->value],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member role updated.')]);

        return to_route('teams.members.index', ['team' => $team->slug]);
    }

    /**
     * Remove the specified team member.
     */
    public function destroy(Team $team, User $user): RedirectResponse
    {
        Gate::authorize('removeMember', $team);

        abort_if(
            app(TeamPolicy::class)->isLastAdmin($user, $team),
            403,
            __('The last administrator cannot be removed.'),
        );

        $team->memberships()
            ->where('user_id', $user->id)
            ->delete();

        app(ActivityLogger::class)->record(
            $team,
            'member.removed',
            'Removed '.$user->name.' from the organization',
            $user,
        );

        /*
         * Land them somewhere they still belong. fallbackTeam() is nullable
         * -- an account can belong to nothing once it is removed here -- so the
         * null case clears the current organization rather than reaching
         * switchTeam() with nothing, which raised a TypeError and 500'd the
         * removal back when this fell through to a personal organization.
         */
        if ($user->isCurrentTeam($team)) {
            $next = $user->fallbackTeam();

            if ($next !== null) {
                $user->switchTeam($next);
            } else {
                $user->forceFill(['current_team_id' => null])->save();
            }
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return to_route('teams.members.index', ['team' => $team->slug]);
    }
}
