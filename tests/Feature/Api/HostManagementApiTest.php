<?php

use App\Enums\TeamRole;
use App\Models\ApiKey;
use App\Models\AvailabilitySchedule;
use App\Models\EventType;
use App\Models\Group;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Teams\AccountCreated;
use Illuminate\Support\Facades\Notification;

/**
 * Another application (TEXRA) putting an agent on a meeting's rota and
 * keeping their hours: the writes the machine-to-machine API allows, and
 * only to a key that was explicitly given them.
 */
beforeEach(function () {
    $this->travelTo('2026-10-05 12:00:00');

    $this->owner = User::factory()->create(['name' => 'Dana Fields', 'email' => 'dana@texra.net']);
    $this->team = $this->owner->currentTeam;

    $this->group = Group::factory()->for($this->team)->create(['name' => 'Agents on Duty']);
    $this->group->members()->sync([$this->owner->id => ['priority' => 0]]);

    AvailabilitySchedule::factory()->for($this->owner)->timezone('America/Chicago')->weekdays()->create();

    $this->eventType = EventType::factory()->ownedBy($this->owner)->roundRobin()->create([
        'name' => 'Call with an agent',
        'slug' => 'call-with-an-agent',
        'is_active' => true,
        'group_id' => $this->group->id,
    ]);

    ['token' => $this->token] = ApiKey::issue('TEXRA', null, $this->team, canWrite: true);
    ['token' => $this->readOnlyToken] = ApiKey::issue('Reader', null, $this->team);
});

/**
 * @param  array<string, mixed>  $body
 */
function ensureHost(array $body, ?string $token = null)
{
    return test()->postJson(route('api.v1.hosts.store', [
        'page' => test()->team->slug,
        'eventType' => 'call-with-an-agent',
    ]), $body, ['Authorization' => 'Bearer '.($token ?? test()->token)]);
}

/**
 * @param  array<string, mixed>  $body
 */
function saveHours(string $email, array $body, ?string $token = null)
{
    return test()->putJson(route('api.v1.hosts.schedule.update', [
        'page' => test()->team->slug,
        'eventType' => 'call-with-an-agent',
        'email' => $email,
    ]), $body, ['Authorization' => 'Bearer '.($token ?? test()->token)]);
}

test('a key without write permission cannot add a host', function () {
    ensureHost(['name' => 'Priya Patel', 'email' => 'priya@texasrenters.com'], $this->readOnlyToken)
        ->assertForbidden();
});

test('a system-wide key cannot add a host even with write permission', function () {
    ['token' => $system] = ApiKey::issue('System', null, null, canWrite: true);

    ensureHost(['name' => 'Priya Patel', 'email' => 'priya@texasrenters.com'], $system)
        ->assertForbidden();
});

