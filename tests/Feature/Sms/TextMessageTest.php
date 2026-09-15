<?php

use App\Enums\AutomationRecipient;
use App\Enums\LocationType;
use App\Enums\QuestionType;
use App\Enums\TeamRole;
use App\Models\Automation;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Bookings\AutomationEmail;
use App\Services\Sms\TwilioClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Cache::flush();

    config()->set('services.twilio.sid', 'AC00000000000000000000000000000001');
    config()->set('services.twilio.token', 'test-token');

    $this->host = User::factory()->create([
        'name' => 'Dana Reed',
        'timezone' => 'UTC',
        'phone' => '+15125550111',
    ]);

    $this->team = $this->host->currentTeam;
    $this->team->update(['sms_from_number' => '+15125550000', 'timezone' => 'UTC']);

    $this->eventType = EventType::factory()->ownedBy($this->host)->create(['name' => 'Leasing Team Meeting']);

    $this->booking = Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'team_id' => $this->team->id,
        'user_id' => $this->host->id,
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
    ]);

    $this->booking->hosts()->attach($this->host);
});

/**
 * Queue a workflow against the booking so it is due right now.
 */
function queueRunFor(Automation $automation, Booking $booking): void
{
    $booking->automationRuns()->create([
        'automation_id' => $automation->id,
        'send_at' => now()->subMinute(),
    ]);
}

test('the account numbers are read from twilio', function () {
    Http::fake([
        '*/IncomingPhoneNumbers.json*' => Http::response([
            'incoming_phone_numbers' => [
                ['phone_number' => '+15125550000', 'friendly_name' => 'Leasing line'],
                ['phone_number' => '+15125550001', 'friendly_name' => 'Maintenance line'],
            ],
        ]),
    ]);

    expect(app(TwilioClient::class)->numbers())->toBe([
        ['number' => '+15125550000', 'label' => 'Leasing line'],
        ['number' => '+15125550001', 'label' => 'Maintenance line'],
    ]);
});

test('nothing is asked of twilio when there are no credentials', function () {
    config()->set('services.twilio.sid', '');
    config()->set('services.twilio.token', '');

    Http::fake();

    $client = app(TwilioClient::class);

    expect($client->isConfigured())->toBeFalse()
        ->and($client->numbers())->toBe([]);

    Http::assertNothingSent();
});

test('a text message is posted to twilio with the number dialled properly', function () {
    Http::fake(['*/Messages.json' => Http::response(['sid' => 'SM1'])]);

    app(TwilioClient::class)->send('+15125550000', '(512) 555-0100', 'Sam booked a meeting.');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/Messages.json')
        && $request['From'] === '+15125550000'
        && $request['To'] === '+15125550100'
        && $request['Body'] === 'Sam booked a meeting.');
});

test('a number twilio could never dial is refused before it is sent', function () {
    Http::fake();

    expect(fn () => app(TwilioClient::class)->send('+15125550000', '12345', 'Hello'))
        ->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});

test('twilio refusing the message is reported rather than swallowed', function () {
    Http::fake(['*/Messages.json' => Http::response(['message' => 'The From number is not valid'], 400)]);

    expect(fn () => app(TwilioClient::class)->send('+15125550000', '+15125550100', 'Hello'))
        ->toThrow(RuntimeException::class, 'The From number is not valid');
});

test('a due text workflow is sent from the organizations own number', function () {
    Http::fake(['*/Messages.json' => Http::response(['sid' => 'SM1'])]);

    $automation = Automation::factory()->byText('+15125550100')->create([
        'team_id' => $this->team->id,
        'sms_body' => '{{ invitee_name }} booked {{ event_name }}.',
    ]);

    queueRunFor($automation, $this->booking);

    $this->artisan('automations:run')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request['From'] === '+15125550000'
        && $request['To'] === '+15125550100'
        && $request['Body'] === 'Sam Rivera booked Leasing Team Meeting.');

    expect($this->booking->automationRuns()->first()->failure)->toBeNull();
});

test('a workflow set to do both sends the email and the text', function () {
    Notification::fake();
    Http::fake(['*/Messages.json' => Http::response(['sid' => 'SM1'])]);

    $automation = Automation::factory()->byEmailAndText()->create([
        'team_id' => $this->team->id,
        'recipient_emails' => ['leasing@texasrenters.com'],
        'recipient_phones' => ['+15125550100'],
    ]);

    queueRunFor($automation, $this->booking);

    $this->artisan('automations:run')->assertSuccessful();

    Notification::assertSentOnDemand(AutomationEmail::class);
    Http::assertSent(fn (Request $request) => $request['To'] === '+15125550100');

    expect($this->booking->automationRuns()->first()->failure)->toBeNull();
});

test('a text to the hosts goes to the numbers they have filled in', function () {
    Http::fake(['*/Messages.json' => Http::response(['sid' => 'SM1'])]);

    $withoutPhone = User::factory()->create(['phone' => null]);
    $this->booking->hosts()->attach($withoutPhone);

    $automation = Automation::factory()->byText()->create([
        'team_id' => $this->team->id,
        'recipient' => AutomationRecipient::Host,
        'recipient_phones' => null,
        'sms_body' => 'You have a meeting.',
    ]);

    queueRunFor($automation, $this->booking);

    $this->artisan('automations:run')->assertSuccessful();

    // The host with no number on file is skipped, not an error.
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request['To'] === '+15125550111');
});

