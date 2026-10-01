<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\CreateTeam;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\DeleteTeamRequest;
use App\Http\Requests\Teams\SaveTeamRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\Sms\TwilioClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class TeamController extends Controller
{
    /**
     * Display a listing of the user's teams.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('teams/Index', [
            'teams' => $user->toUserTeams(includeCurrent: true),
        ]);
    }

    /**
     * Store a newly created team.
     */
    public function store(SaveTeamRequest $request, CreateTeam $createTeam): RedirectResponse
    {
        Gate::authorize('create', Team::class);

        $team = $createTeam->handle($request->user(), $request->validated('name'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Organization created.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Show the team edit page.
     */
    public function edit(Request $request, Team $team): Response
    {
        $user = $request->user();

        return Inertia::render('teams/Edit', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
                'logoUrl' => $team->logoUrl(),
                'welcomeMessage' => $team->welcome_message,
                'websiteUrl' => $team->website_url,
                'timezone' => $team->timezone,
                'smsFromNumber' => $team->sms_from_number,
            ],
            'permissions' => $user->toTeamPermissions($team),
            'timezones' => timezone_identifiers_list(),
            /*
             * The numbers the installation's Twilio account owns, for the admin
             * to pick this organization's from. Only fetched for someone who
             * can actually change it -- everyone else has no use for the list.
             */
            'sms' => [
                'configured' => app(TwilioClient::class)->isConfigured(),
                'numbers' => $user->can('update', $team) ? app(TwilioClient::class)->numbers() : [],
            ],
        ]);
    }

    /**
     * Update the specified team.
     */
    public function update(SaveTeamRequest $request, Team $team): RedirectResponse
    {
        Gate::authorize('update', $team);

        $team = DB::transaction(function () use ($request, $team) {
            $team = Team::whereKey($team->id)->lockForUpdate()->firstOrFail();

            $attributes = ['name' => $request->validated('name')];

            foreach (['welcome_message', 'website_url', 'timezone', 'sms_from_number'] as $field) {
                if ($request->has($field)) {
                    $attributes[$field] = $request->validated($field);
                }
            }

            $attributes = [...$attributes, ...$this->resolveLogo($request, $team)];

            $team->update($attributes);

            return $team;
        });

        app(ActivityLogger::class)->record(
            $team,
            'organization.updated',
            'Updated the organization settings',
            $team,
            ['fields' => array_keys($request->validated())],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Organization updated.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Switch the user's current team.
     */
    public function switch(Request $request, Team $team): RedirectResponse
    {
        abort_unless(
            $request->user()->belongsToTeam($team) || $request->user()->isSuperAdmin(),
            403,
        );

        $request->user()->switchTeam($team);

        return back();
    }

    /**
     * Leave the specified team.
     */
    public function leave(Request $request, Team $team): RedirectResponse
    {
        Gate::authorize('leave', $team);

        $user = $request->user();

        $fallbackTeam = $user->isCurrentTeam($team)
            ? $user->fallbackTeam($team)
            : null;

        $team->memberships()
            ->where('user_id', $user->id)
            ->delete();

        if ($fallbackTeam) {
            $user->switchTeam($fallbackTeam);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You left the organization ":name"', ['name' => $team->name])]);

        return to_route('teams.index');
    }

    /**
     * Delete the specified team.
     */
    public function destroy(DeleteTeamRequest $request, Team $team): RedirectResponse
    {
        $user = $request->user();
        $fallbackTeam = $user->isCurrentTeam($team)
            ? $user->fallbackTeam($team)
            : null;

        DB::transaction(function () use ($user, $team) {
            User::where('current_team_id', $team->id)
                ->where('id', '!=', $user->id)
                ->each(function (User $affectedUser) use ($team) {
                    $next = $affectedUser->fallbackTeam($team);

                    $next === null
                        ? $affectedUser->forceFill(['current_team_id' => null])->save()
                        : $affectedUser->switchTeam($next);
                });

            $team->invitations()->delete();
            $team->memberships()->delete();
            $team->delete();
        });

        if ($fallbackTeam) {
            $user->switchTeam($fallbackTeam);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Organization deleted.')]);

        return to_route('teams.index');
    }

    /**
     * Work out what should happen to the organization logo on this request.
     *
     * Returns the attributes to merge into the update: an empty array when the
     * request says nothing about the logo, so an unrelated save never clears it.
     * The previous file is deleted only once its replacement is safely stored.
     *
     * @return array<string, string|null>
     */
    protected function resolveLogo(SaveTeamRequest $request, Team $team): array
    {
        if ($request->boolean('remove_logo')) {
            $this->deleteLogo($team->logo_path);

            return ['logo_path' => null];
        }

        if (! $request->hasFile('logo')) {
            return [];
        }

        $path = $request->file('logo')->store('team-logos', 'public');

        if ($path === false) {
            throw new RuntimeException('The team logo could not be stored.');
        }

        $this->deleteLogo($team->logo_path);

        return ['logo_path' => $path];
    }

    /**
     * Remove a stored logo, ignoring one that has already gone.
     */
    protected function deleteLogo(?string $path): void
    {
        if ($path !== null && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
