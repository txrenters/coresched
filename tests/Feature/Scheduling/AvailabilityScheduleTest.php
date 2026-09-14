<?php

use App\Models\AvailabilitySchedule;
use App\Models\CalendarAccount;
use App\Models\EventType;
use App\Models\User;
use App\Services\Scheduling\AvailabilityEngine;
use App\Services\Scheduling\ScheduleResolver;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = $this->user->currentTeam;
});

function schedulePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Working hours',
        'timezone' => 'America/Chicago',
        'is_default' => true,
        'rules' => [
            ['day_of_week' => 1, 'starts_at' => '09:00', 'ends_at' => '17:00'],
            ['day_of_week' => 2, 'starts_at' => '09:00', 'ends_at' => '12:00'],
        ],
        'overrides' => [],
    ], $overrides);
}

test('the availability page lists the users schedules', function () {
    AvailabilitySchedule::factory()->for($this->user)->weekdays()->create(['name' => 'Working hours']);

    $this->actingAs($this->user)
        ->get(route('availability.index', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('scheduling/availability/Index')
            ->has('schedules', 1)
            ->where('schedules.0.name', 'Working hours')
            ->has('schedules.0.rules', 5));
});

test('a schedule can be created with weekly hours', function () {
    $this->actingAs($this->user)
        ->post(route('availability.store', ['current_team' => $this->team->slug]), schedulePayload())
        ->assertRedirect(route('availability.index', ['current_team' => $this->team->slug]));

    $schedule = AvailabilitySchedule::first();

    expect($schedule->name)->toBe('Working hours')
        ->and($schedule->timezone)->toBe('America/Chicago')
        ->and($schedule->is_default)->toBeTrue()
        ->and($schedule->rules)->toHaveCount(2)
        ->and($schedule->rules->first()->starts_at)->toBe('09:00:00');
});

test('hours that end before they start are rejected', function () {
    $this->actingAs($this->user)
        ->post(route('availability.store', ['current_team' => $this->team->slug]), schedulePayload([
            'rules' => [['day_of_week' => 1, 'starts_at' => '17:00', 'ends_at' => '09:00']],
        ]))
        ->assertSessionHasErrors('rules.0.ends_at');
});

test('overlapping blocks on the same day are rejected', function () {
    $this->actingAs($this->user)
        ->post(route('availability.store', ['current_team' => $this->team->slug]), schedulePayload([
            'rules' => [
                ['day_of_week' => 1, 'starts_at' => '09:00', 'ends_at' => '13:00'],
                ['day_of_week' => 1, 'starts_at' => '12:00', 'ends_at' => '17:00'],
            ],
        ]))
        ->assertSessionHasErrors('rules.0.starts_at');
});

test('two blocks on the same day are allowed when they do not overlap', function () {
    $this->actingAs($this->user)
        ->post(route('availability.store', ['current_team' => $this->team->slug]), schedulePayload([
            'rules' => [
                ['day_of_week' => 1, 'starts_at' => '09:00', 'ends_at' => '12:00'],
                ['day_of_week' => 1, 'starts_at' => '13:00', 'ends_at' => '17:00'],
            ],
        ]))
        ->assertSessionHasNoErrors();

    expect(AvailabilitySchedule::first()->rules)->toHaveCount(2);
});

test('an unknown timezone is rejected', function () {
    $this->actingAs($this->user)
        ->post(route('availability.store', ['current_team' => $this->team->slug]), schedulePayload([
            'timezone' => 'Mars/Olympus',
        ]))
        ->assertSessionHasErrors('timezone');
});

test('date overrides are stored', function () {
    $this->actingAs($this->user)
        ->post(route('availability.store', ['current_team' => $this->team->slug]), schedulePayload([
            'overrides' => [
                ['date' => '2026-12-25', 'is_unavailable' => true, 'starts_at' => null, 'ends_at' => null],
                ['date' => '2026-12-24', 'is_unavailable' => false, 'starts_at' => '09:00', 'ends_at' => '12:00'],
            ],
        ]))
        ->assertSessionHasNoErrors();

    $overrides = AvailabilitySchedule::first()->overrides;

    expect($overrides)->toHaveCount(2)
        ->and($overrides->firstWhere('is_unavailable', true)->date->toDateString())->toBe('2026-12-25')
        ->and($overrides->firstWhere('is_unavailable', false)->starts_at)->toBe('09:00:00');
});

test('marking a schedule default clears the previous default', function () {
    $existing = AvailabilitySchedule::factory()->for($this->user)->create(['is_default' => true]);

    $this->actingAs($this->user)
        ->post(route('availability.store', ['current_team' => $this->team->slug]), schedulePayload())
        ->assertSessionHasNoErrors();

    expect($existing->fresh()->is_default)->toBeFalse()
        ->and(AvailabilitySchedule::where('is_default', true)->count())->toBe(1);
});

test('updating a schedule replaces its rules', function () {
    $schedule = AvailabilitySchedule::factory()->for($this->user)->weekdays()->create();

    $this->actingAs($this->user)
        ->patch(route('availability.update', [
            'current_team' => $this->team->slug,
            'availability' => $schedule->id,
        ]), schedulePayload([
            'rules' => [['day_of_week' => 3, 'starts_at' => '10:00', 'ends_at' => '14:00']],
        ]))
        ->assertSessionHasNoErrors();

    $rules = $schedule->fresh()->rules;

    expect($rules)->toHaveCount(1)
        ->and($rules->first()->day_of_week)->toBe(3);
});

test('a schedule can be disabled and re-enabled', function () {
    $schedule = AvailabilitySchedule::factory()->for($this->user)->weekdays()->create();

    expect($schedule->is_active)->toBeTrue();

    $this->actingAs($this->user)
        ->patch(
            route('availability.update', ['current_team' => $this->team->slug, 'availability' => $schedule->id]),
            schedulePayload(['is_active' => false]),
        )
        ->assertRedirect();

    expect($schedule->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->user)
        ->patch(
            route('availability.update', ['current_team' => $this->team->slug, 'availability' => $schedule->id]),
            schedulePayload(['is_active' => true]),
        )
        ->assertRedirect();

    expect($schedule->fresh()->is_active)->toBeTrue();
});

test('a disabled schedule offers no slots', function () {
    $schedule = AvailabilitySchedule::factory()->for($this->user)->everyDay()->create(['is_default' => true]);
    $eventType = EventType::factory()->ownedBy($this->user)->create(['duration_minutes' => 30]);

    $engine = app(AvailabilityEngine::class);
    $window = $engine->bookableWindow($eventType);

    expect($engine->slots($eventType, $window)->count())->toBeGreaterThan(0);

    $schedule->update(['is_active' => false]);

    expect($engine->slots($eventType, $window)->count())->toBe(0);
});

test('a disabled schedule does not fall back to another schedule', function () {
    // Deliberately switching a schedule off must not silently substitute
    // someone's other hours — that would book people when they said no.
    $default = AvailabilitySchedule::factory()->for($this->user)->everyDay()->create([
        'name' => 'Working hours', 'is_default' => true, 'is_active' => false,
    ]);
    AvailabilitySchedule::factory()->for($this->user)->everyDay()->create([
        'name' => 'Backup hours', 'is_default' => false,
    ]);

    $eventType = EventType::factory()->ownedBy($this->user)->create(['duration_minutes' => 30]);

    $resolved = app(ScheduleResolver::class)->resolve($eventType, $this->user);

    expect($resolved)->toBeNull()
        ->and($default->fresh()->is_active)->toBeFalse();
});

test('a user cannot edit someone elses schedule', function () {
    $stranger = User::factory()->create();
    $schedule = AvailabilitySchedule::factory()->for($stranger)->create();

    $this->actingAs($this->user)
        ->patch(route('availability.update', [
            'current_team' => $this->team->slug,
            'availability' => $schedule->id,
        ]), schedulePayload())
        ->assertForbidden();
});

test('the last remaining schedule cannot be deleted', function () {
    $schedule = AvailabilitySchedule::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->delete(route('availability.destroy', [
            'current_team' => $this->team->slug,
            'availability' => $schedule->id,
        ]))
        ->assertForbidden();
});

test('a newly registered user starts with weekday working hours', function () {
    $invitation = invitationFor('dana@example.com');

    $this->post(route('register.store'), [
        'invitation' => $invitation->code,
        'name' => 'Dana Reed',
        'email' => 'dana@example.com',
        'password' => 'password-password',
        'password_confirmation' => 'password-password',
    ]);

    $user = User::where('email', 'dana@example.com')->firstOrFail();
    $schedule = $user->defaultAvailabilitySchedule();

    expect($schedule)->not->toBeNull()
        ->and($schedule->is_default)->toBeTrue()
        ->and($schedule->rules)->toHaveCount(5)
        ->and($schedule->rules->pluck('day_of_week')->all())->toBe([1, 2, 3, 4, 5]);
});

test('a newly registered user gets a public booking slug', function () {
    $invitation = invitationFor('dana@example.com');

    $this->post(route('register.store'), [
        'invitation' => $invitation->code,
        'name' => 'Dana Reed',
        'email' => 'dana@example.com',
        'password' => 'password-password',
        'password_confirmation' => 'password-password',
    ]);

    expect(User::where('email', 'dana@example.com')->value('booking_slug'))->toBe('dana-reed');
});

test('booking slugs stay unique across users with the same name', function () {
    User::factory()->create(['name' => 'Dana Reed']);
    $second = User::factory()->create(['name' => 'Dana Reed']);

    expect($second->booking_slug)->toBe('dana-reed-2');
});

test('a new user and their default schedule start in the configured timezone', function () {
    $invitation = invitationFor('casey@example.com');

    $this->post(route('register.store'), [
        'invitation' => $invitation->code,
        'name' => 'Casey Lane',
        'email' => 'casey@example.com',
        'password' => 'password-password',
        'password_confirmation' => 'password-password',
    ]);

    $user = User::where('email', 'casey@example.com')->firstOrFail();

    expect($user->timezone)->toBe('America/Chicago')
        ->and($user->currentTeam->timezone)->toBe('America/Chicago')
        ->and($user->defaultAvailabilitySchedule()->timezone)->toBe('America/Chicago');
});

test('the default timezone is configurable', function () {
    config(['scheduling.default_timezone' => 'America/New_York']);

    $user = User::factory()->create();

    expect($user->timezone)->toBe('America/New_York');
});

test('an explicitly chosen timezone is never overwritten by the default', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);

    $schedule = AvailabilitySchedule::factory()->for($user)->create(['timezone' => 'UTC']);

    expect($user->fresh()->timezone)->toBe('UTC')
        ->and($schedule->fresh()->timezone)->toBe('UTC');
});

