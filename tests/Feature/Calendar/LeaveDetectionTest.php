<?php

use App\Enums\CalendarProvider;
use App\Jobs\SyncCalendarBusyBlocks;
use App\Jobs\SyncLeavePeriods;
use App\Models\AvailabilitySchedule;
use App\Models\CalendarAccount;
use App\Models\EventType;
use App\Models\LeavePeriod;
use App\Models\User;
use App\Services\Calendar\CalendarProviderManager;
use App\Services\Scheduling\AvailabilityEngine;
use App\Support\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 08:00:00', 'UTC'));

    $this->host = User::factory()->create();
});

/**
 * Fake the mailbox settings Graph returns for the connected account.
 *
 * @param  array<string, mixed>  $setting
 */
function fakeAutomaticReply(array $setting, int $status = 200): void
{
    Http::fake([
        'graph.microsoft.com/v1.0/me/mailboxSettings/*' => Http::response($setting, $status),
    ]);
}

function syncLeaveFor(CalendarAccount $account): void
{
    (new SyncLeavePeriods($account))->handle(app(CalendarProviderManager::class));
}

test('an outlook auto reply scheduled for a holiday becomes a leave period', function () {
    $account = CalendarAccount::factory()->for($this->host)->microsoft()->create();

    fakeAutomaticReply([
        'status' => 'scheduled',
        'scheduledStartDateTime' => ['dateTime' => '2026-09-07T00:00:00.0000000', 'timeZone' => 'UTC'],
        'scheduledEndDateTime' => ['dateTime' => '2026-09-14T00:00:00.0000000', 'timeZone' => 'UTC'],
        'internalReplyMessage' => '<html><body>I am on leave until the 14th.</body></html>',
    ]);

    syncLeaveFor($account);

    $leave = LeavePeriod::sole();

    expect($leave->user_id)->toBe($this->host->id)
        ->and($leave->calendar_account_id)->toBe($account->id)
        ->and($leave->starts_at->toIso8601String())->toBe('2026-09-07T00:00:00+00:00')
        ->and($leave->ends_at->toIso8601String())->toBe('2026-09-14T00:00:00+00:00')
        ->and($leave->message)->toBe('I am on leave until the 14th.')
        ->and($this->host->isOnLeave(CarbonImmutable::parse('2026-09-08 10:00:00', 'UTC')))->toBeTrue()
        ->and($this->host->isOnLeave())->toBeFalse();
});

test('an auto reply left permanently on blocks the rest of the sync window', function () {
    $account = CalendarAccount::factory()->for($this->host)->microsoft()->create();

    fakeAutomaticReply(['status' => 'alwaysEnabled', 'internalReplyMessage' => 'Away.']);

    syncLeaveFor($account);

    $leave = LeavePeriod::sole();

    expect($leave->starts_at->toIso8601String())->toBe('2026-09-01T00:00:00+00:00')
        ->and($leave->ends_at->toIso8601String())->toBe('2026-10-31T23:59:59+00:00')
        ->and($this->host->isOnLeave())->toBeTrue();
});

test('a reply whose schedule has already passed leaves nobody away', function () {
    $account = CalendarAccount::factory()->for($this->host)->microsoft()->create();

    fakeAutomaticReply([
        'status' => 'scheduled',
        'scheduledStartDateTime' => ['dateTime' => '2026-08-10T00:00:00.0000000', 'timeZone' => 'UTC'],
        'scheduledEndDateTime' => ['dateTime' => '2026-08-20T00:00:00.0000000', 'timeZone' => 'UTC'],
    ]);

    syncLeaveFor($account);

    expect(LeavePeriod::count())->toBe(0);
});

test('turning the auto reply off clears the leave period', function () {
    $account = CalendarAccount::factory()->for($this->host)->microsoft()->create();

    LeavePeriod::factory()->for($this->host)->create(['calendar_account_id' => $account->id]);

    fakeAutomaticReply(['status' => 'disabled']);

    syncLeaveFor($account);

    expect(LeavePeriod::count())->toBe(0)
        ->and($this->host->isOnLeave())->toBeFalse();
});

