<?php

use App\Enums\LocationType;
use App\Jobs\SendBookingWebhook;
use App\Models\Booking;
use App\Services\Webhooks\BookingWebhookPayload;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->freezeTime();

    config([
        'services.texra.webhook_url' => 'https://texra.example.com/api/bookings/webhook',
        'services.texra.webhook_secret' => 'shared-secret',
    ]);

    $this->booking = Booking::factory()->create(['reference' => 'lead-token-123', 'webhook_sequence' => 1]);
});

function runWebhook(Booking $booking, string $event = 'booking.confirmed', int $sequence = 1, ?string $deliveryId = null): SendBookingWebhook
{
    $job = new SendBookingWebhook($booking, $event, $sequence, $deliveryId);
    $job->handle(app(BookingWebhookPayload::class));

    return $job;
}

test('posts a payload signed over the timestamp and body that the receiver can verify', function () {
    Http::fake([
        'texra.example.com/*' => Http::response(['result' => 'assigned']),
        '*' => Http::response('unexpected host', 500),
    ]);

    $job = runWebhook($this->booking);

    Http::assertSent(function (Request $request) use ($job) {
        $timestamp = $request->header('X-Bookings-Timestamp')[0];
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), 'shared-secret');
        $payload = json_decode($request->body(), true);

        return $request->url() === 'https://texra.example.com/api/bookings/webhook'
            && $request->header('X-Bookings-Signature')[0] === $expected
            && $request->header('X-Bookings-Delivery')[0] === $job->deliveryId
            && $timestamp === (string) now()->getTimestamp()
            && $payload['id'] === $job->deliveryId
            && $payload['event'] === 'booking.confirmed'
            && $payload['sequence'] === 1
            && $payload['booking']['uid'] === $this->booking->uid
            && $payload['booking']['reference'] === 'lead-token-123'
            && $payload['booking']['status'] === 'confirmed'
            && $payload['booking']['host']['email'] === $this->booking->host->email
            && $payload['booking']['invitee']['email'] === $this->booking->email
            && $payload['booking']['invitee']['phone'] === null
            && $payload['booking']['manage_url'] === route('booking.show', ['booking' => $this->booking->uid]);
    });
});

test('the invitee phone is reported only for a booking that asked them for one', function () {
    Http::fake(['*' => Http::response(['result' => 'recorded'])]);

    $phoneCall = Booking::factory()->create([
        'reference' => 'lead-token-456',
        'location_type' => LocationType::Phone,
        'location_detail' => '2815550100',
    ]);

    runWebhook($phoneCall);

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['booking']['invitee']['phone'] === '2815550100');
});

test('keeps its delivery id across retries and signs afresh each time', function () {
    Http::fake(['*' => Http::response(['result' => 'recorded'])]);

    $job = new SendBookingWebhook($this->booking, 'booking.confirmed', 1);
    $job->handle(app(BookingWebhookPayload::class));

    $this->travel(2)->hours();
    $job->handle(app(BookingWebhookPayload::class));

    $deliveries = Http::recorded()->map(fn (array $pair) => $pair[0]->header('X-Bookings-Delivery')[0]);
    $timestamps = Http::recorded()->map(fn (array $pair) => $pair[0]->header('X-Bookings-Timestamp')[0]);

    expect($deliveries->unique())->toHaveCount(1)
        ->and($timestamps->unique())->toHaveCount(2);
});

test('a server error is thrown so the queue retries', function () {
    Http::fake(['*' => Http::response('down', 503)]);

    expect(fn () => runWebhook($this->booking))->toThrow(RuntimeException::class);
});

test('a client error is final and is not thrown', function () {
    Http::fake(['*' => Http::response(['message' => 'The given data was invalid.'], 422)]);

    runWebhook($this->booking);

    Http::assertSentCount(1);
});

test('sends nothing without a URL', function () {
    config(['services.texra.webhook_url' => '']);
    Http::fake();

    runWebhook($this->booking);

    Http::assertNothingSent();
});

test('sends nothing for a booking that carries no reference', function () {
    Http::fake();

    runWebhook(Booking::factory()->create());

    Http::assertNothingSent();
});
