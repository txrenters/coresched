<?php

use App\Models\User;

test('guests cannot render a booking qr code', function () {
    $this->get(route('booking-qr-code', ['url' => url('book/acme')]))
        ->assertRedirect(route('login'));
});

test('a booking link is rendered as an svg qr code', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('booking-qr-code', ['url' => url('book/acme/intro-call')]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertHeaderMissing('Content-Disposition')
        ->assertSee('<svg', false);
});

test('a booking qr code can be downloaded under the link name', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('booking-qr-code', [
            'url' => url('book/acme'),
            'name' => 'Acme Leasing',
            'download' => 1,
        ]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="acme-leasing-qr-code.svg"');
});

test('only booking links on this app can be turned into a qr code', function (string $url) {
    $this->actingAs(User::factory()->create())
        ->getJson(route('booking-qr-code', ['url' => $url]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');
})->with([
    'another site' => 'https://example.org/book/acme',
    'a look-alike host' => fn () => url('book').'.evil.test/acme',
    'a non-booking page' => fn () => url('settings/profile'),
]);
