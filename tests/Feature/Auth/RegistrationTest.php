<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the registration screen is not reachable without an invitation', function () {
    $response = $this->get(route('register'));

    $response->assertRedirect(route('login'));
});

test('registration screen includes team invitation context', function () {
    $invitation = invitationFor('invited@example.com');

    $response = $this->get(route('register', ['invitation' => $invitation->code]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('auth/Register')
        ->where('teamInvitation.code', $invitation->code)
        ->where('teamInvitation.teamName', 'Laravel Team'),
    );
});

test('an expired invitation does not reopen registration', function () {
    $invitation = invitationFor('invited@example.com');
    $invitation->update(['expires_at' => now()->subDay()]);

    $this->get(route('register', ['invitation' => $invitation->code]))
        ->assertRedirect(route('login'));
});

test('invited users can register', function () {
    $invitation = invitationFor('test@example.com');

    $response = $this->post(route('register.store'), [
        'invitation' => $invitation->code,
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();

    expect(User::where('email', 'test@example.com')->value('email_verified_at'))->not->toBeNull();
    $response->assertRedirect(route('dashboard'));
});

test('uninvited users cannot register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Walk In',
        'email' => 'stranger@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();

    expect(User::where('email', 'stranger@example.com')->exists())->toBeFalse();
});

test('an accepted invitation cannot be reused to register', function () {
    $invitation = invitationFor('used@example.com');
    $invitation->update(['accepted_at' => now()]);

    $this->post(route('register.store'), [
        'invitation' => $invitation->code,
        'name' => 'Second Try',
        'email' => 'used@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('registering an email that already has an account points at the password reset', function () {
    User::factory()->create(['email' => 'shell@example.com']);
    invitationFor('shell@example.com');

    $this->post(route('register.store'), [
        'name' => 'Shell Account',
        'email' => 'shell@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors([
        'email' => 'This address already has an account. Use "Forgot password?" on the log-in page to set a password.',
    ]);

    $this->assertGuest();

    expect(User::where('email', 'shell@example.com')->count())->toBe(1);
});

test('the invitation check ignores address casing', function () {
    $invitation = invitationFor('Mixed.Case@Example.com');

    $this->post(route('register.store'), [
        'invitation' => $invitation->code,
        'name' => 'Mixed Case',
        'email' => 'mixed.case@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
});

test('knowing an invited address is not enough to register without its invitation code', function () {
    invitationFor('new-admin@example.com');

    $this->post(route('register.store'), [
        'name' => 'Not Them',
        'email' => 'new-admin@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors([
        'email' => 'Registration is by invitation only. Ask an administrator to invite you.',
    ]);

    $this->assertGuest();
});

test('another invitation\'s code does not unlock registration for a different address', function () {
    invitationFor('new-admin@example.com');
    $otherInvitation = invitationFor('someone-else@example.com');

    $this->post(route('register.store'), [
        'invitation' => $otherInvitation->code,
        'name' => 'Not Them',
        'email' => 'new-admin@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});
