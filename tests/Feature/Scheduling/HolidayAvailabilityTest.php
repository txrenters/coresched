<?php

use App\Enums\LimitPeriod;
use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use App\Services\Scheduling\AvailabilityEngine;
use App\Services\Scheduling\HolidayCalendar;
use App\Support\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-31 08:00:00', 'UTC'));

    $this->user = User::factory()->create(['timezone' => 'UTC']);
    $this->team = $this->user->currentTeam;

    AvailabilitySchedule::factory()->for($this->user)->everyDay()->create(['timezone' => 'UTC']);
});

function slotsFor(EventType $eventType, string $from, string $to): array
{
    return app(AvailabilityEngine::class)
        ->slots($eventType->fresh(), new TimeRange(
            CarbonImmutable::parse($from, 'UTC')->startOfDay(),
            CarbonImmutable::parse($to, 'UTC')->endOfDay(),
        ))
        ->map(fn ($slot) => $slot->startsAt->toDateTimeString())
        ->all();
}

test('the holiday list matches the published dates', function () {
    $holidays = app(HolidayCalendar::class)
        ->forCountry('US', CarbonImmutable::parse('2026-08-19'))
        ->keyBy('key');

    expect($holidays['thanksgiving']['date'])->toBe('2026-11-26')
        ->and($holidays['day_after_thanksgiving']['date'])->toBe('2026-11-27')
        ->and($holidays['mlk_day']['date'])->toBe('2027-01-18')
        ->and($holidays['easter_sunday']['date'])->toBe('2027-03-28')
        ->and($holidays['memorial_day']['date'])->toBe('2027-05-31')
        ->and($holidays['juneteenth']['date'])->toBe('2027-06-18')
        ->and($holidays['juneteenth']['isObserved'])->toBeTrue()
        ->and($holidays['independence_day']['date'])->toBe('2027-07-04');
});

