<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    Notification::fake();

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('email verification status is unchanged when the email address is unchanged', function () {
    Notification::fake();

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();

    Notification::assertNotSentTo($user, VerifyEmail::class);
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});

test('a photo can be uploaded and replaces the one before it', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'photo' => UploadedFile::fake()->image('me.jpg', 400, 400),
    ])->assertSessionHasNoErrors();

    $first = $user->fresh()->avatar_path;

    expect($first)->not->toBeNull();
    Storage::disk('public')->assertExists($first);

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'photo' => UploadedFile::fake()->image('newer.jpg', 400, 400),
    ])->assertSessionHasNoErrors();

    // The old file goes with it rather than piling up on the disk.
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($user->fresh()->avatar_path);
});

test('saving the profile without mentioning the photo keeps it', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'photo' => UploadedFile::fake()->image('me.jpg', 400, 400),
    ]);

    $path = $user->fresh()->avatar_path;

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'A New Name',
        'email' => $user->email,
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->name)->toBe('A New Name')
        ->and($user->fresh()->avatar_path)->toBe($path);
});

test('a photo can be removed', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'photo' => UploadedFile::fake()->image('me.jpg', 400, 400),
    ]);

    $path = $user->fresh()->avatar_path;

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'remove_photo' => true,
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->avatar_path)->toBeNull()
        ->and($user->fresh()->avatar)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

test('an svg is refused as a photo', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    // An SVG can carry script and photos are served from our own origin.
    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'photo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
    ])->assertSessionHasErrors('photo');

    expect($user->fresh()->avatar_path)->toBeNull();
});
