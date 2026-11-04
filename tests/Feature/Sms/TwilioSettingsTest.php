<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const VALID_SID = 'AC00000000000000000000000000000001';
const OTHER_SID = 'AC00000000000000000000000000000002';

beforeEach(function () {
    Cache::flush();

    $this->admin = User::factory()->create();
    $this->team = $this->admin->currentTeam;
});

/**
 * Twilio answers the account lookup for the valid SID and token only, and
 * owns one number.
 */
function fakeTwilioAccount(string $token = 'secret-token-1234'): void
{
    Http::fake(function (Request $request) use ($token) {
        $authorized = $request->hasHeader('Authorization', 'Basic '.base64_encode(VALID_SID.':'.$token));

        if (! $authorized) {
            return Http::response(['message' => 'Authenticate'], 401);
        }

        if (str_contains($request->url(), '/IncomingPhoneNumbers.json')) {
            return Http::response(['incoming_phone_numbers' => [
                ['phone_number' => '+15125550001', 'friendly_name' => 'Front desk'],
            ]]);
        }

        return Http::response(['sid' => VALID_SID]);
    });
}

function saveTwilio(Team $team, array $payload): array
{
    return ['name' => $team->name, ...$payload];
}

test('an admin connects the organizations own twilio account, and the token is stored encrypted', function () {
    fakeTwilioAccount();

    $this->actingAs($this->admin)
        ->patch(route('teams.update', ['team' => $this->team->slug]), saveTwilio($this->team, [
            'twilio_account_sid' => VALID_SID,
            'twilio_auth_token' => 'secret-token-1234',
        ]))
        ->assertSessionHasNoErrors();

    $team = $this->team->fresh();

    expect($team->twilio_account_sid)->toBe(VALID_SID)
        ->and($team->twilio_auth_token)->toBe('secret-token-1234')
        ->and($team->hasTwilio())->toBeTrue()
        ->and(DB::table('teams')->where('id', $team->id)->value('twilio_auth_token'))
        ->not->toContain('secret-token-1234');
});

test('credentials twilio does not accept are refused and nothing is saved', function () {
    fakeTwilioAccount();

    $this->actingAs($this->admin)
        ->patch(route('teams.update', ['team' => $this->team->slug]), saveTwilio($this->team, [
            'twilio_account_sid' => VALID_SID,
            'twilio_auth_token' => 'wrong-token',
        ]))
        ->assertSessionHasErrors('twilio_auth_token');

    expect($this->team->fresh()->hasTwilio())->toBeFalse();
});

test('an account sid that is not shaped like one is refused', function () {
    Http::fake();

    $this->actingAs($this->admin)
        ->patch(route('teams.update', ['team' => $this->team->slug]), saveTwilio($this->team, [
            'twilio_account_sid' => 'not-a-sid',
            'twilio_auth_token' => 'secret-token-1234',
        ]))
        ->assertSessionHasErrors('twilio_account_sid');

    Http::assertNothingSent();
});

test('a blank token keeps the saved one without asking twilio again', function () {
    $this->team->update(['twilio_account_sid' => VALID_SID, 'twilio_auth_token' => 'secret-token-1234']);
    fakeTwilioAccount();

    $this->actingAs($this->admin)
        ->patch(route('teams.update', ['team' => $this->team->slug]), saveTwilio($this->team, [
            'welcome_message' => 'Hello',
            'twilio_account_sid' => VALID_SID,
            'twilio_auth_token' => '',
        ]))
        ->assertSessionHasNoErrors();

    expect($this->team->fresh()->twilio_auth_token)->toBe('secret-token-1234');
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), VALID_SID.'.json'));
});

test('a different account sid cannot borrow the saved token', function () {
    $this->team->update(['twilio_account_sid' => VALID_SID, 'twilio_auth_token' => 'secret-token-1234']);
    fakeTwilioAccount();

    $this->actingAs($this->admin)
        ->patch(route('teams.update', ['team' => $this->team->slug]), saveTwilio($this->team, [
            'twilio_account_sid' => OTHER_SID,
            'twilio_auth_token' => '',
        ]))
        ->assertSessionHasErrors('twilio_auth_token');

    expect($this->team->fresh()->twilio_account_sid)->toBe(VALID_SID);
});

test('disconnecting twilio clears the account and the number that belonged to it', function () {
    $this->team->update([
        'twilio_account_sid' => VALID_SID,
        'twilio_auth_token' => 'secret-token-1234',
        'sms_from_number' => '+15125550001',
    ]);
    fakeTwilioAccount();

    $this->actingAs($this->admin)
        ->patch(route('teams.update', ['team' => $this->team->slug]), saveTwilio($this->team, [
            'remove_twilio' => true,
        ]))
        ->assertSessionHasNoErrors();

    $team = $this->team->fresh();

    expect($team->twilio_account_sid)->toBeNull()
        ->and($team->twilio_auth_token)->toBeNull()
        ->and($team->sms_from_number)->toBeNull();
});

test('the settings page never sends the auth token, only its last four characters', function () {
    $this->team->update(['twilio_account_sid' => VALID_SID, 'twilio_auth_token' => 'secret-token-1234']);
    fakeTwilioAccount();

    $response = $this->actingAs($this->admin)->get(route('teams.edit', ['team' => $this->team->slug]));

    $response->assertInertia(fn ($page) => $page
        ->where('sms.configured', true)
        ->where('sms.accountSid', VALID_SID)
        ->where('sms.tokenHint', '1234')
        ->where('sms.numbers.0.number', '+15125550001'));

    expect($response->getContent())->not->toContain('secret-token-1234');
});

test('a plain member sees no account details and cannot change them', function () {
    $this->team->update(['twilio_account_sid' => VALID_SID, 'twilio_auth_token' => 'secret-token-1234']);
    fakeTwilioAccount();

    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($this->team);

    $this->actingAs($member)
        ->get(route('teams.edit', ['team' => $this->team->slug]))
        ->assertInertia(fn ($page) => $page
            ->where('sms.configured', true)
            ->where('sms.accountSid', null)
            ->where('sms.tokenHint', null)
            ->where('sms.numbers', []));

    $this->actingAs($member)
        ->patch(route('teams.update', ['team' => $this->team->slug]), saveTwilio($this->team, [
            'remove_twilio' => true,
        ]))
        ->assertForbidden();

    expect($this->team->fresh()->hasTwilio())->toBeTrue();
});

test('twilio credentials and the super admins plan details are never serialized with the organization', function () {
    $this->team->update([
        'twilio_account_sid' => VALID_SID,
        'twilio_auth_token' => 'secret-token-1234',
        'plan' => 'enterprise',
        'plan_note' => 'Internal only',
        'mail_from_address' => 'scheduling@texasrenters.com',
    ]);

    expect($this->team->fresh()->toArray())->not->toHaveKeys([
        'twilio_account_sid', 'twilio_auth_token', 'plan', 'plan_note', 'mail_from_address',
    ]);

    // The signed-in user is shared with every page and carries their current
    // organization along, so this is the path that would leak it.
    $response = $this->actingAs($this->admin)->get(route('teams.edit', ['team' => $this->team->slug]));

    expect($response->getContent())
        ->not->toContain('secret-token-1234')
        ->not->toContain('Internal only');
});