test('a text to the invitee uses the number they gave when booking', function () {
    Http::fake(['*/Messages.json' => Http::response(['sid' => 'SM1'])]);

    $question = $this->eventType->questions()->create([
        'type' => QuestionType::Phone,
        'label' => 'Best number',
        'position' => 0,
    ]);

    $this->booking->answers()->create([
        'event_type_question_id' => $question->id,
        'label' => 'Best number',
        'answer' => '512-555-0199',
    ]);

    $automation = Automation::factory()->byText()->create([
        'team_id' => $this->team->id,
        'recipient' => AutomationRecipient::Invitee,
        'recipient_phones' => null,
        'sms_body' => 'See you soon.',
    ]);

    queueRunFor($automation, $this->booking);

    $this->artisan('automations:run')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request['To'] === '+15125550199');
});

test('a text fills in the booking answers and the notes', function () {
    Http::fake(['*/Messages.json' => Http::response(['sid' => 'SM1'])]);

    $this->booking->update(['notes' => 'Bringing my co-signer.']);

    $question = $this->eventType->questions()->create([
        'type' => QuestionType::Text,
        'label' => 'Property address',
        'position' => 0,
    ]);

    $this->booking->answers()->create([
        'event_type_question_id' => $question->id,
        'label' => 'Property address',
        'answer' => '12 Oak Lane',
    ]);

    $automation = Automation::factory()->byText()->create([
        'team_id' => $this->team->id,
        'sms_body' => "{{ invitee_name }} booked.\n{{ questions_and_answers }}\nNotes: {{ invitee_notes }}",
    ]);

    queueRunFor($automation, $this->booking);

    $this->artisan('automations:run')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request['Body'] === "Sam Rivera booked.\nProperty address: 12 Oak Lane\nNotes: Bringing my co-signer.");
});

test('an invitee who was never asked for a number is simply not texted', function () {
    Http::fake();

    $automation = Automation::factory()->byText()->create([
        'team_id' => $this->team->id,
        'recipient' => AutomationRecipient::Invitee,
        'recipient_phones' => null,
    ]);

    queueRunFor($automation, $this->booking);

    $this->artisan('automations:run')->assertSuccessful();

    Http::assertNothingSent();

    expect($this->booking->automationRuns()->first()->failure)->toBeNull();
});

test('an organization with no number chosen records why nothing was texted', function () {
    Http::fake();

    $this->team->update(['sms_from_number' => null]);

    $automation = Automation::factory()->byText()->create(['team_id' => $this->team->id]);

    queueRunFor($automation, $this->booking);

    $this->artisan('automations:run')->assertSuccessful();

    Http::assertNothingSent();

    expect($this->booking->automationRuns()->first()->failure)
        ->toContain('has not chosen a number');
});

test('an invitee phone question beats the call me location', function () {
    $this->booking->update([
        'location_type' => LocationType::Phone,
        'location_detail' => '512-555-0122',
    ]);

    expect($this->booking->fresh()->load('answers.question')->inviteePhone())->toBe('512-555-0122');
});

test('an admin can point the organization at one of the accounts numbers', function () {
    Http::fake([
        '*/IncomingPhoneNumbers.json*' => Http::response([
            'incoming_phone_numbers' => [
                ['phone_number' => '+15125550001', 'friendly_name' => 'Maintenance line'],
            ],
        ]),
    ]);

    $this->actingAs($this->host)
        ->patch(route('teams.update', ['team' => $this->team->slug]), [
            'name' => $this->team->name,
            'sms_from_number' => '+15125550001',
        ])
        ->assertRedirect();

    expect($this->team->fresh()->sms_from_number)->toBe('+15125550001');
});

test('a number the account does not own is refused', function () {
    Http::fake([
        '*/IncomingPhoneNumbers.json*' => Http::response(['incoming_phone_numbers' => []]),
    ]);

    $this->actingAs($this->host)
        ->patch(route('teams.update', ['team' => $this->team->slug]), [
            'name' => $this->team->name,
            'sms_from_number' => '+15125559999',
        ])
        ->assertSessionHasErrors('sms_from_number');
});

test('a plain member cannot choose the organizations number', function () {
    // A number the account really owns, so it is the policy that turns this
    // away rather than the validator.
    Http::fake([
        '*/IncomingPhoneNumbers.json*' => Http::response([
            'incoming_phone_numbers' => [
                ['phone_number' => '+15125550001', 'friendly_name' => 'Maintenance line'],
            ],
        ]),
    ]);

    $team = Team::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);

    $this->actingAs($member)
        ->patch(route('teams.update', ['team' => $team->slug]), [
            'name' => $team->name,
            'sms_from_number' => '+15125550001',
        ])
        ->assertForbidden();
});

test('a phone number on the profile is stored the way twilio dials it', function () {
    $this->actingAs($this->host)
        ->patch(route('profile.update'), [
            'name' => $this->host->name,
            'email' => $this->host->email,
            'phone' => '(512) 555-0133',
        ])
        ->assertRedirect();

    expect($this->host->fresh()->phone)->toBe('+15125550133');
});

test('a phone number nobody could dial is refused on the profile', function () {
    $this->actingAs($this->host)
        ->patch(route('profile.update'), [
            'name' => $this->host->name,
            'email' => $this->host->email,
            'phone' => '555',
        ])
        ->assertSessionHasErrors('phone');
});