test('an enabled holiday removes that day from availability', function () {
    $eventType = EventType::factory()->ownedBy($this->user)->create([
        'duration_minutes' => 60,
        'rolling_days' => 365,
    ]);

    expect(slotsFor($eventType, '2026-11-26', '2026-11-26'))->not->toBeEmpty();

    $this->user->update(['holiday_country' => 'US']);
    DB::table('user_holidays')->insert([
        'user_id' => $this->user->id,
        'holiday_key' => 'thanksgiving',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(slotsFor($eventType, '2026-11-26', '2026-11-26'))->toBeEmpty()
        ->and(slotsFor($eventType, '2026-11-25', '2026-11-25'))->not->toBeEmpty();
});

test('holidays only apply once a country is chosen', function () {
    $eventType = EventType::factory()->ownedBy($this->user)->create([
        'duration_minutes' => 60,
        'rolling_days' => 365,
    ]);

    DB::table('user_holidays')->insert([
        'user_id' => $this->user->id,
        'holiday_key' => 'thanksgiving',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(slotsFor($eventType, '2026-11-26', '2026-11-26'))->not->toBeEmpty();
});

test('a daily meeting limit closes the day once reached', function () {
    $eventType = EventType::factory()->ownedBy($this->user)->create(['duration_minutes' => 60]);
    $other = EventType::factory()->ownedBy($this->user)->create(['duration_minutes' => 60]);

    $this->user->meetingLimits()->create([
        'period' => LimitPeriod::Day,
        'max_bookings' => 1,
    ]);

    // The booking is on a different event type, so only a host level cap
    // can close the day for this one.
    Booking::factory()->create([
        'event_type_id' => $other->id,
        'user_id' => $this->user->id,
        'team_id' => $other->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC'),
    ]);

    expect(slotsFor($eventType, '2026-09-02', '2026-09-02'))->toBeEmpty()
        ->and(slotsFor($eventType, '2026-09-03', '2026-09-03'))->not->toBeEmpty();
});

test('a weekly meeting limit counts across the whole week', function () {
    $eventType = EventType::factory()->ownedBy($this->user)->create(['duration_minutes' => 60]);

    $this->user->meetingLimits()->create([
        'period' => LimitPeriod::Week,
        'max_bookings' => 2,
    ]);

    foreach (['2026-09-01 09:00:00', '2026-09-02 09:00:00'] as $startsAt) {
        Booking::factory()->create([
            'event_type_id' => $eventType->id,
            'user_id' => $this->user->id,
            'team_id' => $eventType->team_id,
            'starts_at' => CarbonImmutable::parse($startsAt, 'UTC'),
            'ends_at' => CarbonImmutable::parse($startsAt, 'UTC')->addHour(),
        ]);
    }

    // That week is full, the next one is not.
    expect(slotsFor($eventType, '2026-09-03', '2026-09-04'))->toBeEmpty()
        ->and(slotsFor($eventType, '2026-09-07', '2026-09-07'))->not->toBeEmpty();
});

test('a meeting limit leaves other hosts in a round robin bookable', function () {
    $second = User::factory()->create(['timezone' => 'UTC']);
    AvailabilitySchedule::factory()->for($second)->everyDay()->create(['timezone' => 'UTC']);

    $eventType = EventType::factory()->ownedBy($this->user)->roundRobin()->create(['duration_minutes' => 60]);
    $eventType->hosts()->attach([$this->user->id, $second->id]);

    $this->user->meetingLimits()->create([
        'period' => LimitPeriod::Day,
        'max_bookings' => 1,
    ]);

    Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'user_id' => $this->user->id,
        'team_id' => $eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC'),
    ]);

    $slots = app(AvailabilityEngine::class)->slots(
        $eventType->fresh(),
        new TimeRange(
            CarbonImmutable::parse('2026-09-02', 'UTC')->startOfDay(),
            CarbonImmutable::parse('2026-09-02', 'UTC')->endOfDay(),
        ),
    );

    expect($slots)->not->toBeEmpty()
        ->and($slots->first()->hostIds)->toBe([$second->id]);
});

test('the advanced tab lists limits and holidays', function () {
    $this->user->update(['holiday_country' => 'US']);
    $this->user->meetingLimits()->create(['period' => LimitPeriod::Day, 'max_bookings' => 4]);

    $this->actingAs($this->user)
        ->get(route('availability.advanced', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('scheduling/availability/Advanced')
            ->where('limits.0.period', 'day')
            ->where('limits.0.max_bookings', 4)
            ->where('holidayCountry', 'US')
            ->has('holidays', 15)
            ->has('periods', 3));
});

test('holidays are not listed until a country is chosen', function () {
    $this->actingAs($this->user)
        ->get(route('availability.advanced', ['current_team' => $this->team->slug]))
        ->assertInertia(fn ($page) => $page->has('holidays', 0));
});

test('limits and holidays can be saved together', function () {
    $this->actingAs($this->user)
        ->patch(route('availability.advanced.update', ['current_team' => $this->team->slug]), [
            'limits' => [
                ['period' => 'day', 'max_bookings' => 3],
                ['period' => 'week', 'max_bookings' => 10],
            ],
            'holiday_country' => 'US',
            'holidays' => ['thanksgiving', 'christmas_day'],
        ])
        ->assertRedirect(route('availability.advanced', ['current_team' => $this->team->slug]));

    $this->user->refresh();

    expect($this->user->holiday_country)->toBe('US')
        ->and($this->user->meetingLimits()->count())->toBe(2)
        ->and($this->user->enabledHolidays())->toEqualCanonicalizing(['thanksgiving', 'christmas_day']);
});

test('saving replaces the previous limits and holidays', function () {
    $this->user->meetingLimits()->create(['period' => LimitPeriod::Month, 'max_bookings' => 50]);
    $this->user->update(['holiday_country' => 'US']);
    DB::table('user_holidays')->insert([
        'user_id' => $this->user->id,
        'holiday_key' => 'labor_day',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->patch(route('availability.advanced.update', ['current_team' => $this->team->slug]), [
            'limits' => [['period' => 'day', 'max_bookings' => 2]],
            'holiday_country' => 'US',
            'holidays' => ['thanksgiving'],
        ]);

    expect($this->user->meetingLimits()->pluck('period')->all())->toBe([LimitPeriod::Day])
        ->and($this->user->enabledHolidays())->toBe(['thanksgiving']);
});

test('two limits for the same period are rejected', function () {
    $this->actingAs($this->user)
        ->patch(route('availability.advanced.update', ['current_team' => $this->team->slug]), [
            'limits' => [
                ['period' => 'day', 'max_bookings' => 3],
                ['period' => 'day', 'max_bookings' => 5],
            ],
        ])
        ->assertSessionHasErrors('limits');
});

test('a newly registered user is opted in to their holidays', function () {
    $invitation = invitationFor('casey@example.com');

    $this->post(route('register.store'), [
        'invitation' => $invitation->code,
        'name' => 'Casey Lane',
        'email' => 'casey@example.com',
        'password' => 'password-password',
        'password_confirmation' => 'password-password',
    ]);

    $user = User::where('email', 'casey@example.com')->firstOrFail();

    expect($user->holiday_country)->toBe('US')
        ->and($user->enabledHolidays())->toHaveCount(15)
        ->and($user->enabledHolidays())->toContain('thanksgiving', 'christmas_day');
});

test('a new user is unavailable on a holiday out of the box', function () {
    $invitation = invitationFor('casey@example.com');

    $this->post(route('register.store'), [
        'invitation' => $invitation->code,
        'name' => 'Casey Lane',
        'email' => 'casey@example.com',
        'password' => 'password-password',
        'password_confirmation' => 'password-password',
    ]);

    $user = User::where('email', 'casey@example.com')->firstOrFail();
    $user->availabilitySchedules()->first()->update(['timezone' => 'UTC']);

    $eventType = EventType::factory()->ownedBy($user)->create([
        'duration_minutes' => 60,
        'rolling_days' => 365,
    ]);

    // Thanksgiving is closed, the Wednesday before is not.
    expect(slotsFor($eventType, '2026-11-26', '2026-11-26'))->toBeEmpty()
        ->and(slotsFor($eventType, '2026-11-25', '2026-11-25'))->not->toBeEmpty();
});

test('the default country can be turned off', function () {
    config(['scheduling.default_holiday_country' => null]);

    $invitation = invitationFor('casey@example.com');

    $this->post(route('register.store'), [
        'invitation' => $invitation->code,
        'name' => 'Casey Lane',
        'email' => 'casey@example.com',
        'password' => 'password-password',
        'password_confirmation' => 'password-password',
    ]);

    $user = User::where('email', 'casey@example.com')->firstOrFail();

    expect($user->holiday_country)->toBeNull()
        ->and($user->enabledHolidays())->toBeEmpty();
});
