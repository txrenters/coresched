<?php

namespace App\Http\Responses\Concerns;

use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

trait RedirectsToCurrentTeam
{
    /**
     * Where to send someone who has just signed in, registered or verified.
     *
     * Normally the given page inside their current organization. A super
     * admin's home is the admin console instead: they run the platform, and
     * reach an organization's workspace by signing in as one of its members.
     */
    protected function redirectPathForCurrentTeam(Request $request, string $redirect): string
    {
        if ($request->user()?->isSuperAdmin()) {
            return route('admin.organizations.index', absolute: false);
        }

        $team = $this->currentTeam($request);

        URL::defaults(['current_team' => $team->slug]);

        return "/{$team->slug}{$redirect}";
    }

    /**
     * The organization to land in.
     */
    protected function currentTeam(Request $request): Team
    {
        $user = $request->user();

        abort_if(! $user, 403);

        $team = $user->currentTeam ?? $user->fallbackTeam();

        abort_if(! $team, 403);

        return $team;
    }
}
