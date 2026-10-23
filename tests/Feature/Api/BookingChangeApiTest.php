<?php

use App\Enums\BookingStatus;
use App\Enums\EventTypeKind;
use App\Enums\TeamRole;
use App\Jobs\SendBookingWebhook;
use App\Models\ApiKey;
use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\HostZone;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Bookings\BookingCanceled;
use App\Notifications\Bookings\BookingRescheduled;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * TEXRA handing a lead to another agent (the Spanish speakers): re-tagging
 * the booking's zone, moving it to the new agent at the same time, or
 * calling it off when they are busy then.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-31 08:00:00', 'UTC'));

    // Nothing here is about delivery; the webhook job is asserted where it matters.
    Http::fake(['*' => Http::response(['result' => 'recorded'])]);

    config([
        'services.texra.webhook_url' => 'https://texra.example.com/api/bookings/webhook',
        'services.texra.webhook_secret' => 'shared-secret',
    ]);

    $this->host = User::factory()->create(['name' => 'Samuel Upchurch', 'email' => 'samuel@texra.net', 'timezone' => 'UTC']);
    $this->team = $this->host->currentTeam;
    $this->spanishHost = User::factory()->create(['name' => 'Sofia Reyes', 'email' => 'sofia@texra.net', 'timezone' => 'UTC']);
    $this->team->members()->attach($this->spanishHost, ['role' => TeamRole::Member->value]);

    AvailabilitySchedule::factory()->for($this->host)->everyDay()->create();
    AvailabilitySchedule::factory()->for($this->spanishHost)->everyDay()->create();

    $this->eventType = EventType::factory()->ownedBy($this->host)->roundRobin()->create([
        'name' => 'Call with an agent',
        'slug' => 'call-with-an-agent',
        'duration_minutes' => 30,
    ]);
    $this->eventType->hosts()->attach($this->host, ['priority' => 0]);
    $this->eventType->hosts()->attach($this->spanishHost, ['priority' => 1]);

    HostZone::query()->create(['team_id' => $this->team->id, 'user_id' => $this->spanishHost->id, 'zone' => 'spanish']);

    $this->booking = Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'user_id' => $this->host->id,
        'team_id' => $this->eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 12:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 12:30:00', 'UTC'),
        'email' => 'dominic@example.com',
        'reference' => 'lead-token-123',
    ]);
    $this->booking->hosts()->attach($this->host);

    ['token' => $this->token] = ApiKey::issue('TEXRA', null, $this->team, canWrite: true);
    ['token' => $this->readOnlyToken] = ApiKey::issue('Reader', null, $this->team);
});

/**
 * @param  array<string, mixed>  $body
 */
function changeBooking(string $method, string $route, array $body, ?string $uid = null, ?string $token = null)
{
    return test()->json(
        $method,
        route($route, ['booking' => $uid ?? test()->booking->uid]),
        $body,
        $token === '' ? [] : ['Authorization' => 'Bearer '.($token ?? test()->token)],
    );
}

dataset('booking changes', [
    'zone' => ['PUT', 'api.v1.bookings.zone.update', ['zone' => 'spanish']],
    'hand-off' => ['POST', 'api.v1.bookings.hand-off.store', ['email' => 'sofia@texra.net']],
    'cancel' => ['POST', 'api.v1.bookings.cancel.store', []],
]);

test('a booking change needs a key', function (string $method, string $route, array $body) {
    changeBooking($method, $route, $body, token: '')->assertUnauthorized();
})->with('booking changes');

test('a booking change needs a key that can write', function (string $method, string $route, array $body) {
    changeBooking($method, $route, $body, token: $this->readOnlyToken)->assertForbidden();

    expect($this->booking->refresh()->status)->toBe(BookingStatus::Confirmed);
})->with('booking changes');

test('another organization\'s booking is not found', function (string $method, string $route, array $body) {
    ['token' => $theirs] = ApiKey::issue('Someone else', null, Team::factory()->create(), canWrite: true);

    changeBooking($method, $route, $body, token: $theirs)->assertNotFound();
    changeBooking($method, $route, $body, uid: 'no-such-booking')->assertNotFound();

    expect($this->booking->refresh()->status)->toBe(BookingStatus::Confirmed);
})->with('booking changes');

test('a booking that can no longer change is gone', function (string $method, string $route, array $body) {
    $this->booking->update(['status' => BookingStatus::Canceled, 'canceled_at' => now()]);

    changeBooking($method, $route, $body)->assertStatus(410);
})->with('booking changes');

test('a meeting that has already started cannot change', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-02 12:10:00', 'UTC'));

    changeBooking('POST', 'api.v1.bookings.hand-off.store', ['email' => 'sofia@texra.net'])->assertStatus(410);
});

test('re-tagging a zone stores it and reports booking.updated', function () {
    Queue::fake();

    changeBooking('PUT', 'api.v1.bookings.zone.update', ['zone' => 'spanish'])
        ->assertOk()
        ->assertJsonPath('data.uid', $this->booking->uid)
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.zone', 'spanish')
        ->assertJsonPath('data.host.email', 'samuel@texra.net');

    expect($this->booking->refresh()->zone)->toBe('spanish')
        ->and($this->booking->keptHost())->toBeNull();

    Queue::assertPushed(SendBookingWebhook::class, fn (SendBookingWebhook $job): bool => $job->event === 'booking.updated'
        && $job->changes === ['zone']);
});

test('a zone can be cleared', function () {
    $this->booking->update(['zone' => 'spanish']);

    changeBooking('PUT', 'api.v1.bookings.zone.update', ['zone' => null])
        ->assertOk()
        ->assertJsonPath('data.zone', null);

    expect($this->booking->refresh()->zone)->toBeNull();
});

