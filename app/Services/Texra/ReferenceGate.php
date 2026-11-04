<?php

namespace App\Services\Texra;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Asks TEXRA whether a lead's booking link may still book: first claim wins.
 *
 * Once an agent claims the lead in TEXRA, the renter must not book a call
 * with somebody else through the link they were sent. A link pinned to the
 * agent who holds the lead (`host=<email>`) keeps working, so the holder can
 * still be booked.
 *
 * The request is signed exactly like SendBookingWebhook: an HMAC-SHA256 of
 * "{timestamp}.{body}" with the shared secret, in X-Bookings-Signature.
 *
 * It fails OPEN. Only a definite `open: false` from TEXRA closes a link; no
 * URL configured, a timeout, a connection error, a non-200 or a body that is
 * not the expected shape all let the renter book, as before this check
 * existed. A renter who cannot book loses the lead; a booking with the wrong
 * agent is a hand-off. Answers are never cached across requests, because
 * claims change minute to minute; one request asking twice is answered once.
 */
class ReferenceGate
{
    /**
     * TEXRA's answers within this request, by reference.
     *
     * @var array<string, array{open: bool, host: string|null}>
     */
    protected array $answers = [];

    /**
     * Determine whether TEXRA is set up to be asked at all.
     */
    public function isConfigured(): bool
    {
        return filled(config('services.texra.reference_url'));
    }

    /**
     * Determine whether a link carrying this reference may book.
     *
     * @param  string|null  $reference  The link's `ref` token.
     * @param  string|null  $pinnedHost  The email of the host the link is pinned to, if any.
     */
    public function allows(?string $reference, ?string $pinnedHost = null): bool
    {
        if (blank($reference) || ! $this->isConfigured()) {
            return true;
        }

        $answer = $this->answers[$reference] ??= $this->ask($reference);

        if ($answer['open']) {
            return true;
        }

        return filled($pinnedHost)
            && $answer['host'] !== null
            && strcasecmp(trim($pinnedHost), trim($answer['host'])) === 0;
    }

    /**
     * Ask TEXRA about one reference, treating anything but a clear answer as open.
     *
     * @return array{open: bool, host: string|null}
     */
    protected function ask(string $reference): array
    {
        $open = ['open' => true, 'host' => null];

        $body = json_encode(['reference' => $reference], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->getTimestamp();
        // An env line left empty (TEXRA_REFERENCE_SECRET=) still means "the webhook's".
        $secret = (string) (config('services.texra.reference_secret') ?: config('services.texra.webhook_secret'));

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'User-Agent' => 'CoreSched',
                'X-Bookings-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
                'X-Bookings-Timestamp' => $timestamp,
            ])
                ->timeout(3)
                ->connectTimeout(3)
                ->withBody($body, 'application/json')
                ->post((string) config('services.texra.reference_url'));
        } catch (ConnectionException $exception) {
            report($exception);

            return $open;
        }

        $isOpen = $response->json('open');
        $host = $response->json('host');

        if ($response->status() !== 200 || ! is_bool($isOpen) || ($host !== null && ! is_string($host))) {
            report(new RuntimeException("TEXRA answered the reference check with HTTP {$response->status()} and an unexpected body; the link stays open."));

            return $open;
        }

        if (! $isOpen) {
            Log::info('Booking link closed: TEXRA says the lead is held.', [
                'reference' => $reference,
                'host' => $host,
            ]);
        }

        return ['open' => $isOpen, 'host' => blank($host) ? null : $host];
    }
}