test('an account that never consented to mailbox access reports it instead of retrying', function () {
    $account = CalendarAccount::factory()->for($this->host)->microsoft()->create([
        'email' => 'agent@texasrenters.com',
    ]);

    fakeAutomaticReply(['error' => ['code' => 'ErrorAccessDenied']], 403);

    syncLeaveFor($account);

    expect(LeavePeriod::count())->toBe(0)
        ->and($account->fresh()->sync_error)->toBe('Reconnect agent@texasrenters.com to let us see out of office replies.');
});

test('google accounts are left alone because only outlook is read', function () {
    $account = CalendarAccount::factory()->for($this->host)->create(['provider' => CalendarProvider::Google]);

    Http::fake();

    syncLeaveFor($account);

    Http::assertNothingSent();
    expect(LeavePeriod::count())->toBe(0);
});

test('a host on leave offers no slots while they are away', function () {
    $host = User::factory()->create(['timezone' => 'UTC']);

    AvailabilitySchedule::factory()->for($host)->timezone('UTC')->weekdays()->create();

    $eventType = EventType::factory()->ownedBy($host)->create(['duration_minutes' => 60]);

    $wednesday = CarbonImmutable::parse('2026-09-02', 'UTC');
    $window = new TimeRange($wednesday->startOfDay(), $wednesday->endOfDay());

    expect(app(AvailabilityEngine::class)->slots($eventType, $window))->not->toBeEmpty();

    LeavePeriod::factory()->for($host)->create([
        'starts_at' => CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-05 00:00:00', 'UTC'),
    ]);

    expect(app(AvailabilityEngine::class)->slots($eventType, $window))->toBeEmpty();
});

test('the sync command asks every account about leave, calendars or not', function () {
    Queue::fake();

    $account = CalendarAccount::factory()->for($this->host)->microsoft()->create();

    $account->calendars()->create([
        'external_id' => 'primary-calendar',
        'name' => 'Primary',
        'is_primary' => true,
        'checks_conflicts' => false,
    ]);

    $this->artisan('calendars:sync')->assertSuccessful();

    Queue::assertPushed(SyncLeavePeriods::class, 1);
    Queue::assertNotPushed(SyncCalendarBusyBlocks::class);
});

test('the calendar page shows the detected leave under the connected account', function () {
    $account = CalendarAccount::factory()->for($this->host)->microsoft()->create();

    LeavePeriod::factory()->for($this->host)->create([
        'calendar_account_id' => $account->id,
        'starts_at' => CarbonImmutable::parse('2026-09-07 00:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-14 00:00:00', 'UTC'),
        'message' => 'Back on the 14th.',
    ]);

    $this->actingAs($this->host)
        ->get(route('availability.calendars', ['current_team' => $this->host->currentTeam->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('accounts.0.detectsLeave', true)
            ->where('accounts.0.leave.startsAt', '2026-09-07T00:00:00+00:00')
            ->where('accounts.0.leave.endsAt', '2026-09-14T00:00:00+00:00')
            ->where('accounts.0.leave.message', 'Back on the 14th.'));
});

test('a google account is shown no leave row because only outlook is read', function () {
    CalendarAccount::factory()->for($this->host)->create(['provider' => CalendarProvider::Google]);

    $this->actingAs($this->host)
        ->get(route('availability.calendars', ['current_team' => $this->host->currentTeam->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('accounts.0.detectsLeave', false)
            ->where('accounts.0.leave', null));
});

test('sync now refreshes leave as well as busy times', function () {
    Queue::fake();

    $account = CalendarAccount::factory()->for($this->host)->microsoft()->create();

    $this->actingAs($this->host)
        ->post(route('integrations.sync', ['calendarAccount' => $account->id]))
        ->assertRedirect();

    Queue::assertPushedOn('calendars', SyncCalendarBusyBlocks::class);
    Queue::assertPushedOn('calendars', SyncLeavePeriods::class);
});

test('a long graph failure is trimmed to fit the sync error column', function () {
    $account = CalendarAccount::factory()->for($this->host)->microsoft()->create();

    fakeAutomaticReply(['error' => ['message' => str_repeat('graph is unwell ', 60)]], 500);

    expect(fn () => syncLeaveFor($account))->toThrow(RequestException::class);

    $error = $account->fresh()->sync_error;

    expect(mb_strlen($error))->toBeLessThanOrEqual(255)
        ->and($error)->toContain('500');
});
