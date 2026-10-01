<?php

namespace App\Http\Middleware;

use App\Models\Team;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'currentTeam' => fn () => $user?->currentTeam ? $user->toUserTeam($user->currentTeam) : null,
            'teams' => fn () => $user?->toUserTeams(includeCurrent: true) ?? [],
            // Only the badge count is shared; the panel fetches the list on open.
            'unreadNotifications' => fn () => $user?->unreadNotifications()->count() ?? 0,
            // Gates the Logs nav entry; the controller enforces it as well.
            'isSuperAdmin' => (bool) $user?->isSuperAdmin(),
            // Hides "New organization" in the switcher; TeamPolicy enforces it.
            'canCreateTeam' => (bool) $user?->can('create', Team::class),
            // Gates the Users nav entry; the controller enforces it as well.
            'canViewUsers' => (bool) $user?->can('viewUsers'),
            // Gates the API keys tab of the organization settings; the route enforces it as well.
            'canManageApiKeys' => (bool) $user?->can('manageApiKeys'),
            // Gates the Settings nav entry, which opens the current organization's settings.
            'canManageOrganization' => (bool) ($user?->currentTeam && $user->can('update', $user->currentTeam)),
            // Gates the Organizations nav entry; the controller enforces it as well.
            'canViewOrganizations' => (bool) $user?->can('viewOrganizations'),
        ];
    }
}