test('a zone is a slug and must be sent', function (array $body) {
    changeBooking('PUT', 'api.v1.bookings.zone.update', $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['zone']);
})->with([
    'not a slug' => [['zone' => 'Spanish!']],
    'missing' => [[]],
]);

test('handing off moves the meeting to the named host at the same time', function () {
    Notification::fake();
    Queue::fake();

    $response = changeBooking('POST', 'api.v1.bookings.hand-off.store', ['email' => 'Sofia@texra.net', 'reason' => 'Needs a Spanish speaker'])
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.host.email', 'sofia@texra.net')
        ->assertJsonPath('data.startsAt', '2026-09-02T12:00:00+00:00');

    $replacement = Booking::where('rescheduled_from_id', $this->booking->id)->firstOrFail();

    expect($response->json('data.uid'))->toBe($replacement->uid)->not->toBe($this->booking->uid)
        ->and($replacement->user_id)->toBe($this->spanishHost->id)
        ->and($replacement->hosts->modelKeys())->toBe([$this->spanishHost->id])
        ->and($replacement->reference)->toBe('lead-token-123')
        ->and($this->booking->refresh()->status)->toBe(BookingStatus::Rescheduled)
        ->and($this->booking->cancellation_reason)->toBe('Needs a Spanish speaker')
        ->and($this->booking->canceled_by)->toBe('host');

    Notification::assertSentTo($this->spanishHost, BookingRescheduled::class);
    Notification::assertSentTo($this->host, BookingCanceled::class);
    Queue::assertPushed(SendBookingWebhook::class, fn (SendBookingWebhook $job): bool => $job->booking->is($replacement)
        && $job->event === 'booking.rescheduled');
});

test('a booking tagged with a zone is handed to a host in it', function () {
    Notification::fake();
    $this->booking->update(['zone' => 'spanish']);

    changeBooking('POST', 'api.v1.bookings.hand-off.store', ['email' => 'sofia@texra.net'])
        ->assertOk()
        ->assertJsonPath('data.zone', 'spanish')
        ->assertJsonPath('data.host.email', 'sofia@texra.net');
});

test('handing off to the host who already has it changes nothing', function () {
    Notification::fake();

    changeBooking('POST', 'api.v1.bookings.hand-off.store', ['email' => 'SAMUEL@texra.net'])
        ->assertOk()
        ->assertJsonPath('data.uid', $this->booking->uid)
        ->assertJsonPath('data.host.email', 'samuel@texra.net');

    expect($this->booking->refresh()->status)->toBe(BookingStatus::Confirmed)
        ->and(Booking::where('rescheduled_from_id', $this->booking->id)->exists())->toBeFalse();

    Notification::assertNothingSent();
});

test('a meeting cannot be handed to somebody who does not host it', function () {
    User::factory()->create(['email' => 'outsider@texra.net']);

    changeBooking('POST', 'api.v1.bookings.hand-off.store', ['email' => 'outsider@texra.net'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    changeBooking('POST', 'api.v1.bookings.hand-off.store', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    expect($this->booking->refresh()->status)->toBe(BookingStatus::Confirmed);
});

test('a one-on-one meeting cannot be handed off', function () {
    $this->eventType->update(['kind' => EventTypeKind::OneOnOne]);

    changeBooking('POST', 'api.v1.bookings.hand-off.store', ['email' => 'sofia@texra.net'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

test('a host who is busy then is a conflict, and the meeting stays put', function () {
    Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'user_id' => $this->spanishHost->id,
        'team_id' => $this->eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 12:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 12:30:00', 'UTC'),
    ])->hosts()->attach($this->spanishHost);

    changeBooking('POST', 'api.v1.bookings.hand-off.store', ['email' => 'sofia@texra.net'])
        ->assertStatus(409)
        ->assertJsonPath('message', 'That time is no longer available.');

    expect($this->booking->refresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($this->booking->user_id)->toBe($this->host->id);
});

test('a host outside the booking\'s zone is a conflict', function () {
    HostZone::query()->where('user_id', $this->spanishHost->id)->delete();
    HostZone::query()->create(['team_id' => $this->team->id, 'user_id' => $this->host->id, 'zone' => 'spanish']);
    $this->booking->update(['zone' => 'spanish']);

    changeBooking('POST', 'api.v1.bookings.hand-off.store', ['email' => 'sofia@texra.net'])->assertStatus(409);

    expect($this->booking->refresh()->status)->toBe(BookingStatus::Confirmed);
});

test('canceling calls the meeting off on the host side and tells everyone', function () {
    Notification::fake();
    Queue::fake();

    changeBooking('POST', 'api.v1.bookings.cancel.store', ['reason' => 'Nobody who speaks Spanish is free then'])
        ->assertOk()
        ->assertJsonPath('data.uid', $this->booking->uid)
        ->assertJsonPath('data.status', 'canceled')
        ->assertJsonPath('data.host.email', 'samuel@texra.net');

    expect($this->booking->refresh()->status)->toBe(BookingStatus::Canceled)
        ->and($this->booking->canceled_by)->toBe('host')
        ->and($this->booking->cancellation_reason)->toBe('Nobody who speaks Spanish is free then');

    Notification::assertSentTo($this->host, BookingCanceled::class);
    Queue::assertPushed(SendBookingWebhook::class, fn (SendBookingWebhook $job): bool => $job->booking->is($this->booking)
        && $job->event === 'booking.canceled');
});
