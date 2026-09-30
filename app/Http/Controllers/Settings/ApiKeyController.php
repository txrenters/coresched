<?php

namespace App\Http\Controllers\Settings;

use App\Enums\WebhookScope;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
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
            // Likewise the webhook signing secret, flashed by update().
            'issuedWebhookSecret' => $request->session()->get('issued_webhook_secret'),
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
                    'webhookUrl' => $key->webhook_url,
                    'webhookScope' => $key->webhook_scope->value,
                ]),
            'webhookScopes' => collect(WebhookScope::cases())->map(fn (WebhookScope $scope): array => [
                'value' => $scope->value,
                'label' => $scope->label(),
            ]),
        ]);
    }

    /**
     * Rename a key, or point its booking webhooks at an endpoint.
     *
     * A key that gains an endpoint gets a signing secret, shown once like the
     * key itself; asking to rotate makes a new one. Clearing the URL stops the
     * webhooks and drops the secret.
     */
    public function update(Request $request, ApiKey $apiKey): RedirectResponse
    {
        abort_unless(
            $request->user()?->isSuperAdmin() || $apiKey->team_id === $request->user()?->current_team_id,
            403,
        );

        abort_if($apiKey->isRevoked(), 422, 'A revoked key cannot be changed.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'webhook_url' => ['nullable', 'url:https,http', 'max:2048'],
            'webhook_scope' => [Rule::enum(WebhookScope::class)],
            'rotate_secret' => ['boolean'],
        ]);

        $apiKey->forceFill(['name' => $validated['name']])->save();

        $secret = $apiKey->pointWebhookAt(
            $validated['webhook_url'] ?? null,
            $request->boolean('rotate_secret'),
            WebhookScope::tryFrom($validated['webhook_scope'] ?? '') ?? WebhookScope::Referenced,
        );

        Log::info('An API key was updated.', [
            'api_key_id' => $apiKey->id,
            'webhook_url' => $apiKey->webhook_url,
            'webhook_scope' => $apiKey->webhook_scope->value,
            'secret_issued' => $secret !== null,
            'updated_by' => $request->user()?->id,
        ]);

        $redirect = to_route('api-keys.index')->with('success', "\"{$apiKey->name}\" is saved.");

        return $secret === null ? $redirect : $redirect->with('issued_webhook_secret', [
            'name' => $apiKey->name,
            'secret' => $secret,
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
        ]);

        /** @var User $user */
        $user = $request->user();

        // Always the organization being looked after right now. A super admin
        // who wants a key for another one switches to it, the same way they
        // do for everything else about that organization.
        $team = $user->currentTeam;

        abort_if($team === null, 403);

        ['key' => $key, 'token' => $token] = ApiKey::issue($validated['name'], $user, $team);

        Log::info('An API key was issued.', [
            'api_key_id' => $key->id,
            'name' => $key->name,
            'team_id' => $key->team_id,
            'issued_by' => $user?->id,
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

    /**
     * Delete a key for good, once it has been revoked.
     *
     * Revoking is what stops a key working; this only clears the record. It
     * is deliberately a second step, so a key in use cannot be swept away by
     * one stray click — and so the list keeps saying what happened until
     * somebody decides it no longer needs to.
     */
    public function forget(Request $request, ApiKey $apiKey): RedirectResponse
    {
        abort_unless(
            $request->user()?->isSuperAdmin() || $apiKey->team_id === $request->user()?->current_team_id,
            403,
        );

        abort_unless($apiKey->isRevoked(), 422, 'Revoke the key before deleting it.');

        $apiKey->delete();

        Log::info('An API key was deleted.', [
            'api_key_id' => $apiKey->id,
            'deleted_by' => $request->user()?->id,
        ]);

        return back()->with('success', "\"{$apiKey->name}\" is gone.");
    }
}
