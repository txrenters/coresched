<?php

namespace App\Services\Calendar\Providers;

use App\Data\Calendar\ExternalCalendar;
use App\Data\Calendar\ExternalEvent;
use App\Data\Calendar\ExternalLeave;
use App\Data\Calendar\ProviderIdentity;
use App\Enums\CalendarProvider;
use App\Exceptions\MailboxAccessDeniedException;
use App\Models\Booking;
use App\Models\CalendarAccount;
use App\Services\Calendar\CalendarProviderContract;
use App\Services\Calendar\DetectsLeaveContract;
use App\Services\Ics\IcsGenerator;
use App\Support\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MicrosoftCalendarProvider implements CalendarProviderContract, DetectsLeaveContract
{
    protected const GRAPH_URL = 'https://graph.microsoft.com/v1.0';

    /**
     * The scopes required to read busy times, spot an out of office reply
     * and manage our own events.
     *
     * @var array<int, string>
     */
    protected array $scopes = [
        'openid',
        'email',
        'offline_access',
        'User.Read',
        'Calendars.ReadWrite',
        'MailboxSettings.Read',
    ];

    public function __construct(protected IcsGenerator $ics)
    {
        //
    }

    /**
     * Build the URL that starts the consent flow.
     */
    public function authorizationUrl(string $state): string
    {
        return $this->endpoint('authorize').'?'.http_build_query([
            'client_id' => $this->config('client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'response_mode' => 'query',
            'scope' => implode(' ', $this->scopes),
            'state' => $state,
        ]);
    }

    /**
     * Trade an authorization code for tokens and the account identity.
     */
    public function exchangeCode(string $code): ProviderIdentity
    {
        $tokens = Http::asForm()
            ->post($this->endpoint('token'), [
                'code' => $code,
                'client_id' => $this->config('client_id'),
                'client_secret' => $this->config('client_secret'),
                'redirect_uri' => $this->redirectUri(),
                'grant_type' => 'authorization_code',
                'scope' => implode(' ', $this->scopes),
            ])
            ->throw()
            ->json();

        $profile = Http::withToken($tokens['access_token'])
            ->get(self::GRAPH_URL.'/me')
            ->throw()
            ->json();

        return new ProviderIdentity(
            externalId: (string) $profile['id'],
            email: (string) ($profile['mail'] ?? $profile['userPrincipalName']),
            accessToken: $tokens['access_token'],
            refreshToken: $tokens['refresh_token'] ?? null,
            expiresAt: isset($tokens['expires_in'])
                ? CarbonImmutable::now()->addSeconds((int) $tokens['expires_in'])
                : null,
        );
    }

    /**
     * Refresh an expired access token in place.
     */
    public function refreshToken(CalendarAccount $account): void
    {
        if (blank($account->refresh_token)) {
            throw new RuntimeException('The Microsoft account has no refresh token; it must be reconnected.');
        }

        $tokens = Http::asForm()
            ->post($this->endpoint('token'), [
                'refresh_token' => $account->refresh_token,
                'client_id' => $this->config('client_id'),
                'client_secret' => $this->config('client_secret'),
                'grant_type' => 'refresh_token',
                'scope' => implode(' ', $this->scopes),
            ])
            ->throw()
            ->json();

        $account->update([
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? $account->refresh_token,
            'token_expires_at' => isset($tokens['expires_in'])
                ? CarbonImmutable::now()->addSeconds((int) $tokens['expires_in'])
                : null,
        ]);
    }

    /**
     * List the calendars available on the account.
     *
     * @return Collection<int, ExternalCalendar>
     */
    public function listCalendars(CalendarAccount $account): Collection
    {
        $items = $this->request($account)
            ->get(self::GRAPH_URL.'/me/calendars')
            ->throw()
            ->json('value', []);

        return (new Collection($items))->map(fn (array $item) => new ExternalCalendar(
            id: $item['id'],
            name: $item['name'] ?? $item['id'],
            isPrimary: (bool) ($item['isDefaultCalendar'] ?? false),
        ))->values();
    }

    /**
     * Get the busy periods across the given calendars.
     *
     * @param  array<int, string>  $calendarIds
     * @return Collection<int, TimeRange>
     */
    public function busyPeriods(CalendarAccount $account, array $calendarIds, TimeRange $window): Collection
    {
        /** @var Collection<int, TimeRange> $busy */
        $busy = new Collection;

        foreach ($calendarIds as $calendarId) {
            $events = $this->request($account)
                ->withHeaders(['Prefer' => 'outlook.timezone="UTC"'])
                ->get(self::GRAPH_URL."/me/calendars/{$calendarId}/calendarView", [
                    'startDateTime' => $window->start->toIso8601String(),
                    'endDateTime' => $window->end->toIso8601String(),
                    '$select' => 'start,end,showAs,isCancelled,categories',
                    '$top' => 250,
                ])
                ->throw()
                ->json('value', []);

            foreach ($events as $event) {
                if (($event['isCancelled'] ?? false) || ($event['showAs'] ?? 'busy') === 'free') {
                    continue;
                }

                // Outlook files the holidays it adds to a calendar under the
                // Holiday category. Holidays are the organization's call, made
                // in its advanced settings, so they never block time here.
                if ($this->isHoliday($event)) {
                    continue;
                }

                $busy->push(TimeRange::make(
                    $this->parseGraphDate($event['start']),
                    $this->parseGraphDate($event['end']),
                ));
            }
        }

        return $busy->values();
    }

    /**
     * Determine if an event is a holiday Outlook added to the calendar.
     *
     * @param  array<string, mixed>  $event
     */
    protected function isHoliday(array $event): bool
    {
        foreach ((array) ($event['categories'] ?? []) as $category) {
            if (is_string($category) && in_array(strtolower(trim($category)), ['holiday', 'holidays'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the leave the account's mailbox is announcing inside the window.
     *
     * Outlook's automatic reply is the signal. A scheduled reply carries the
     * dates the user picked; one switched on with no schedule has no end, so
     * it blocks the rest of the window and the next sync renews it.
     */
    public function leavePeriod(CalendarAccount $account, TimeRange $window): ?ExternalLeave
    {
        $response = $this->request($account)
            ->get(self::GRAPH_URL.'/me/mailboxSettings/automaticRepliesSetting');

        if ($response->forbidden()) {
            throw MailboxAccessDeniedException::needsReconnect($account->email);
        }

        $setting = $response->throw()->json();
        $status = $setting['status'] ?? 'disabled';

        if ($status === 'disabled') {
            return null;
        }

        $scheduled = $status === 'scheduled';

        $startsAt = $scheduled && isset($setting['scheduledStartDateTime'])
            ? $this->parseGraphDate($setting['scheduledStartDateTime'])
            : $window->start;

        $endsAt = $scheduled && isset($setting['scheduledEndDateTime'])
            ? $this->parseGraphDate($setting['scheduledEndDateTime'])
            : $window->end;

        if ($endsAt <= $window->start) {
            return null;
        }

        return new ExternalLeave($startsAt, $endsAt, $this->replyMessage($setting));
    }

    /**
     * Get the reply text as plain prose, since Graph returns it as HTML.
     *
     * @param  array<string, mixed>  $setting
     */
    protected function replyMessage(array $setting): ?string
    {
        $html = (string) ($setting['internalReplyMessage'] ?? $setting['externalReplyMessage'] ?? '');
        $text = trim(html_entity_decode(strip_tags($html)));

        return blank($text) ? null : $text;
    }

    /**
     * Write a booking to the account's calendar.
     *
     * The event carries no attendees. Graph has no equivalent of Google's
     * sendUpdates=none: any attendee on an event is emailed an Outlook invite
     * from the calendar owner, on top of the booking's own confirmation. The
     * confirmation and its .ics are the one invite everyone gets.
     */
    public function createEvent(CalendarAccount $account, string $calendarId, Booking $booking, bool $withOnlineMeeting): ExternalEvent
    {
        $timezone = $this->eventTimezone($account);

        $payload = [
            'subject' => $this->ics->title($booking),
            'body' => ['contentType' => 'text', 'content' => $this->ics->description($booking)],
            'start' => ['dateTime' => $booking->starts_at->setTimezone($timezone)->format('Y-m-d\TH:i:s'), 'timeZone' => $timezone],
            'end' => ['dateTime' => $booking->ends_at->setTimezone($timezone)->format('Y-m-d\TH:i:s'), 'timeZone' => $timezone],
        ];

        if ($withOnlineMeeting) {
            $payload['isOnlineMeeting'] = true;
            $payload['onlineMeetingProvider'] = 'teamsForBusiness';
        } elseif (filled($booking->location_detail)) {
            $payload['location'] = ['displayName' => $booking->location_detail];
        }

        $event = $this->request($account)
            ->post(self::GRAPH_URL."/me/calendars/{$calendarId}/events", $payload)
            ->throw()
            ->json();

        return new ExternalEvent(
            id: $event['id'],
            calendarId: $calendarId,
            meetingUrl: data_get($event, 'onlineMeeting.joinUrl'),
        );
    }

    /**
     * Remove a previously written event.
     */
    public function deleteEvent(CalendarAccount $account, string $calendarId, string $eventId): void
    {
        $response = $this->request($account)
            ->delete(self::GRAPH_URL."/me/calendars/{$calendarId}/events/{$eventId}");

        if ($response->status() !== 404) {
            $response->throw();
        }
    }

    /**
     * Get an HTTP client authenticated as the account.
     */
    protected function request(CalendarAccount $account): PendingRequest
    {
        return Http::withToken($account->access_token)->acceptJson();
    }

    /**
     * Get the timezone an event is written in.
     *
     * Outlook shows an opened event in the timezone it was created in, so an
     * event written in UTC reads five or six hours off to a host in Texas even
     * though the calendar grid places it correctly. Graph accepts IANA names.
     */
    protected function eventTimezone(CalendarAccount $account): string
    {
        return $account->user?->timezone ?: config('scheduling.default_timezone');
    }

    /**
     * Turn a Graph date/timezone pair into a parseable string.
     *
     * @param  array{dateTime: string, timeZone?: string}  $value
     */
    protected function parseGraphDate(array $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value['dateTime'], $value['timeZone'] ?? 'UTC')->utc();
    }

    /**
     * Build a tenant aware identity endpoint.
     */
    protected function endpoint(string $path): string
    {
        $tenant = $this->config('tenant') ?: 'common';

        return "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/{$path}";
    }

    /**
     * Read a value from the provider's service configuration.
     */
    protected function config(string $key): ?string
    {
        return config(CalendarProvider::Microsoft->configKey().'.'.$key);
    }

    /**
     * Get the OAuth redirect URI for this provider.
     */
    protected function redirectUri(): string
    {
        return route('integrations.callback', ['provider' => CalendarProvider::Microsoft->value]);
    }
}
