<?php

use App\Enums\TeamRole;
use App\Models\AvailabilitySchedule;
use App\Models\EventType;
use App\Models\Team;
use App\Models\TeamClosure;
use App\Models\User;
use App\Services\Scheduling\AvailabilityEngine;
use App\Support\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-31 08:00:00', 'UTC'));

    $this->admin = User::factory()->create(['timezone' => 'UTC']);
    $this->member = User::factory()->create(['timezone' => 'UTC']);
    $this->team = Team::factory()->create();

    $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin->value]);
    $this->team->members()->attach($this->member, ['role' => TeamRole::Member->value]);

    AvailabilitySchedule::factory()->for($this->member)->everyDay()->create(['timezone' => 'UTC']);

    $this->eventType = EventType::factory()->create([
        'user_id' => $this->member->id,
        'team_id' => $this->team->id,
        'duration_minutes' => 60,
        'rolling_days' => 365,
    ]);
});

function openSlotsOn(EventType $eventType, string $day): array
{
    return app(AvailabilityEngine::class)
        ->slots($eventType->fresh(), new TimeRange(
            CarbonImmutable::parse($day, 'UTC')->startOfDay(),
            CarbonImmutable::parse($day, 'UTC')->endOfDay(),
        ))
        ->all();
}

test('members can see the advanced settings but not change them', function () {
    $this->actingAs($this->member)
        ->get(route('teams.advanced.edit', $this->team))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('teams/Advanced')->where('canUpdate', false));

    $this->actingAs($this->member)
        ->patch(route('teams.advanced.update', $this->team), ['holiday_country' => 'US', 'holidays' => ['thanksgiving']])
        ->assertForbidden();

    $this->actingAs($this->member)
        ->post(route('teams.closures.store', $this->team), ['name' => 'Outing', 'starts_on' => '2026-09-10'])
        ->assertForbidden();

    expect($this->team->fresh()->requiredHolidays())->toBeEmpty()
        ->and($this->team->closures()->count())->toBe(0);
});

test('an admin can require holidays of every member', function () {
    $this->actingAs($this->admin)
        ->patch(route('teams.advanced.update', $this->team), ['holiday_country' => 'US', 'holidays' => ['thanksgiving']])
        ->assertRedirect(route('teams.advanced.edit', $this->team));

    expect($this->team->fresh()->holiday_country)->toBe('US')
        ->and($this->team->fresh()->requiredHolidays())->toBe(['thanksgiving']);
});

test('a holiday the country does not have is refused', function () {
    $this->actingAs($this->admin)
        ->patch(route('teams.advanced.update', $this->team), ['holiday_country' => 'US', 'holidays' => ['boxing_day']])
        ->assertSessionHasErrors('holidays.0');
});

test('a required holiday blocks the member even though they never took it off', function () {
    expect($this->member->enabledHolidays())->toBeEmpty()
        ->and(openSlotsOn($this->eventType, '2026-11-26'))->not->toBeEmpty();

    $this->team->update(['holiday_country' => 'US']);
    DB::table('team_holidays')->insert(['team_id' => $this->team->id, 'holiday_key' => 'thanksgiving']);

    expect(openSlotsOn($this->eventType, '2026-11-26'))->toBeEmpty()
        ->and(openSlotsOn($this->eventType, '2026-11-25'))->not->toBeEmpty();
});

test('an admin can block a run of days and invitees cannot book them', function () {
    $this->actingAs($this->admin)
        ->post(route('teams.closures.store', $this->team), [
            'name' => 'Company outing',
            'starts_on' => '2026-09-10',
            'ends_on' => '2026-09-11',
        ])
        ->assertRedirect(route('teams.advanced.edit', $this->team));

    expect(openSlotsOn($this->eventType, '2026-09-09'))->not->toBeEmpty()
        ->and(openSlotsOn($this->eventType, '2026-09-10'))->toBeEmpty()
        ->and(openSlotsOn($this->eventType, '2026-09-11'))->toBeEmpty()
        ->and(openSlotsOn($this->eventType, '2026-09-12'))->not->toBeEmpty();
});

test('a closure with no last day blocks just the one day', function () {
    $this->actingAs($this->admin)
        ->post(route('teams.closures.store', $this->team), ['name' => 'Outing', 'starts_on' => '2026-09-10']);

    $closure = $this->team->closures()->sole();

    expect($closure->ends_on->toDateString())->toBe('2026-09-10');
});

test('a closure cannot end before it starts', function () {
    $this->actingAs($this->admin)
        ->post(route('teams.closures.store', $this->team), [
            'name' => 'Outing',
            'starts_on' => '2026-09-10',
            'ends_on' => '2026-09-09',
        ])
        ->assertSessionHasErrors('ends_on');
});

test('blocked dates only apply to that organization', function () {
    $other = Team::factory()->create();
    $other->members()->attach($this->member, ['role' => TeamRole::Member->value]);
    $elsewhere = EventType::factory()->create([
        'user_id' => $this->member->id,
        'team_id' => $other->id,
        'duration_minutes' => 60,
    ]);

    TeamClosure::factory()->for($this->team)->create(['starts_on' => '2026-09-10', 'ends_on' => '2026-09-10']);

    expect(openSlotsOn($this->eventType, '2026-09-10'))->toBeEmpty()
        ->and(openSlotsOn($elsewhere, '2026-09-10'))->not->toBeEmpty();
});

test('an admin can unblock dates, but not another organization\'s', function () {
    $closure = TeamClosure::factory()->for($this->team)->create(['starts_on' => '2026-09-10', 'ends_on' => '2026-09-10']);
    $foreign = TeamClosure::factory()->create();

    $this->actingAs($this->admin)
        ->delete(route('teams.closures.destroy', [$this->team, $foreign]))
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->delete(route('teams.closures.destroy', [$this->team, $closure]))
        ->assertRedirect(route('teams.advanced.edit', $this->team));

    expect(TeamClosure::count())->toBe(1)
        ->and(openSlotsOn($this->eventType, '2026-09-10'))->not->toBeEmpty();
});

test('members no longer have their own advanced settings', function () {
    expect(Route::has('availability.advanced'))->toBeFalse()
        ->and(Route::has('availability.advanced.update'))->toBeFalse();
});
