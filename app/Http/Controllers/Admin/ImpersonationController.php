<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Lets a super admin see the app exactly as one of an organization's members
 * does, and return to their own account afterwards.
 *
 * Both ends are written to the organization's activity log, under the super
 * admin's name. While it lasts, RestrictWhileImpersonating keeps the member's
 * own account security (password, email, 2FA, passkeys, API keys) out of reach.
 */
class ImpersonationController extends Controller
{
    /**
     * Sign in as a member of the organization.
     */
    public function store(Request $request, Team $team, User $user, ActivityLogger $activity): RedirectResponse
    {
        Gate::authorize('accessAdminConsole');

        // One level only: returning must always land on a real super admin.
        abort_if(Impersonation::active($request), 403);

        // Scoped to the organization the console row belongs to, so a stray
        // id cannot reach an account outside it.
        abort_unless($user->belongsToTeam($team), 404);

        // Its members are kept out of a disabled organization, so signing in
        // as one would land nowhere. Enable it first.
        abort_if($team->isDisabled(), 403, __('Enable this organization before signing in as its members.'));

        $admin = $request->user();

        abort_if($user->isSuperAdmin() || $user->is($admin), 403);

        $activity->record(
            $team,
            'impersonation.started',
            __(':admin signed in as :member', ['admin' => $admin->name, 'member' => $user->name]),
            $user,
            actor: $admin,
        );

        Auth::guard('web')->login($user);

        $request->session()->regenerate();
        $request->session()->put(Impersonation::IMPERSONATOR_KEY, $admin->id);
        $request->session()->put(Impersonation::TEAM_KEY, $team->id);

        return redirect()->route('dashboard', ['current_team' => $team->slug]);
    }

    /**
     * Return to the super admin's own account.
     */
    public function destroy(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $admin = Impersonation::impersonator($request);

        abort_if($admin === null, 403);

        $member = $request->user();
        $team = Team::find($request->session()->get(Impersonation::TEAM_KEY));

        if ($team !== null) {
            $activity->record(
                $team,
                'impersonation.ended',
                __(':admin stopped signing in as :member', ['admin' => $admin->name, 'member' => $member->name]),
                $member,
                actor: $admin,
            );
        }

        Auth::guard('web')->login($admin);

        $request->session()->regenerate();
        $request->session()->forget([Impersonation::IMPERSONATOR_KEY, Impersonation::TEAM_KEY]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Back to your own account.')]);

        return redirect()->route('admin.organizations.index');
    }
}