test('the availability card reports what each schedule governs', function () {
    $schedule = AvailabilitySchedule::factory()
        ->for($this->user)
        ->weekdays('09:00:00', '17:00:00')
        ->create(['name' => 'Working hours']);

    EventType::factory()->ownedBy($this->user)->create([
        'availability_schedule_id' => $schedule->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('availability.index', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('schedules.0.name', 'Working hours')
            ->where('schedules.0.eventTypeCount', 1)
            ->where('schedules.0.summary', 'Weekdays, 9 am - 5 pm')
            ->has('schedules.0.rules', 5));
});

test('a schedule with no hours reports none in the card', function () {
    AvailabilitySchedule::factory()->for($this->user)->create(['name' => 'Empty']);

    $this->actingAs($this->user)
        ->get(route('availability.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn ($page) => $page
            ->where('schedules.0.summary', null)
            ->where('schedules.0.eventTypeCount', 0)
            ->has('schedules.0.rules', 0));
});

test('two blocks can be kept on the same day', function () {
    $this->actingAs($this->user)
        ->post(route('availability.store', ['current_team' => $this->team->slug]), schedulePayload([
            'rules' => [
                ['day_of_week' => 1, 'starts_at' => '09:00', 'ends_at' => '12:00'],
                ['day_of_week' => 1, 'starts_at' => '13:00', 'ends_at' => '17:00'],
            ],
        ]))
        ->assertSessionHasNoErrors();

    $schedule = AvailabilitySchedule::first();

    expect($schedule->rules)->toHaveCount(2)
        ->and($schedule->fresh('rules')->summary())->toBe('Mon, hours vary');
});

test('the calendar settings tab lists connected calendars', function () {
    $account = CalendarAccount::factory()->for($this->user)->create();
    $account->calendars()->create([
        'external_id' => 'primary',
        'name' => 'Primary',
        'is_primary' => true,
        'checks_conflicts' => true,
        'is_write_target' => true,
    ]);

    $this->actingAs($this->user)
        ->get(route('availability.calendars', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('scheduling/availability/Calendars')
            ->has('accounts', 1)
            ->where('accounts.0.calendars.0.name', 'Primary')
            ->where('accounts.0.calendars.0.checksConflicts', true));
});

test('an end time that is not after its start is refused, and says so', function () {
    $schedule = AvailabilitySchedule::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->patch(route('availability.update', [
            'current_team' => $this->team->slug,
            'availability' => $schedule->id,
        ]), [
            'name' => 'Working hours',
            'timezone' => 'UTC',
            'rules' => [
                ['day_of_week' => 1, 'starts_at' => '09:00', 'ends_at' => '09:00'],
            ],
        ])
        // The message hangs off ends_at, which the page renders beside the
        // block now: it used to bounce the save with nothing on screen.
        ->assertSessionHasErrors('rules.0.ends_at');
});

test('overlapping blocks on the same day are refused', function () {
    $schedule = AvailabilitySchedule::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->patch(route('availability.update', [
            'current_team' => $this->team->slug,
            'availability' => $schedule->id,
        ]), [
            'name' => 'Working hours',
            'timezone' => 'UTC',
            'rules' => [
                ['day_of_week' => 1, 'starts_at' => '09:00', 'ends_at' => '12:00'],
                ['day_of_week' => 1, 'starts_at' => '11:00', 'ends_at' => '15:00'],
            ],
        ])
        ->assertSessionHasErrors('rules.0.starts_at');
});
