<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config([
        'mail.default' => 'microsoft-graph',
        'mail.mailers.microsoft-graph' => [
            'transport' => 'microsoft-graph',
            'tenant' => 'tenant-id',
            'client_id' => 'mail-id',
            'client_secret' => 'mail-secret',
            'mailbox' => 'scheduling@texasrenters.com',
            'save_to_sent_items' => false,
            'send_as' => ['no-reply@texasrenters.com'],
        ],
        'mail.from' => ['address' => 'scheduling@texasrenters.com', 'name' => 'TR Scheduling'],
    ]);
});

/**
 * Fake the token endpoint and sendMail, optionally failing the send.
 *
 * Called per test rather than in beforeEach: a later Http::fake() is matched
 * after the earlier one, so a shared default would shadow the failure case.
 */
function fakeGraph(mixed $sendMail = null): void
{
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'graph-token', 'expires_in' => 3600]),
        'graph.microsoft.com/*' => $sendMail ?? Http::response([], 202),
    ]);
}

/**
 * Post the given mail through the Graph transport and return the sendMail body.
 *
 * @return array<string, mixed>
 */
function sendThroughGraph(callable $build): array
{
    Mail::raw('ignored', $build);

    $request = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->last(fn ($request) => str_contains($request->url(), 'graph.microsoft.com'));

    return $request->data();
}

test('mail is sent from the shared mailbox through graph', function () {
    fakeGraph();
    $body = sendThroughGraph(fn ($message) => $message
        ->to('invitee@example.com', 'Invitee')
        ->subject('Your booking is confirmed'));

    Http::assertSent(fn ($request) => $request->url() === 'https://login.microsoftonline.com/tenant-id/oauth2/v2.0/token'
        && $request['grant_type'] === 'client_credentials'
        && $request['scope'] === 'https://graph.microsoft.com/.default');

    Http::assertSent(fn ($request) => $request->url() === 'https://graph.microsoft.com/v1.0/users/scheduling%40texasrenters.com/sendMail'
        && $request->hasHeader('Authorization', 'Bearer graph-token'));

    expect($body['message']['subject'])->toBe('Your booking is confirmed')
        ->and($body['message']['from']['emailAddress']['address'])->toBe('scheduling@texasrenters.com')
        ->and($body['message']['toRecipients'])->toBe([
            ['emailAddress' => ['address' => 'invitee@example.com', 'name' => 'Invitee']],
        ])
        ->and($body['saveToSentItems'])->toBeFalse();
});

test('a copy is kept in sent items when the mailer is set to save one', function () {
    config(['mail.mailers.microsoft-graph.save_to_sent_items' => true]);
    fakeGraph();
    $body = sendThroughGraph(fn ($message) => $message
        ->to('invitee@example.com')
        ->subject('Reminder: Showing in 1 hour'));

    expect($body['saveToSentItems'])->toBeTrue();
});

test('the mailer keeps a sent items copy when the setting is left out', function () {
    config(['mail.mailers.microsoft-graph' => collect(config('mail.mailers.microsoft-graph'))->except('save_to_sent_items')->all()]);
    fakeGraph();
    $body = sendThroughGraph(fn ($message) => $message
        ->to('invitee@example.com')
        ->subject('Reminder: Showing in 1 hour'));

    expect($body['saveToSentItems'])->toBeTrue();
});

test('mail from the no-reply address is sent through that mailbox', function () {
    fakeGraph();
    $body = sendThroughGraph(fn ($message) => $message
        ->from('no-reply@texasrenters.com', 'TR Scheduling')
        ->to('someone@example.com')
        ->subject('Reset Password Notification'));

    Http::assertSent(fn ($request) => $request->url() === 'https://graph.microsoft.com/v1.0/users/no-reply%40texasrenters.com/sendMail');

    expect($body['message']['from']['emailAddress']['address'])->toBe('no-reply@texasrenters.com');
});

test('an unexpected from address still sends through the configured mailbox', function () {
    fakeGraph();
    sendThroughGraph(fn ($message) => $message
        ->from('ceo@texasrenters.com')
        ->to('someone@example.com')
        ->subject('Hello'));

    Http::assertSent(fn ($request) => $request->url() === 'https://graph.microsoft.com/v1.0/users/scheduling%40texasrenters.com/sendMail');
});

test('cc, bcc and reply-to are only sent when they carry addresses', function () {
    fakeGraph();
    $body = sendThroughGraph(fn ($message) => $message
        ->to('invitee@example.com')
        ->cc('host@texasrenters.com')
        ->subject('Booked'));

    expect($body['message']['ccRecipients'])->toBe([
        ['emailAddress' => ['address' => 'host@texasrenters.com']],
    ])
        ->and($body['message'])->not->toHaveKey('bccRecipients')
        ->and($body['message'])->not->toHaveKey('replyTo');
});

test('the calendar invite rides along as a base64 file attachment', function () {
    fakeGraph();
    $body = sendThroughGraph(fn ($message) => $message
        ->to('invitee@example.com')
        ->subject('Booked')
        ->attachData('BEGIN:VCALENDAR', 'invite.ics', ['mime' => 'text/calendar']));

    expect($body['message']['attachments'])->toHaveCount(1)
        ->and($body['message']['attachments'][0])->toMatchArray([
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => 'invite.ics',
            'contentType' => 'text/calendar',
            'contentBytes' => base64_encode('BEGIN:VCALENDAR'),
            'isInline' => false,
        ]);
});

test('the app-only token is fetched once and reused across sends', function () {
    fakeGraph();
    sendThroughGraph(fn ($message) => $message->to('one@example.com')->subject('One'));
    sendThroughGraph(fn ($message) => $message->to('two@example.com')->subject('Two'));

    $tokenRequests = collect(Http::recorded())
        ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'login.microsoftonline.com'));

    expect($tokenRequests)->toHaveCount(1);
});

test('a failed send surfaces the graph error rather than passing silently', function () {
    fakeGraph(Http::response(['error' => ['code' => 'ErrorAccessDenied']], 403));

    expect(fn () => Mail::raw('body', fn ($message) => $message->to('invitee@example.com')->subject('Booked')))
        ->toThrow(RequestException::class);
});

test('the mailer refuses a mailbox left blank in the environment', function () {
    config(['mail.mailers.microsoft-graph.mailbox' => '']);

    expect(fn () => Mail::mailer('microsoft-graph'))
        ->toThrow(RuntimeException::class, 'needs a mailbox');
});

test('the mailer refuses the multi-tenant placeholder the calendar flow allows', function () {
    config(['mail.mailers.microsoft-graph.tenant' => 'common']);

    expect(fn () => Mail::mailer('microsoft-graph'))
        ->toThrow(RuntimeException::class, 'client credentials cannot use "common"');
});