test('a host needs a name, an email and a real timezone', function () {
    ensureHost(['name' => '', 'email' => 'not-an-email', 'timezone' => 'Mars/Olympus'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email', 'timezone']);
});

test('a new agent gets an account, a seat in the organization and on the rota, default hours and a welcome', function () {
    Notification::fake();

    $response = ensureHost([
        'name' => 'Priya Patel',
        'email' => 'Priya@TexasRenters.com',
        'timezone' => 'America/Chicago',
    ])->assertCreated();

    expect($response->json('host.email'))->toBe('priya@texasrenters.com')
        ->and($response->json('host.name'))->toBe('Priya Patel')
        ->and($response->json('host.created'))->toBeTrue()
        ->and($response->json('governs'))->toBe('personal');

    $priya = User::query()->where('email', 'priya@texasrenters.com')->sole();

    expect($priya->timezone)->toBe('America/Chicago')
        ->and($priya->teamRole($this->team))->toBe(TeamRole::Member)
        ->and($this->group->members()->whereKey($priya->id)->exists())->toBeTrue()
        ->and($priya->defaultAvailabilitySchedule()?->timezone)->toBe('America/Chicago')
        ->and($priya->defaultAvailabilitySchedule()?->rules)->toHaveCount(5);

    Notification::assertSentTo($priya, AccountCreated::class);
});

test('an existing account is used as it is and never duplicated', function () {
    Notification::fake();

    $jose = User::factory()->create(['name' => 'Jose Ruiz', 'email' => 'jose@texra.net', 'timezone' => 'UTC']);

    $response = ensureHost([
        'name' => 'Somebody Else',
        'email' => 'JOSE@texra.net',
        'timezone' => 'America/Chicago',
    ])->assertOk();

    expect($response->json('host.created'))->toBeFalse()
        ->and(User::query()->where('email', 'jose@texra.net')->count())->toBe(1)
        ->and($jose->refresh()->name)->toBe('Jose Ruiz')
        ->and($jose->timezone)->toBe('UTC')
        ->and($jose->teamRole($this->team))->toBe(TeamRole::Member)
        ->and($this->group->members()->whereKey($jose->id)->exists())->toBeTrue();

    Notification::assertNothingSent();
});

test('adding a host twice changes nothing', function () {
    Notification::fake();

    ensureHost(['name' => 'Priya Patel', 'email' => 'priya@texasrenters.com'])->assertCreated();
    ensureHost(['name' => 'Priya Patel', 'email' => 'priya@texasrenters.com'])->assertOk();

    $priya = User::query()->where('email', 'priya@texasrenters.com')->sole();

    expect($this->team->memberships()->where('user_id', $priya->id)->count())->toBe(1)
        ->and($this->group->members()->whereKey($priya->id)->count())->toBe(1)
        ->and($priya->availabilitySchedules()->count())->toBe(1);

    Notification::assertSentToTimes($priya, AccountCreated::class, 1);
});

test('governs reports the pool hours when the group keeps its own schedule', function () {
    $shared = AvailabilitySchedule::factory()->for($this->owner)->create([
        'name' => 'Agents on Duty hours',
        'team_id' => $this->team->id,
        'is_default' => false,
    ]);
    $this->group->update(['availability_schedule_id' => $shared->id]);

    $response = ensureHost(['name' => 'Priya Patel', 'email' => 'priya@texasrenters.com'])->assertCreated();

    expect($response->json('governs'))->toBe('group');
});

test('a key without write permission cannot save hours', function () {
    saveHours('dana@texra.net', ['timezone' => 'America/Chicago', 'rules' => []], $this->readOnlyToken)
        ->assertForbidden();
});

test('saving hours replaces the host weekly hours, days off and timezone', function () {
    $response = saveHours('Dana@texra.net', [
        'timezone' => 'America/New_York',
        'rules' => [
            ['day_of_week' => 3, 'starts_at' => '09:00', 'ends_at' => '12:00'],
            ['day_of_week' => 1, 'starts_at' => '10:00', 'ends_at' => '14:00'],
        ],
        'overrides' => [
            ['date' => '2026-10-20', 'is_unavailable' => true],
        ],
    ])->assertOk();

    expect($response->json('schedule.kind'))->toBe('personal')
        ->and($response->json('schedule.timezone'))->toBe('America/New_York')
        ->and($response->json('schedule.weekly'))->toBe([
            ['dayOfWeek' => 1, 'startsAt' => '10:00', 'endsAt' => '14:00'],
            ['dayOfWeek' => 3, 'startsAt' => '09:00', 'endsAt' => '12:00'],
        ])
        ->and($response->json('schedule.overrides'))->toBe([
            ['date' => '2026-10-20', 'isUnavailable' => true, 'startsAt' => null, 'endsAt' => null],
        ])
        ->and($response->json('governs'))->toBe('personal');

    $schedule = $this->owner->refresh()->defaultAvailabilitySchedule();

    expect($schedule?->timezone)->toBe('America/New_York')
        ->and($schedule?->rules)->toHaveCount(2)
        ->and($schedule?->rules->first()?->starts_at)->toBe('10:00:00')
        ->and($schedule?->overrides)->toHaveCount(1);
});

test('a host with no hours yet gets a schedule from the save', function () {
    $this->owner->availabilitySchedules()->delete();

    saveHours('dana@texra.net', [
        'timezone' => 'America/Chicago',
        'rules' => [['day_of_week' => 2, 'starts_at' => '08:00', 'ends_at' => '11:00']],
    ])->assertOk();

    expect($this->owner->refresh()->defaultAvailabilitySchedule()?->rules)->toHaveCount(1);
});

test('overlapping hours on the same day are refused', function () {
    saveHours('dana@texra.net', [
        'timezone' => 'America/Chicago',
        'rules' => [
            ['day_of_week' => 1, 'starts_at' => '09:00', 'ends_at' => '12:00'],
            ['day_of_week' => 1, 'starts_at' => '11:00', 'ends_at' => '14:00'],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors(['rules.0.starts_at']);
});

test('hours cannot be saved for somebody off the rota', function () {
    User::factory()->create(['email' => 'stranger@texra.net']);

    saveHours('stranger@texra.net', ['timezone' => 'America/Chicago', 'rules' => []])
        ->assertNotFound();
});

test('an organization key cannot touch another organization rota', function () {
    ['token' => $theirs] = ApiKey::issue('Someone else', null, Team::factory()->create(), canWrite: true);

    ensureHost(['name' => 'Priya Patel', 'email' => 'priya@texasrenters.com'], $theirs)->assertNotFound();
});

/**
 * @param  array<string, mixed>  $body
 */
function saveZones(string $email, array $body, ?string $token = null)
{
    return test()->putJson(route('api.v1.hosts.zones.update', [
        'page' => test()->team->slug,
        'eventType' => 'call-with-an-agent',
        'email' => $email,
    ]), $body, ['Authorization' => 'Bearer '.($token ?? test()->token)]);
}

test('a key without write permission cannot set a host\'s zones', function () {
    saveZones('dana@texra.net', ['zones' => ['zone-a']], $this->readOnlyToken)->assertForbidden();
});

test('a host\'s zones are kept per organization and read back with their hours', function () {
    saveZones('Dana@texra.net', ['zones' => ['zone-b', 'zone-a']])
        ->assertOk()
        ->assertExactJson(['zones' => ['zone-a', 'zone-b']]);

    // Saving again replaces the set rather than adding to it.
    saveZones('dana@texra.net', ['zones' => ['zone-a']])
        ->assertOk()
        ->assertExactJson(['zones' => ['zone-a']]);

    $this->getJson(route('api.v1.hosts.index', [
        'page' => $this->team->slug,
        'eventType' => 'call-with-an-agent',
        'from' => '2026-10-05',
        'to' => '2026-10-05',
    ]), ['Authorization' => 'Bearer '.$this->token])
        ->assertOk()
        ->assertJsonPath('data.0.email', 'dana@texra.net')
        ->assertJsonPath('data.0.zones', ['zone-a']);
});

test('an empty list clears a host\'s zones', function () {
    saveZones('dana@texra.net', ['zones' => ['zone-a']])->assertOk();
    saveZones('dana@texra.net', ['zones' => []])->assertOk()->assertExactJson(['zones' => []]);
});

test('somebody off the rota cannot be given zones', function () {
    saveZones('nobody@texra.net', ['zones' => ['zone-a']])->assertNotFound();
});

test('a zone is a slug', function () {
    saveZones('dana@texra.net', ['zones' => ['Zone A']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['zones.0']);
});

test('putting a host on the rota reports the zones they already have', function () {
    saveZones('dana@texra.net', ['zones' => ['zone-a']])->assertOk();

    ensureHost(['name' => 'Dana Fields', 'email' => 'dana@texra.net'])
        ->assertOk()
        ->assertJsonPath('host.zones', ['zone-a']);
});
