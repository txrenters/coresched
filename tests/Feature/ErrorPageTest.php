<?php

use App\Models\User;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;

test('a missing page renders the themed not found page', function () {
    $this->get('/this-page-does-not-exist')
        ->assertNotFound()
        ->assertInertia(fn ($page) => $page
            ->component('Error')
            ->where('status', 404));
});

test('a forbidden page renders the themed page with the signed in user shared', function () {
    $user = User::factory()->create();
    $user->teams()->detach();

    $this->actingAs($user)
        ->get(route('users.index'))
        ->assertForbidden()
        ->assertInertia(fn ($page) => $page
            ->component('Error')
            ->where('status', 403)
            ->where('auth.user.id', $user->id));
});

test('a server error renders the themed page without the database backed shared props', function () {
    config(['app.debug' => false]);

    Route::middleware('web')->get('/error-page-test/crash', fn () => throw new RuntimeException('Boom'));

    $this->get('/error-page-test/crash')
        ->assertInternalServerError()
        ->assertInertia(fn ($page) => $page
            ->component('Error')
            ->where('status', 500)
            ->missing('auth'));
});

test('a server error with debug on is left to the framework debug screen', function () {
    config(['app.debug' => true]);

    Route::middleware('web')->get('/error-page-test/crash', fn () => throw new RuntimeException('Boom'));

    $response = $this->get('/error-page-test/crash')->assertInternalServerError();

    expect($response->headers->get('X-Inertia'))->toBeNull()
        ->and($response->getContent())->not->toContain('"component":"Error"');
});

test('an expired session sends the visitor back to the form with a toast', function () {
    Route::middleware('web')->post('/error-page-test/expired', fn () => throw new TokenMismatchException);

    $this->from('/dashboard')
        ->post('/error-page-test/expired')
        ->assertStatus(303)
        ->assertRedirect('/dashboard')
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Your session expired. Please try again.']);
});

test('a json request still gets a json error', function () {
    $this->getJson('/this-page-does-not-exist')
        ->assertNotFound()
        ->assertJsonStructure(['message']);
});

test('a booking that can no longer be changed renders the themed gone page', function () {
    Route::middleware('web')->get('/error-page-test/gone', fn () => abort(410));

    $this->get('/error-page-test/gone')
        ->assertGone()
        ->assertInertia(fn ($page) => $page
            ->component('Error')
            ->where('status', 410));
});
