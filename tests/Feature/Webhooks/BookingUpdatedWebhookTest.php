<?php

use App\Enums\BookingStatus;
use App\Jobs\SendBookingWebhook;
use App\Models\Booking;
use App\Services\Webhooks\BookingWebhookPayload;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'services.texra.webhook_url' => 'https://texra.example.com/api/bookings/webhook',
        'services.texra.webhook_secret' => 'shared-secret',
    ]);
});

test('any other change to a referenced booking is reported as booking.updated, naming what changed', function (array $change, array $expected) {
    $booking = Booking::factory()->create(['reference' => 'lead-token-123']);
    Queue::fake();

    $booking->update($change);

    Queue::assertPushed(SendBookingWebhook::class, fn (SendBookingWebhook $job) => $job->event === 'booking.updated'
        && $job->changes === $expected
        && $job->sequence === 1);
})->with([
    'a no-show' => [fn () => ['no_show_at' => now()], ['no_show_at']],
    'the host notes' => [['host_notes' => 'Called, wants a 2 bed'], ['host_notes']],
    'the meeting link' => [['meeting_url' => 'https://meet.example.com/abc'], ['meeting_url']],
    'the invitee details' => [['name' => 'New Name', 'email' => 'new@example.com'], ['name', 'email']],
]);

test('a booking nobody sent here is never reported', function () {
    $booking = Booking::factory()->create(['reference' => null]);
    Queue::fake();

    $booking->update(['host_notes' => 'Private']);

    Queue::assertNotPushed(SendBookingWebhook::class);
});

test('a lifecycle change is left to its own event, not repeated as an update', function () {
    $booking = Booking::factory()->create(['reference' => 'lead-token-123']);
    Queue::fake();

    $booking->update([
        'status' => BookingStatus::Canceled,
        'canceled_at' => now(),
        'cancellation_reason' => 'Changed plans',
    ]);

    Queue::assertNotPushed(SendBookingWebhook::class, fn (SendBookingWebhook $job) => $job->event === 'booking.updated');
});

test('bumping the sequence does not report itself', function () {
    $booking = Booking::factory()->create(['reference' => 'lead-token-123']);
    Queue::fake();

    $booking->update(['host_notes' => 'First note']);

    Queue::assertPushed(SendBookingWebhook::class, 1);
    expect($booking->fresh()->webhook_sequence)->toBe(1);
});

test('the update payload carries the changed columns and the fields they live in', function () {
    Http::fake(['*' => Http::response(['result' => 'recorded'])]);

    $booking = Booking::factory()->create([
        'reference' => 'lead-token-123',
        'host_notes' => 'Wants a 2 bed',
        'meeting_url' => 'https://meet.example.com/abc',
        'no_show_at' => now(),
        'webhook_sequence' => 4,
    ]);

    (new SendBookingWebhook($booking, 'booking.updated', 4, changes: ['no_show_at']))
        ->handle(app(BookingWebhookPayload::class));

    Http::assertSent(function (Request $request) {
        $payload = json_decode($request->body(), true);

        return $payload['event'] === 'booking.updated'
            && $payload['changes'] === ['no_show_at']
            && $payload['booking']['host_notes'] === 'Wants a 2 bed'
            && $payload['booking']['meeting_url'] === 'https://meet.example.com/abc'
            && $payload['booking']['no_show_at'] !== null;
    });
});
