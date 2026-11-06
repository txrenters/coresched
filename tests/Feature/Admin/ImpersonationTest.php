<?php

use App\Enums\TeamRole;
use App\Models\ActivityLog;
use App\Models\ApiKey;
use App\Models\Team;
use App\Models\User;
use App\Support\Impersonation;

beforeEach(function () {
    $this->operator = User::factory()->create(['is_super_admin' => true]);
    $this->team = Team::factory()->create();
    $this->member = User::factory()->create();
    $this->team->members()->attach($this->member, ['role' => TeamRole::Admin->value]);
});

function startImpersonating(object $test): void
{
    $test->actingAs($test->operator)
        ->post(route('admin.impersonation.store', ['team' => $test->team->slug, 'user' => $test->member->id]))
        ->assertRedirect(route('dashboard', ['current_team' => $test->team->slug]));
}

test('a super admin signs in as a member and lands in that organization', function () {
    startImpersonating($this);

    $this->assertAuthenticatedAs($this->member);
    expect(session(Impersonation::IMPERSONATOR_KEY))->toBe($this->operator->id);

    $this->assertDatabaseMissing('activity_logs', ['team_id' => $this->team->id, 'event' => 'impersonation.started']);
});

/** Send the impersonation log to a temporary file, and return its path. */
function captureImpersonationLog(): string
{
    $logPath = tempnam(sys_get_temp_dir(), 'impersonation');
    config(['logging.channels.impersonation' => ['driver' => 'single', 'path' => $logPath]]);

    return $logPath;
}

/** Create an event type through the app, which the activity log records. */
function createEventType(object $test): void
{
    $test->post(route('scheduling.store', ['current_team' => $test->team->slug]), [
        'name' => 'Property Tour',
        'slug' => 'property-tour',
        'kind' => 'one_on_one',
        'duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'seats_per_slot' => 1,
        'date_range_type' => 'rolling_days',
        'rolling_days' => 60,
        'location_type' => 'custom_link',
        'location_detail' => 'https://example.com/room',
        'is_active' => true,
        'is_hidden' => false,
    ])->assertRedirect();
}

test('both ends are logged for super admins and never for the organization', function () {
    $logPath = captureImpersonationLog();

    startImpersonating($this);
    $this->delete(route('impersonation.destroy'));

    $log = file_get_contents($logPath);
    unlink($logPath);

    expect($log)
        ->toContain('A super admin signed in as a member.')
        ->toContain('A super admin stopped signing in as a member.')
        ->toContain('"admin_id":'.$this->operator->id)
        ->toContain('"member_id":'.$this->member->id)
        ->toContain('"team_id":'.$this->team->id);

    $this->assertDatabaseMissing('activity_logs', ['team_id' => $this->team->id, 'user_id' => $this->operator->id]);
});

test('an action taken while signed in as a member names the super admin only in their log', function () {
    $logPath = captureImpersonationLog();
    $this->member->switchTeam($this->team);
    startImpersonating($this);

    createEventType($this);

    $log = file_get_contents($logPath);
    unlink($logPath);

    $entry = ActivityLog::query()->where('event', 'event_type.created')->sole();

    expect($entry->user_id)->toBe($this->member->id)
        ->and($entry->actor_name)->toBe($this->member->name)
        ->and(json_encode($entry->properties))->not->toContain($this->operator->name)
        ->and($log)->toContain('A super admin acted while signed in as a member.')
        ->and($log)->toContain('"admin_id":'.$this->operator->id)
        ->and($log)->toContain('"member_id":'.$this->member->id)
        ->and($log)->toContain('"activity_log_id":'.$entry->id);
});

test('an action is still attributed after the super admin loses that role mid-session', function () {
    $logPath = captureImpersonationLog();
    $this->member->switchTeam($this->team);
    startImpersonating($this);
    $this->operator->forceFill(['is_super_admin' => false])->save();

    createEventType($this);

    $log = file_get_contents($logPath);
    unlink($logPath);

    expect($log)
        ->toContain('A super admin acted while signed in as a member.')
        ->toContain('"admin_id":'.$this->operator->id)
        ->toContain('"admin_still_super_admin":false');
});

