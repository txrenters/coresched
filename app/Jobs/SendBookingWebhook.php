<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Services\Webhooks\BookingWebhookPayload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Reports one booking event to the system that sent the invitee here.
 *
 * The delivery id is fixed when the job is created, so every retry presents
 * the same id and the receiver can drop repeats. The timestamp and the
 * signature are computed at send time, so a retry an hour later still
 * verifies. The signature covers "{timestamp}.{body}" with the shared
 * secret: a captured body cannot be replayed with a fresh timestamp.
 *
 * A 5xx or a connection failure is retried on the queue's schedule; a 4xx
 * means the receiver understood and refused, which no retry will change.
 */
class SendBookingWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 600, 1800];

    public readonly string $deliveryId;

    /**
     * @param  list<string>  $changes  The columns a booking.updated event is about.
     */
    public function __construct(
        public Booking $booking,
        public string $event,
        public int $sequence,
        ?string $deliveryId = null,
        public array $changes = [],
    ) {
        $this->deliveryId = $deliveryId ?? (string) Str::uuid();
    }

    public function handle(BookingWebhookPayload $payloads): void
    {
        $url = (string) config('services.texra.webhook_url', '');
        $secret = (string) config('services.texra.webhook_secret', '');
        $booking = $this->booking->fresh();

        if ($url === '' || $booking === null || $booking->reference === null) {
            return;
        }

        $body = json_encode(
            $payloads->build($booking, $this->event, $this->sequence, $this->deliveryId, now(), $this->changes),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $timestamp = (string) now()->getTimestamp();

        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'User-Agent' => 'TexasRenters-Bookings',
            'X-Bookings-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
            'X-Bookings-Timestamp' => $timestamp,
            'X-Bookings-Delivery' => $this->deliveryId,
        ])
            ->timeout(10)
            ->withBody($body, 'application/json')
            ->post($url);

        if ($response->successful()) {
            Log::info('Booking webhook delivered.', [
                'booking' => $booking->uid,
                'event' => $this->event,
                'delivery' => $this->deliveryId,
                'result' => $response->json('result'),
            ]);

            return;
        }

        if ($response->clientError()) {
            $refusal = new RuntimeException("The receiver refused booking webhook {$this->deliveryId} with HTTP {$response->status()}.");

            Log::warning('Booking webhook refused; not retrying.', [
                'booking' => $booking->uid,
                'event' => $this->event,
                'delivery' => $this->deliveryId,
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 500),
            ]);

            $this->fail($refusal);

            return;
        }

        throw new RuntimeException("The receiver answered booking webhook {$this->deliveryId} with HTTP {$response->status()}.");
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Booking webhook gave up.', [
            'booking' => $this->booking->uid,
            'event' => $this->event,
            'delivery' => $this->deliveryId,
            'reason' => $exception?->getMessage(),
        ]);
    }
}
