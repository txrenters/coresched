<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\JoinTeamFromInvitation;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\CreateTeamInvitationRequest;
use App\Http\Requests\Teams\RespondToTeamInvitationRequest;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use App\Services\Activity\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class TeamInvitationController extends Controller
{
    /**
     * Store a newly created invitation.
     */
    public function store(CreateTeamInvitationRequest $request, Team $team): RedirectResponse
    {
        Gate::authorize('inviteMember', $team);

        $invitation = $team->invitations()->create([
            'email' => $request->validated('email'),
            'role' => TeamRole::from($request->validated('role')),
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(3),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));

        app(ActivityLogger::class)->record(
            $team,
            'invitation.sent',
            'Invited '.$invitation->email.' to the organization',
            $invitation,
            ['role' => $invitation->role->value],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Cancel the specified invitation.
     */
    public function destroy(Team $team, TeamInvitation $invitation): RedirectResponse
    {
        abort_unless($invitation->team_id === $team->id, 404);

        Gate::authorize('cancelInvitation', $team);

        $email = $invitation->email;

        $invitation->delete();

        app(ActivityLogger::class)->record(
            $team,
            'invitation.cancelled',
            'Cancelled the invitation for '.$email,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation cancelled.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Show the confirmation page behind the emailed "Join Now" link.
     *
     * Opening the link changes nothing. Mail security scanners (Microsoft Safe
     * Links among them) fetch links in an email before the person does, and
     * when this GET created the account it handed the scanner a signed in
     * session and burned the invitation, leaving the real invitee with "expired
     * or already used". The account is only created by the POST from this page.
     *
     * An address that ALREADY has an account is sent to sign in instead, and is
     * deliberately never signed in automatically. That account may hold a
     * password and a second factor, and honouring a link from an inbox as proof
     * of identity would let anyone holding the email walk past both.
     */
    public function showJoin(TeamInvitation $invitation): RedirectResponse|Response
    {
        if ($redirect = $this->redirectAwayFromJoin($invitation)) {
            return $redirect;
        }

        $invitation->loadMissing(['team', 'inviter']);

        return Inertia::render('auth/JoinInvitation', [
            'invitation' => [
                'code' => $invitation->code,
                'email' => $invitation->email,
                'teamName' => $invitation->team->name,
                'roleLabel' => $invitation->role->label(),
                'inviterName' => $invitation->inviter?->name,
            ],
        ]);
    }

    /**
     * Join from the confirmation page.
     *
     * For an address with no account yet this creates one, signs the invitee
     * in and accepts the invitation in a single request, so there is no form to
     * fill in and no password to invent.
     */
    public function join(Request $request, TeamInvitation $invitation, JoinTeamFromInvitation $joinTeam): RedirectResponse
    {
        if ($redirect = $this->redirectAwayFromJoin($invitation)) {
            return $redirect;
        }

        $user = $joinTeam->handle($invitation);

        Auth::login($user);

        // A session that existed before sign-in must not survive it.
        $request->session()->regenerate();

        app(ActivityLogger::class)->record(
            $invitation->team,
            'invitation.accepted',
            $user->email.' joined the organization',
            $invitation,
            ['role' => $invitation->role->value],
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Welcome! Set a password in Settings so you can sign in again.'),
        ]);

        return to_route('dashboard', ['current_team' => $invitation->team->slug]);
    }

    /**
     * Accept the invitation.
     */
    public function accept(RespondToTeamInvitationRequest $request, TeamInvitation $invitation): RedirectResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user, $invitation) {
            $team = $invitation->team;

            $team->memberships()->firstOrCreate(
                ['user_id' => $user->id],
                ['role' => $invitation->role],
            );

            $invitation->update(['accepted_at' => now()]);

            $user->switchTeam($team);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation accepted.')]);

        return to_route('dashboard');
    }

    /**
     * Decline the invitation.
     */
    public function decline(RespondToTeamInvitationRequest $request, TeamInvitation $invitation): RedirectResponse
    {
        $invitation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation declined.')]);

        return to_route('dashboard');
    }

    /**
     * Send the visitor somewhere else when this invitation cannot create an account.
     */
    protected function redirectAwayFromJoin(TeamInvitation $invitation): ?RedirectResponse
    {
        if (! $invitation->isPending()) {
            return to_route('login')->withErrors([
                'email' => __('This invitation has expired or has already been used.'),
            ]);
        }

        $hasAccount = User::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower($invitation->email)])
            ->exists();

        return $hasAccount
            ? redirect()->route('login', ['invitation' => $invitation->code])
            : null;
    }
}
