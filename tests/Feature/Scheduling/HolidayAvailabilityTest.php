<?php

use App\Actions\Teams\CreateTeam;
use App\Models\AvailabilitySchedule;
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

test('an organization holiday removes that day from availability', function () {
    $eventType = EventType::factory()->ownedBy($this->user)->create([
        'duration_minutes' => 60,
        'rolling_days' => 365,
    ]);

    expect(slotsFor($eventType, '2026-11-26', '2026-11-26'))->not->toBeEmpty();

    $this->team->update(['holiday_country' => 'US']);
    DB::table('team_holidays')->insert(['team_id' => $this->team->id, 'holiday_key' => 'thanksgiving']);

    expect(slotsFor($eventType, '2026-11-26', '2026-11-26'))->toBeEmpty()
        ->and(slotsFor($eventType, '2026-11-25', '2026-11-25'))->not->toBeEmpty();
});

test('holidays only apply once the organization chooses a country', function () {
    $eventType = EventType::factory()->ownedBy($this->user)->create([
        'duration_minutes' => 60,
        'rolling_days' => 365,
    ]);

    DB::table('team_holidays')->insert(['team_id' => $this->team->id, 'holiday_key' => 'thanksgiving']);

    expect(slotsFor($eventType, '2026-11-26', '2026-11-26'))->not->toBeEmpty();
});

test('a new organization starts closed on every holiday', function () {
    $team = app(CreateTeam::class)->handle($this->user, 'Leasing Office');

    expect($team->fresh()->holiday_country)->toBe('US')
        ->and($team->requiredHolidays())->toHaveCount(15)
        ->and($team->requiredHolidays())->toContain('thanksgiving', 'christmas_day');

    $eventType = EventType::factory()->ownedBy($this->user->fresh())->create([
        'duration_minutes' => 60,
        'rolling_days' => 365,
    ]);

    expect($eventType->team_id)->toBe($team->id)
        ->and(slotsFor($eventType, '2026-11-26', '2026-11-26'))->toBeEmpty()
        ->and(slotsFor($eventType, '2026-11-25', '2026-11-25'))->not->toBeEmpty();
});

test('the default country can be turned off', function () {
    config(['scheduling.default_holiday_country' => null]);

    $team = app(CreateTeam::class)->handle($this->user, 'Leasing Office');

    expect($team->fresh()->holiday_country)->toBeNull()
        ->and($team->requiredHolidays())->toBeEmpty();
});