test('a member acting for themselves is not written to the impersonation log', function () {
    $logPath = captureImpersonationLog();
    $this->member->switchTeam($this->team);
    $this->actingAs($this->member);

    createEventType($this);

    $log = file_get_contents($logPath);
    unlink($logPath);

    expect(ActivityLog::query()->where('event', 'event_type.created')->exists())->toBeTrue()
        ->and($log)->toBe('');
});

test('the banner knows who to return to', function () {
    startImpersonating($this);

    $this->get(route('dashboard', ['current_team' => $this->team->slug]))
        ->assertInertia(fn ($page) => $page->where('impersonator.name', $this->operator->name));
});

test('returning puts the super admin back in their own account', function () {
    startImpersonating($this);

    $this->delete(route('impersonation.destroy'))
        ->assertRedirect(route('admin.organizations.index'));

    $this->assertAuthenticatedAs($this->operator);
    expect(session()->has(Impersonation::IMPERSONATOR_KEY))->toBeFalse();
    $this->assertDatabaseMissing('activity_logs', ['team_id' => $this->team->id, 'event' => 'impersonation.ended']);
});

test('a session that is not an impersonation cannot return anywhere', function () {
    $this->actingAs($this->member)
        ->delete(route('impersonation.destroy'))
        ->assertForbidden();

    $this->assertAuthenticatedAs($this->member);
});

test('only a super admin can sign in as someone', function () {
    $outsider = User::factory()->create();
    $this->team->members()->attach($outsider, ['role' => TeamRole::Admin->value]);

    $this->actingAs($outsider)
        ->post(route('admin.impersonation.store', ['team' => $this->team->slug, 'user' => $this->member->id]))
        ->assertForbidden();

    $this->assertAuthenticatedAs($outsider);
});

test('nobody can be signed into through an organization they do not belong to', function () {
    $stranger = User::factory()->create();

    $this->actingAs($this->operator)
        ->post(route('admin.impersonation.store', ['team' => $this->team->slug, 'user' => $stranger->id]))
        ->assertNotFound();

    $this->assertAuthenticatedAs($this->operator);
});

test('another super admin can never be signed into', function () {
    $otherOperator = User::factory()->create(['is_super_admin' => true]);
    $this->team->members()->attach($otherOperator, ['role' => TeamRole::Admin->value]);

    $this->actingAs($this->operator)
        ->post(route('admin.impersonation.store', ['team' => $this->team->slug, 'user' => $otherOperator->id]))
        ->assertForbidden();

    $this->assertAuthenticatedAs($this->operator);
});

test('the members account security is locked while signed in as them', function (string $method, string $routeName) {
    startImpersonating($this);

    $this->{$method}(route($routeName))->assertForbidden();
})->with([
    'password' => ['put', 'user-password.update'],
    'profile and email' => ['patch', 'profile.update'],
    'account deletion' => ['delete', 'profile.destroy'],
    'two-factor' => ['post', 'two-factor.enable'],
    'api keys' => ['post', 'api-keys.store'],
]);

test('their organization API keys can be viewed while signed in as them', function () {
    ApiKey::issue('Client portal', $this->member, $this->team);
    $this->member->switchTeam($this->team);
    startImpersonating($this);

    $this->get(route('api-keys.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('keys', 1)->where('keys.0.name', 'Client portal'));
});

test('their organization API keys cannot be changed while signed in as them', function (string $method, string $routeName) {
    ['key' => $key] = ApiKey::issue('Client portal', $this->member, $this->team);
    $this->member->switchTeam($this->team);
    startImpersonating($this);

    $this->{$method}(route($routeName, $key), ['name' => 'Renamed'])->assertForbidden();

    expect($key->fresh())
        ->name->toBe('Client portal')
        ->revoked_at->toBeNull();
})->with([
    'edit' => ['patch', 'api-keys.update'],
    'revoke' => ['delete', 'api-keys.destroy'],
    'delete' => ['delete', 'api-keys.forget'],
]);

test('their password is untouched when a change is attempted', function () {
    $hash = $this->member->password;
    startImpersonating($this);

    $this->put(route('user-password.update'), [
        'current_password' => 'password',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertForbidden();

    expect($this->member->fresh()->password)->toBe($hash);
});
