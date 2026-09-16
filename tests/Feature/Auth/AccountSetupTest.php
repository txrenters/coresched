<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Teams\AccountCreated;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * Create an account the way a super admin does, and return it with the token
 * from its welcome email.
 *
 * @return array{0: User, 1: string}
 */
function createdAccountWithToken(): array
{
    Notification::fake();

    $team = Team::factory()->create(['name' => 'TexasRenters']);

    test()->actingAs(User::factory()->create(['is_super_admin' => true]))
        ->post(route('teams.members.store', $team), [
            'name' => 'New Hire',
            'email' => 'new.hire@example.com',
            'role' => TeamRole::Member->value,
        ]);

    auth()->logout();

    $user = User::where('email', 'new.hire@example.com')->sole();
    $token = null;

    Notification::assertSentTo($user, AccountCreated::class, function (AccountCreated $notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    return [$user, $token];
}

test('a created account gets a welcome email, not a password reset', function () {
    [$user] = createdAccountWithToken();

    Notification::assertNotSentTo($user, ResetPassword::class);
    Notification::assertSentTo($user, AccountCreated::class, function (AccountCreated $notification) use ($user) {
        $mail = $notification->toMail($user);

        expect($mail->subject)->toBe('Welcome to '.config('app.name'))
            ->and($mail->actionText)->toBe('Set your password')
            ->and($mail->actionUrl)->toContain('/account/setup/')
            ->and(implode(' ', $mail->introLines))->toContain('TexasRenters organization')
            ->and(implode(' ', $mail->outroLines))->toContain('expires in 7 days');

        return true;
    });
});

test('the welcome link lets the new person set their password', function () {
    [$user, $token] = createdAccountWithToken();

    $this->get(route('account-setup.show', ['token' => $token, 'email' => $user->email]))
        ->assertInertia(fn ($page) => $page->component('auth/SetPassword')->where('email', $user->email));

    $this->post(route('account-setup.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'a-good-new-password',
        'password_confirmation' => 'a-good-new-password',
    ])->assertRedirect(route('login'));

    expect(Hash::check('a-good-new-password', $user->refresh()->password))->toBeTrue();
});

test('the welcome link still works after several days, unlike a reset link', function () {
    [$user, $token] = createdAccountWithToken();

    $this->travel(6)->days();

    $this->post(route('account-setup.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'a-good-new-password',
        'password_confirmation' => 'a-good-new-password',
    ])->assertSessionHasNoErrors();

    expect(Hash::check('a-good-new-password', $user->refresh()->password))->toBeTrue();
});

test('the welcome link stops working after a week', function () {
    [$user, $token] = createdAccountWithToken();

    $this->travel(8)->days();

    $this->post(route('account-setup.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'a-good-new-password',
        'password_confirmation' => 'a-good-new-password',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('a-good-new-password', $user->refresh()->password))->toBeFalse();
});

test('the welcome link works only once', function () {
    [$user, $token] = createdAccountWithToken();

    $details = [
        'token' => $token,
        'email' => $user->email,
        'password' => 'a-good-new-password',
        'password_confirmation' => 'a-good-new-password',
    ];

    $this->post(route('account-setup.store'), $details)->assertSessionHasNoErrors();

    $this->post(route('account-setup.store'), [...$details, 'password' => 'someone-elses-pass', 'password_confirmation' => 'someone-elses-pass'])
        ->assertSessionHasErrors('email');

    expect(Hash::check('a-good-new-password', $user->refresh()->password))->toBeTrue();
});
