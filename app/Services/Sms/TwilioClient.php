<?php

namespace App\Services\Sms;

use App\Support\PhoneNumber;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to Twilio's REST API over plain HTTP, the way the calendar and mail
 * providers do -- no SDK, so tests fake it with Http::fake().
 *
 * Each organization texts through its own Twilio account (Team::twilio()),
 * and picks which of that account's numbers to send from
 * (Team::sms_from_number). There is no installation-wide account.
 */
class TwilioClient
{
    public function __construct(
        protected ?string $accountSid = null,
        protected ?string $authToken = null,
    ) {}

    /**
     * Determine whether this client has credentials at all.
     *
     * An organization that has not connected Twilio is the normal case, so
     * nothing may assume this is configured.
     */
    public function isConfigured(): bool
    {
        return filled($this->accountSid) && filled($this->authToken);
    }

    /**
     * Check the credentials against Twilio by reading the account itself.
     *
     * Used when an admin saves them, so a mistyped token is refused on the
     * settings page rather than discovered when a text fails to send.
     */
    public function credentialsAreValid(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        return $this->request()->get($this->url('').'.json')->successful();
    }

    /**
     * Get the numbers the account can send from.
     *
     * Cached briefly per account: this is read every time an admin opens the
     * organization settings, and the list changes only when someone buys a
     * number. The key includes a hash of the token too, so replacing a wrong
     * token is not answered from the empty list the wrong one produced.
     *
     * @return array<int, array{number: string, label: string}>
     */
    public function numbers(bool $fresh = false): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $cacheKey = 'twilio.numbers.'.$this->accountSid.'.'.hash('sha256', (string) $this->authToken);

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addMinutes(10), function () {
            $response = $this->request()->get($this->url('IncomingPhoneNumbers.json'), ['PageSize' => 100]);

            if ($response->failed()) {
                return [];
            }

            $payload = $response->json('incoming_phone_numbers');

            if (! is_array($payload)) {
                return [];
            }

            $numbers = [];

            foreach ($payload as $entry) {
                // A number is only useful if Twilio told us what it is; a
                // friendly name is a convenience and falls back to the number.
                $number = is_array($entry) ? (string) ($entry['phone_number'] ?? '') : '';

                if ($number === '') {
                    continue;
                }

                $numbers[] = [
                    'number' => $number,
                    'label' => (string) ($entry['friendly_name'] ?? $number),
                ];
            }

            return $numbers;
        });
    }

    /**
     * Send one text message.
     *
     * @throws RuntimeException when Twilio refuses it
     */
    public function send(string $from, string $to, string $body): void
    {
        $recipient = PhoneNumber::toE164($to);

        if ($recipient === null) {
            throw new RuntimeException('"'.$to.'" is not a number that can be texted.');
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException('This organization has not connected a Twilio account.');
        }

        $response = $this->request()->asForm()->post($this->url('Messages.json'), [
            'From' => $from,
            'To' => $recipient,
            'Body' => $body,
        ]);

        if ($response->failed()) {
            // Twilio explains itself in the body; the status alone says little.
            throw new RuntimeException(
                'Twilio refused the message: '.($response->json('message') ?? $response->status()),
            );
        }
    }

    /**
     * Start a request carrying the account's credentials.
     */
    protected function request(): PendingRequest
    {
        return Http::withBasicAuth((string) $this->accountSid, (string) $this->authToken)->timeout(15);
    }

    /**
     * Build the URL of an account-scoped resource. An empty resource is the
     * account itself (callers append ".json").
     */
    protected function url(string $resource): string
    {
        $account = rtrim((string) config('services.twilio.base_url'), '/')
            .'/Accounts/'.rawurlencode((string) $this->accountSid);

        return $resource === '' ? $account : $account.'/'.$resource;
    }
}
