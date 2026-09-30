<?php

use App\Enums\BookingStatus;
use App\Enums\TeamRole;
use App\Jobs\RemoveBookingFromCalendars;
use App\Jobs\SyncBookingToCalendars;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\BookingReminder;
use App\Models\EventType;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Bookings\BookingCanceled;
use App\Notifications\Bookings\BookingConfirmed;
use App\Notifications\Bookings\BookingDeclined;
use App\Notifications\Bookings\BookingReminder as BookingReminderNotification;
use App\Services\Ics\IcsGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 08:00:00', 'UTC'));

    $this->host = User::factory()->create(['name' => 'Dana Reed', 'timezone' => 'America/Chicago']);
    $this->team = $this->host->currentTeam;
    $this->eventType = EventType::factory()->ownedBy($this->host)->create(['name' => 'Intro call']);
});

function bookingFor(User $host, EventType $eventType, array $overrides = []): Booking
{
    $booking = Booking::factory()->create(array_merge([
        'event_type_id' => $eventType->id,
        'user_id' => $host->id,
        'team_id' => $eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-03 15:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-03 15:30:00', 'UTC'),
    ], $overrides));

    $booking->hosts()->attach($host);

    return $booking;
}

test('the bookings page lists upcoming bookings in the viewers timezone', function () {
    bookingFor($this->host, $this->eventType, ['name' => 'Sam Rivera']);

    $this->actingAs($this->host)
        ->get(route('meetings.index', ['current_team' => $this->team->slug, 'filter' => 'upcoming']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('scheduling/meetings/Index')
            ->where('viewerTimezone', 'America/Chicago')
            ->has('meetings', 1)
            ->where('total', 1)
            ->where('meetings.0.inviteeName', 'Sam Rivera')
            ->where('meetings.0.timeLabel', '10:00 am - 10:30 am')
            ->where('meetings.0.eventTypeName', 'Intro call'));
});

test('past bookings are only listed under the past filter', function () {
    bookingFor($this->host, $this->eventType, [
        'starts_at' => CarbonImmutable::parse('2026-08-20 10:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-08-20 10:30:00', 'UTC'),
    ]);

    $this->actingAs($this->host)
        ->get(route('meetings.index', ['current_team' => $this->team->slug, 'filter' => 'upcoming']))
        ->assertInertia(fn ($page) => $page->has('meetings', 0));

    $this->actingAs($this->host)
        ->get(route('meetings.index', ['current_team' => $this->team->slug, 'filter' => 'past']))
        ->assertInertia(fn ($page) => $page->has('meetings', 1));
});

test('a member only sees bookings they host', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();

    $team->members()->attach($this->host, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);

    $eventType = EventType::factory()->create(['team_id' => $team->id, 'user_id' => $this->host->id]);
    bookingFor($this->host, $eventType);

    $this->actingAs($member)
        ->get(route('meetings.index', ['current_team' => $team->slug, 'filter' => 'upcoming']))
        ->assertInertia(fn ($page) => $page->has('meetings', 0));
});

