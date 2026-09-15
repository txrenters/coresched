<?php

use App\Models\Booking;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Bookings\BookingConfirmed;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Collection;

beforeEach(function () {
    config([
        'mail.default' => 'array',
        'mail.from' => ['address' => 'bookings@texasrenters.com', 'name' => 'TR Bookings'],
        'mail.account_from' => ['address' => 'no-reply@texasrenters.com', 'name' => 'TR Bookings'],
    ]);
});

/**
 * Get the From address of every message the array mailer has sent.
 *
 * @return Collection<int, string>
 */
function sentFromAddresses(): Collection
{
    return collect(app('mailer')->getSymfonyTransport()->messages())
        ->map(fn ($sent) => $sent->getOriginalMessage()->getFrom()[0]->getAddress());
}

test('a password reset comes from the no-reply address', function () {
    User::factory()->create()->notifyNow(new ResetPassword('token'));

    expect(sentFromAddresses()->all())->toBe(['no-reply@texasrenters.com']);
});

test('an email verification comes from the no-reply address', function () {
    User::factory()->create()->notifyNow(new VerifyEmail);

    expect(sentFromAddresses()->all())->toBe(['no-reply@texasrenters.com']);
});

test('an organization invitation comes from the no-reply address', function () {
    (new AnonymousNotifiable)
        ->route('mail', 'invitee@example.com')
        ->notifyNow(new TeamInvitationNotification(TeamInvitation::factory()->create()));

    expect(sentFromAddresses()->all())->toBe(['no-reply@texasrenters.com']);
});

test('booking mail keeps the bookings address', function () {
    (new AnonymousNotifiable)
        ->route('mail', 'invitee@example.com')
        ->notifyNow(new BookingConfirmed(Booking::factory()->create()));

    expect(sentFromAddresses()->all())->toBe(['bookings@texasrenters.com']);
});
