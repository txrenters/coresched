<?php

use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use App\Notifications\Bookings\BookingMailPresenter;
use Illuminate\Notifications\Messages\MailMessage;

beforeEach(function () {
    config([
        'mail.from.address' => 'notifications@coresched.com',
        'mail.org_senders' => ['scheduling@texasrenters.com'],
    ]);

    $this->host = User::factory()->create();
    $this->team = $this->host->currentTeam;
    $this->team->update(['name' => 'TexasRenters']);
    $eventType = EventType::factory()->ownedBy($this->host)->create();

    $this->booking = Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'team_id' => $this->team->id,
        'user_id' => $this->host->id,
    ]);
});

/**
 * The From a booking email for this booking would carry.
 *
 * @return array{0: string, 1: string}
 */
function bookingMailSender(Booking $booking): array
{
    $message = (new BookingMailPresenter($booking->fresh(), User::find($booking->user_id)))->withSender(new MailMessage);

    return $message->from;
}

test('booking mail comes from the platform address by default, under the organizations name', function () {
    expect(bookingMailSender($this->booking))->toBe(['notifications@coresched.com', 'TexasRenters']);
});

test('an organization given its own sender sends its booking mail from it', function () {
    $this->team->update(['mail_from_address' => 'scheduling@texasrenters.com']);

    expect(bookingMailSender($this->booking))->toBe(['scheduling@texasrenters.com', 'TexasRenters']);
});

test('a sender taken off the approved list falls back to the platform address', function () {
    $this->team->update(['mail_from_address' => 'scheduling@texasrenters.com']);
    config(['mail.org_senders' => []]);

    expect(bookingMailSender($this->booking))->toBe(['notifications@coresched.com', 'TexasRenters']);
});

test('a super admin gives an organization one of the approved senders', function () {
    $this->actingAs(User::factory()->create(['is_super_admin' => true]))
        ->patch(route('admin.organizations.plan', ['team' => $this->team->slug]), [
            'plan' => 'enterprise',
            'mail_from_address' => 'scheduling@texasrenters.com',
        ])
        ->assertSessionHasNoErrors();

    expect($this->team->fresh()->mail_from_address)->toBe('scheduling@texasrenters.com');
});

test('an address that is not on the approved list is refused', function () {
    $this->actingAs(User::factory()->create(['is_super_admin' => true]))
        ->patch(route('admin.organizations.plan', ['team' => $this->team->slug]), [
            'mail_from_address' => 'ceo@somewhere-else.com',
        ])
        ->assertSessionHasErrors('mail_from_address');

    expect($this->team->fresh()->mail_from_address)->toBeNull();
});

test('saving the plan without the sender leaves the sender alone', function () {
    $this->team->update(['mail_from_address' => 'scheduling@texasrenters.com']);

    $this->actingAs(User::factory()->create(['is_super_admin' => true]))
        ->patch(route('admin.organizations.plan', ['team' => $this->team->slug]), ['plan' => 'team'])
        ->assertSessionHasNoErrors();

    expect($this->team->fresh()->mail_from_address)->toBe('scheduling@texasrenters.com');
});

test('an organization admin cannot choose their own sender', function () {
    $this->actingAs($this->host)
        ->patch(route('admin.organizations.plan', ['team' => $this->team->slug]), [
            'mail_from_address' => 'scheduling@texasrenters.com',
        ])
        ->assertForbidden();

    expect($this->team->fresh()->mail_from_address)->toBeNull();
});

test('every approved sender is also one the mailer may send through as itself', function () {
    putenv('MAIL_ORG_SENDERS=scheduling@texasrenters.com, other@example.com');
    putenv('MAIL_ACCOUNT_FROM_ADDRESS=noreply@coresched.com');

    try {
        $config = require config_path('mail.php');
    } finally {
        putenv('MAIL_ORG_SENDERS');
        putenv('MAIL_ACCOUNT_FROM_ADDRESS');
    }

    expect($config['org_senders'])->toBe(['scheduling@texasrenters.com', 'other@example.com'])
        ->and($config['mailers']['microsoft-graph']['send_as'])
        ->toBe(['noreply@coresched.com', 'scheduling@texasrenters.com', 'other@example.com']);
});