test('a team admin sees every booking in the team', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();

    $team->members()->attach($this->host, ['role' => TeamRole::Member->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $admin->switchTeam($team);

    $eventType = EventType::factory()->create(['team_id' => $team->id, 'user_id' => $this->host->id]);
    bookingFor($this->host, $eventType);

    $this->actingAs($admin)
        ->get(route('meetings.index', ['current_team' => $team->slug, 'filter' => 'upcoming']))
        ->assertInertia(fn ($page) => $page->has('meetings', 1));
});

test('a host can cancel a booking', function () {
    Notification::fake();

    $booking = bookingFor($this->host, $this->eventType);

    $this->actingAs($this->host)
        ->delete(route('meetings.destroy', [
            'current_team' => $this->team->slug,
            'booking' => $booking->uid,
        ]), ['reason' => 'Out sick']);

    $booking->refresh();

    expect($booking->status)->toBe(BookingStatus::Canceled)
        ->and($booking->canceled_by)->toBe('host')
        ->and($booking->cancellation_reason)->toBe('Out sick');

    Notification::assertSentOnDemand(BookingCanceled::class);
});

test('canceling clears the pending reminders', function () {
    Notification::fake();

    $booking = bookingFor($this->host, $this->eventType);
    $booking->reminders()->create(['minutes_before' => 60, 'send_at' => now()->addDay()]);

    $this->actingAs($this->host)
        ->delete(route('meetings.destroy', [
            'current_team' => $this->team->slug,
            'booking' => $booking->uid,
        ]));

    expect($booking->fresh()->reminders)->toHaveCount(0);
});

test('a reply to a booking email reaches the other party, not the shared mailbox', function () {
    $booking = bookingFor($this->host, $this->eventType, [
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
    ]);

    $notification = new BookingConfirmed($booking->fresh(['eventType', 'host', 'hosts', 'guests', 'answers']));

    // The invitee replies to whoever is hosting them...
    expect($notification->toMail(new AnonymousNotifiable)->replyTo)
        ->toBe([[$this->host->email, 'Dana Reed']]);

    // ...and the host replies to the invitee.
    expect($notification->toMail($this->host)->replyTo)
        ->toBe([['sam@example.com', 'Sam Rivera']]);
});

test('a stranger cannot cancel a booking', function () {
    $booking = bookingFor($this->host, $this->eventType);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->delete(route('meetings.destroy', [
            'current_team' => $this->team->slug,
            'booking' => $booking->uid,
        ]))
        ->assertForbidden();
});

test('due reminders are sent once', function () {
    Notification::fake();

    $booking = bookingFor($this->host, $this->eventType);
    $reminder = $booking->reminders()->create([
        'minutes_before' => 60,
        'send_at' => now()->subMinute(),
    ]);

    $this->artisan('bookings:send-reminders')->assertSuccessful();

    expect($reminder->fresh()->sent_at)->not->toBeNull();

    Notification::assertSentTo($this->host, BookingReminderNotification::class);

    Notification::fake();
    $this->artisan('bookings:send-reminders')->assertSuccessful();
    Notification::assertNothingSent();
});

test('due reminders reach the invitee and every additional guest', function () {
    Notification::fake();

    $booking = bookingFor($this->host, $this->eventType, ['email' => 'sam@example.com']);
    $booking->guests()->create(['email' => 'guest@example.com']);

    $booking->reminders()->create([
        'minutes_before' => 60,
        'send_at' => now()->subMinute(),
    ]);

    $this->artisan('bookings:send-reminders')->assertSuccessful();

    Notification::assertSentTo($this->host, BookingReminderNotification::class);

    foreach (['sam@example.com', 'guest@example.com'] as $email) {
        Notification::assertSentOnDemand(
            BookingReminderNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $email,
        );
    }
});

test('reminders for canceled bookings are discarded', function () {
    Notification::fake();

    $booking = bookingFor($this->host, $this->eventType, ['status' => BookingStatus::Canceled]);
    $reminder = $booking->reminders()->create([
        'minutes_before' => 60,
        'send_at' => now()->subMinute(),
    ]);

    $this->artisan('bookings:send-reminders')->assertSuccessful();

    expect(BookingReminder::find($reminder->id))->toBeNull();

    Notification::assertNothingSent();
});

test('the ics document describes the meeting', function () {
    $booking = bookingFor($this->host, $this->eventType, [
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'meeting_url' => 'https://teams.microsoft.com/l/meetup-join/abc',
    ]);

    $ics = app(IcsGenerator::class)->forBooking($booking->fresh(['eventType', 'host', 'hosts', 'guests', 'answers']));

    expect($ics)->toContain('BEGIN:VCALENDAR')
        ->and($ics)->toContain('METHOD:REQUEST')
        ->and($ics)->toContain('UID:'.$booking->uid)
        ->and($ics)->toContain('DTSTART:20260903T150000Z')
        ->and($ics)->toContain('DTEND:20260903T153000Z')
        ->and($ics)->toContain('STATUS:CONFIRMED')
        ->and($ics)->toContain('mailto:sam@example.com')
        ->and($ics)->toContain('END:VCALENDAR');
});

test('a canceled booking produces a cancelling ics document', function () {
    $booking = bookingFor($this->host, $this->eventType, ['status' => BookingStatus::Canceled]);

    $ics = app(IcsGenerator::class)->forBooking($booking->fresh(['eventType', 'host', 'hosts', 'guests', 'answers']));

    expect($ics)->toContain('METHOD:CANCEL')
        ->and($ics)->toContain('STATUS:CANCELLED');
});

test('meetings are grouped under a day heading', function () {
    bookingFor($this->host, $this->eventType, [
        'starts_at' => CarbonImmutable::parse('2026-09-01 15:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-01 15:30:00', 'UTC'),
    ]);

    $this->actingAs($this->host)
        ->get(route('meetings.index', ['current_team' => $this->team->slug, 'filter' => 'upcoming']))
        ->assertInertia(fn ($page) => $page
            ->where('meetings.0.dateLabel', 'Today')
            ->where('meetings.0.dateKey', '2026-09-01'));
});

test('the this week range only covers the current week', function () {
    bookingFor($this->host, $this->eventType, [
        'starts_at' => CarbonImmutable::parse('2026-09-03 15:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-03 15:30:00', 'UTC'),
    ]);
    bookingFor($this->host, $this->eventType, [
        'starts_at' => CarbonImmutable::parse('2026-09-20 15:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-20 15:30:00', 'UTC'),
    ]);

    $this->actingAs($this->host)
        ->get(route('meetings.index', ['current_team' => $this->team->slug, 'filter' => 'this_week']))
        ->assertInertia(fn ($page) => $page->has('meetings', 1)->where('total', 1));
});

test('the today chip only covers today', function () {
    bookingFor($this->host, $this->eventType, [
        'starts_at' => CarbonImmutable::parse('2026-09-01 15:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-01 15:30:00', 'UTC'),
    ]);
    bookingFor($this->host, $this->eventType, [
        'starts_at' => CarbonImmutable::parse('2026-09-04 15:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-04 15:30:00', 'UTC'),
    ]);

    $this->actingAs($this->host)
        ->get(route('meetings.index', ['current_team' => $this->team->slug, 'filter' => 'today']))
        ->assertInertia(fn ($page) => $page->has('meetings', 1)->where('total', 1));
});

test('the meetings page opens on today', function () {
    bookingFor($this->host, $this->eventType, [
        'starts_at' => CarbonImmutable::parse('2026-09-01 15:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-01 15:30:00', 'UTC'),
    ]);
    bookingFor($this->host, $this->eventType);

    $this->actingAs($this->host)
        ->get(route('meetings.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn ($page) => $page->where('filter', 'today')->has('meetings', 1));

    $this->actingAs($this->host)
        ->get(route('meetings.index', ['current_team' => $this->team->slug, 'filter' => 'nonsense']))
        ->assertInertia(fn ($page) => $page->where('filter', 'today'));
});

test('cancelled meetings are found through the status filter', function () {
    bookingFor($this->host, $this->eventType, ['status' => BookingStatus::Canceled]);

    $this->actingAs($this->host)
        ->get(route('meetings.index', ['current_team' => $this->team->slug, 'filter' => 'upcoming']))
        ->assertInertia(fn ($page) => $page->has('meetings', 0));

    $this->actingAs($this->host)
        ->get(route('meetings.index', [
            'current_team' => $this->team->slug,
            'filter' => 'upcoming',
            'status' => 'canceled',
        ]))
        ->assertInertia(fn ($page) => $page->has('meetings', 1));
});

test('the detail payload carries everything the side panel shows', function () {
    $booking = bookingFor($this->host, $this->eventType, [
        'name' => 'Max Menchaca',
        'email' => 'max@example.com',
        'notes' => 'Looking for a new management company.',
        'invitee_timezone' => 'America/Chicago',
    ]);
    $booking->answers()->create(['label' => 'Best number?', 'answer' => '+1 832 298 6554']);

    $this->actingAs($this->host)
        ->get(route('meetings.index', ['current_team' => $this->team->slug, 'filter' => 'upcoming']))
        ->assertInertia(fn ($page) => $page
            ->where('meetings.0.inviteeInitials', 'MM')
            ->where('meetings.0.fullDateLabel', 'Thursday, 3 September')
            ->where('meetings.0.timeWithZone', '10:00 am - 10:30 am (CDT)')
            ->where('meetings.0.notes', 'Looking for a new management company.')
            ->where('meetings.0.answers.0.label', 'Best number?')
            ->where('meetings.0.hostNames.0', 'Dana Reed'));
});

test('a host can save private notes against a meeting', function () {
    $booking = bookingFor($this->host, $this->eventType);

    $this->actingAs($this->host)
        ->patch(route('meetings.notes.update', [
            'current_team' => $this->team->slug,
            'booking' => $booking->uid,
        ]), ['host_notes' => 'Follow up about the East Houston property.'])
        ->assertSessionHasNoErrors();

    expect($booking->fresh()->host_notes)->toBe('Follow up about the East Houston property.');
});

test('someone who cannot see a meeting cannot write notes on it', function () {
    $booking = bookingFor($this->host, $this->eventType);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->patch(route('meetings.notes.update', [
            'current_team' => $this->team->slug,
            'booking' => $booking->uid,
        ]), ['host_notes' => 'Nope'])
        ->assertForbidden();
});

test('meetings can be searched by invitee or event type', function () {
    bookingFor($this->host, $this->eventType, ['name' => 'Sam Rivera']);
    bookingFor($this->host, $this->eventType, ['name' => 'Jo Baker']);

    $this->actingAs($this->host)
        ->get(route('meetings.index', ['current_team' => $this->team->slug, 'filter' => 'upcoming', 'search' => 'Rivera']))
        ->assertInertia(fn ($page) => $page
            ->has('meetings', 1)
            ->where('meetings.0.inviteeName', 'Sam Rivera'));
});

test('meetings can be filtered to one event type', function () {
    $other = EventType::factory()->ownedBy($this->host)->create(['name' => 'Deep dive']);

    bookingFor($this->host, $this->eventType);
    bookingFor($this->host, $other);

    $this->actingAs($this->host)
        ->get(route('meetings.index', [
            'current_team' => $this->team->slug,
            'filter' => 'upcoming',
            'event_type' => $other->id,
        ]))
        ->assertInertia(fn ($page) => $page
            ->has('meetings', 1)
            ->where('meetings.0.eventTypeName', 'Deep dive'));
});

test('the list reports whether more meetings are waiting', function () {
    foreach (range(1, 21) as $index) {
        bookingFor($this->host, $this->eventType, [
            'starts_at' => CarbonImmutable::parse('2026-09-03 09:00:00', 'UTC')->addMinutes($index * 45),
            'ends_at' => CarbonImmutable::parse('2026-09-03 09:30:00', 'UTC')->addMinutes($index * 45),
        ]);
    }

    $this->actingAs($this->host)
        ->get(route('meetings.index', ['current_team' => $this->team->slug, 'filter' => 'upcoming']))
        ->assertInertia(fn ($page) => $page
            ->where('total', 21)
            ->where('hasMore', true)
            ->has('meetings', 20));

    $this->actingAs($this->host)
        ->get(route('meetings.index', ['current_team' => $this->team->slug, 'filter' => 'upcoming', 'page' => 2]))
        ->assertInertia(fn ($page) => $page
            ->where('hasMore', false)
            ->has('meetings', 1));
});

test('the scope picker is offered on the meetings list', function () {
    $this->actingAs($this->host)
        ->get(route('meetings.index', ['current_team' => $this->team->slug, 'filter' => 'upcoming']))
        ->assertInertia(fn ($page) => $page
            ->where('scope', 'all')
            ->has('scopeOptions.primary', 2)
            ->has('ranges', 5));
});

test('a host can approve a pending booking', function () {
    Notification::fake();
    Queue::fake();

    $booking = bookingFor($this->host, $this->eventType, ['status' => BookingStatus::Pending]);

    $this->actingAs($this->host)
        ->post(route('meetings.approve', [
            'current_team' => $this->team->slug,
            'booking' => $booking->uid,
        ]))
        ->assertRedirect();

    $booking->refresh();

    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->reminders()->count())->toBe(count(config('scheduling.reminder_lead_times')));

    Queue::assertPushed(SyncBookingToCalendars::class);
    Notification::assertSentOnDemand(BookingConfirmed::class);

    $log = ActivityLog::where('event', 'booking.approved')->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->host->id);
});

test('approving a booking that is no longer pending changes nothing', function () {
    Notification::fake();

    $booking = bookingFor($this->host, $this->eventType, [
        'status' => BookingStatus::Canceled,
        'canceled_at' => now(),
    ]);

    $this->actingAs($this->host)
        ->post(route('meetings.approve', [
            'current_team' => $this->team->slug,
            'booking' => $booking->uid,
        ]))
        ->assertRedirect();

    expect($booking->refresh()->status)->toBe(BookingStatus::Canceled);

    Notification::assertNothingSent();
});

test('a stranger cannot approve or decline a booking', function () {
    $booking = bookingFor($this->host, $this->eventType, ['status' => BookingStatus::Pending]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->post(route('meetings.approve', [
            'current_team' => $this->team->slug,
            'booking' => $booking->uid,
        ]))
        ->assertForbidden();

    $this->actingAs($stranger)
        ->post(route('meetings.decline', [
            'current_team' => $this->team->slug,
            'booking' => $booking->uid,
        ]))
        ->assertForbidden();

    expect($booking->refresh()->status)->toBe(BookingStatus::Pending);
});

test('a host can decline a pending booking with a reason', function () {
    Notification::fake();
    Queue::fake();

    $booking = bookingFor($this->host, $this->eventType, ['status' => BookingStatus::Pending]);

    $this->actingAs($this->host)
        ->post(route('meetings.decline', [
            'current_team' => $this->team->slug,
            'booking' => $booking->uid,
        ]), ['reason' => 'No availability that day'])
        ->assertRedirect();

    $booking->refresh();

    expect($booking->status)->toBe(BookingStatus::Canceled)
        ->and($booking->canceled_by)->toBe('host')
        ->and($booking->cancellation_reason)->toBe('No availability that day');

    Notification::assertSentOnDemand(BookingDeclined::class);

    // Nothing was ever synced, so there is no calendar event to remove.
    Queue::assertNotPushed(RemoveBookingFromCalendars::class);

    expect(ActivityLog::where('event', 'booking.declined')->exists())->toBeTrue();
});

test('the pending filter lists only pending meetings', function () {
    bookingFor($this->host, $this->eventType, ['status' => BookingStatus::Pending, 'name' => 'Sam Rivera']);
    bookingFor($this->host, $this->eventType, [
        'starts_at' => CarbonImmutable::parse('2026-09-04 15:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-04 15:30:00', 'UTC'),
    ]);

    $this->actingAs($this->host)
        ->get(route('meetings.index', ['current_team' => $this->team->slug, 'filter' => 'upcoming', 'status' => 'pending']))
        ->assertInertia(fn ($page) => $page
            ->has('meetings', 1)
            ->where('meetings.0.inviteeName', 'Sam Rivera')
            ->where('meetings.0.status', 'pending')
            ->where('meetings.0.canApprove', true));

    // The default active listing keeps pending requests visible.
    $this->actingAs($this->host)
        ->get(route('meetings.index', ['current_team' => $this->team->slug, 'filter' => 'upcoming']))
        ->assertInertia(fn ($page) => $page->has('meetings', 2));
});

test('the reminder carries the event description, invitee and answers to the host', function () {
    $this->eventType->update(['description' => 'A 20 minute chat about billing.']);

    $booking = bookingFor($this->host, $this->eventType, [
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'notes' => 'Running from another call.',
    ]);

    $booking->answers()->create([
        'label' => 'What is the best number to reach you?',
        'answer' => '+1 346-239-9213',
    ]);

    $mail = (new BookingReminderNotification($booking, 60))->toMail($this->host);
    $body = implode(' ', [...$mail->introLines, ...$mail->outroLines]);

    expect($body)->toContain('A 20 minute chat about billing.')
        ->and($body)->toContain('**Invitee:** Sam Rivera (sam@example.com)')
        ->and($body)->toContain('**What is the best number to reach you?** +1 346-239-9213')
        ->and($body)->toContain('**Notes** Running from another call.');
});

test('the reminder does not read the invitees own details back to them', function () {
    $booking = bookingFor($this->host, $this->eventType, [
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
    ]);

    $booking->answers()->create([
        'label' => 'What is the best number to reach you?',
        'answer' => '+1 346-239-9213',
    ]);

    $notifiable = (new AnonymousNotifiable)->route('mail', $booking->email);
    $mail = (new BookingReminderNotification($booking, 60))->toMail($notifiable);
    $body = implode(' ', [...$mail->introLines, ...$mail->outroLines]);

    expect($body)->not->toContain('**Invitee:**')
        // The answers still travel: they are what the meeting is about.
        ->and($body)->toContain('**What is the best number to reach you?** +1 346-239-9213');
});

test('a meeting that is running now stays under upcoming', function () {
    $running = bookingFor($this->host, $this->eventType, [
        'name' => 'Halfway through',
        'starts_at' => CarbonImmutable::parse('2026-09-01 07:45:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-01 08:15:00', 'UTC'),
    ]);

    bookingFor($this->host, $this->eventType, [
        'name' => 'Already over',
        'starts_at' => CarbonImmutable::parse('2026-09-01 07:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-01 07:30:00', 'UTC'),
    ]);

    $this->actingAs($this->host)
        ->get(route('meetings.index', ['current_team' => $this->team->slug, 'filter' => 'upcoming']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('meetings', 1)
            ->where('meetings.0.inviteeName', 'Halfway through')
            ->where('meetings.0.endsAt', $running->ends_at->toIso8601String()));
});

test('a finished meeting is listed under past', function () {
    bookingFor($this->host, $this->eventType, [
        'name' => 'Already over',
        'starts_at' => CarbonImmutable::parse('2026-09-01 07:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-01 07:30:00', 'UTC'),
    ]);

    bookingFor($this->host, $this->eventType, [
        'name' => 'Halfway through',
        'starts_at' => CarbonImmutable::parse('2026-09-01 07:45:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-01 08:15:00', 'UTC'),
    ]);

    $this->actingAs($this->host)
        ->get(route('meetings.index', ['current_team' => $this->team->slug, 'filter' => 'past']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('meetings', 1)
            ->where('meetings.0.inviteeName', 'Already over'));
});

test('a meeting carries its history, oldest first', function () {
    $booking = bookingFor($this->host, $this->eventType, [
        'name' => 'Sam Rivera',
        'starts_at' => CarbonImmutable::parse('2026-09-01 07:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-01 07:30:00', 'UTC'),
    ]);

    // created_at is not fillable, so it is set after the fact.
    ActivityLog::create([
        'team_id' => $this->team->id,
        'user_id' => null,
        'actor_name' => 'Sam Rivera',
        'event' => 'booking.created',
        'description' => 'Booked Intro call',
        'subject_type' => Booking::class,
        'subject_id' => $booking->id,
    ])->forceFill(['created_at' => CarbonImmutable::parse('2026-08-31 16:04:00', 'UTC')])->save();

    $booking->reminders()->create([
        'minutes_before' => 60,
        'send_at' => CarbonImmutable::parse('2026-09-01 06:00:00', 'UTC'),
        'sent_at' => CarbonImmutable::parse('2026-09-01 06:00:00', 'UTC'),
    ]);

    $this->actingAs($this->host)
        ->get(route('meetings.index', ['current_team' => $this->team->slug, 'filter' => 'past']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Booked, reminded, started, ended.
            ->has('meetings.0.timeline', 4)
            ->where('meetings.0.timeline.0.label', 'Booked Intro call')
            ->where('meetings.0.timeline.0.detail', 'Sam Rivera')
            ->where('meetings.0.timeline.1.label', 'Reminder sent')
            ->where('meetings.0.timeline.2.label', 'Meeting started')
            ->where('meetings.0.timeline.3.label', 'Meeting ended'));
});

test('a cancelled meeting does not claim it went ahead', function () {
    $booking = bookingFor($this->host, $this->eventType, [
        'starts_at' => CarbonImmutable::parse('2026-09-01 07:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-01 07:30:00', 'UTC'),
        'status' => BookingStatus::Canceled,
        'canceled_at' => CarbonImmutable::parse('2026-09-01 06:30:00', 'UTC'),
        'cancellation_reason' => 'Something came up',
    ]);

    $this->actingAs($this->host)
        ->get(route('meetings.index', [
            'current_team' => $this->team->slug,
            'filter' => 'past',
            'status' => 'canceled',
        ]))
        ->assertOk()
        ->assertInertia(function ($page) {
            $labels = collect($page->toArray()['props']['meetings'][0]['timeline'])
                ->pluck('label');

            expect($labels)->toContain('Meeting cancelled')
                ->and($labels)->not->toContain('Meeting started');
        });

    expect($booking->fresh()->status)->toBe(BookingStatus::Canceled);
});
