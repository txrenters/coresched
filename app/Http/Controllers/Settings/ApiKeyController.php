<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class ApiKeyController extends Controller
{
    /**
     * The keys our other applications use to reach the API.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('settings/ApiKeys', [
            // The only moment the secret is readable. It is flashed by store()
            // and handed over here, then gone.
            'issued' => $request->session()->get('issued_api_key'),
            'canIssueSystemWide' => (bool) $request->user()?->isSuperAdmin(),
            /** @var Collection<int, array<string, mixed>> */
            'keys' => ApiKey::query()
                ->with(['creator:id,name', 'team:id,name'])
                // An admin sees their own organization's keys; a super admin
                // sees every one, including the system-wide keys.
                ->unless($request->user()?->isSuperAdmin(), fn ($query) => $query
                    ->where('team_id', $request->user()?->current_team_id))
                ->latest()
                ->get()
                ->map(fn (ApiKey $key): array => [
                    'id' => $key->id,
                    'name' => $key->name,
                    'organization' => $key->team?->name,
                    'prefix' => $key->prefix,
                    'createdBy' => $key->creator?->name,
                    'createdAt' => $key->created_at?->toIso8601String(),
                    'lastUsedAt' => $key->last_used_at?->toIso8601String(),
                    'revokedAt' => $key->revoked_at?->toIso8601String(),
                ]),
        ]);
    }

    /**
     * Issue a key, and show it once.
     *
     * The secret is flashed rather than stored: this is the only moment it
     * exists in readable form, which is why the page says to copy it now.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'system_wide' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();

        // Scoped to the admin's own organization. Only a super admin may make
        // a key that speaks for all of them, and only by asking for one.
        $team = $user?->isSuperAdmin() && $request->boolean('system_wide')
            ? null
            : $user?->currentTeam;

        abort_if($team === null && ! $user?->isSuperAdmin(), 403);

        ['key' => $key, 'token' => $token] = ApiKey::issue($validated['name'], $user, $team);

        Log::info('An API key was issued.', [
            'api_key_id' => $key->id,
            'name' => $key->name,
            'issued_by' => $request->user()?->id,
        ]);

        return to_route('api-keys.index')->with('issued_api_key', [
            'name' => $key->name,
            'token' => $token,
        ]);
    }

    /**
     * Revoke a key, which stops it working on the next request.
     */
    public function destroy(Request $request, ApiKey $apiKey): RedirectResponse
    {
        // An admin may only revoke their own organization's keys.
        abort_unless(
            $request->user()?->isSuperAdmin() || $apiKey->team_id === $request->user()?->current_team_id,
            403,
        );

        $apiKey->forceFill(['revoked_at' => now()])->save();

        Log::info('An API key was revoked.', [
            'api_key_id' => $apiKey->id,
            'revoked_by' => $request->user()?->id,
        ]);

        return back()->with('success', "\"{$apiKey->name}\" can no longer be used.");
    }
}
