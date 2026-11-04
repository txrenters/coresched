<?php

use App\Actions\Bookings\CreateBooking;
use App\Enums\TeamRole;
use App\Exceptions\SlotUnavailableException;
use App\Http\Middleware\EnsureActiveOrganization;
use App\Models\ApiKey;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Bookings\BookingReminder as BookingReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->operator = User::factory()->create(['is_super_admin' => true]);
    $this->member = User::factory()->create();
    $this->team = $this->member->currentTeam;
    $this->team->update(['name' => 'Suspended Co']);
});

function disable(Team $team): void
{
    $team->forceFill(['disabled_at' => now(), 'disabled_reason' => 'Unpaid invoice'])->save();
}

test('a super admin disables an organization with a reason, and it is logged', function () {
    $this->actingAs($this->operator)
        ->patch(route('admin.organizations.status', ['team' => $this->team->slug]), [
            'disabled' => true,
            'reason' => 'Unpaid invoice',
        ])
        ->assertSessionHasNoErrors();

    expect($this->team->fresh())
        ->isDisabled()->toBeTrue()
        ->disabled_reason->toBe('Unpaid invoice');

    $this->assertDatabaseHas('activity_logs', ['team_id' => $this->team->id, 'event' => 'organization.disabled']);
});

test('enabling an organization clears its disabled state', function () {
    disable($this->team);

    $this->actingAs($this->operator)
        ->patch(route('admin.organizations.status', ['team' => $this->team->slug]), ['disabled' => false])
        ->assertSessionHasNoErrors();

    expect($this->team->fresh())
        ->isDisabled()->toBeFalse()
        ->disabled_reason->toBeNull();
});

test('an organization admin cannot disable or enable an organization', function () {
    $this->actingAs($this->member)
        ->patch(route('admin.organizations.status', ['team' => $this->team->slug]), ['disabled' => true])
        ->assertForbidden();

    expect($this->team->fresh()->isDisabled())->toBeFalse();
});

test('someone whose only organization is disabled cannot sign in', function () {
    disable($this->team);

    $this->post(route('login.store'), ['email' => $this->member->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => EnsureActiveOrganization::MESSAGE]);

    $this->assertGuest();
});

test('someone already signed in is signed out once their only organization is disabled', function () {
    $this->actingAs($this->member);
    disable($this->team);

    $this->get(route('dashboard', ['current_team' => $this->team->slug]))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('someone with another active organization keeps that one and loses the disabled one', function () {
    $other = Team::factory()->create(['name' => 'Still Active']);
    $other->members()->attach($this->member, ['role' => TeamRole::Member->value]);
    disable($this->team);

    $this->post(route('login.store'), ['email' => $this->member->email, 'password' => 'password'])
        ->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($this->member);

    // Their current organization was the disabled one: they are moved on.
    $this->get(route('dashboard', ['current_team' => $this->team->slug]))
        ->assertRedirect(route('dashboard', ['current_team' => $other->slug]));

    expect($this->member->fresh()->current_team_id)->toBe($other->id)
        ->and($this->member->fresh()->toUserTeams(includeCurrent: true)->pluck('slug')->all())->toBe([$other->slug]);

    // And it stays out of reach even when asked for directly.
    $this->get(route('dashboard', ['current_team' => $this->team->slug]))->assertForbidden();
});

test('a disabled organizations booking page says it is not taking bookings', function () {
    disable($this->team);

    $this->get(route('book.page', ['page' => $this->team->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('book/Unavailable')
            ->where('pageName', 'Suspended Co'));
});

test('no booking can be made for a disabled organization, however it arrives', function () {
    $eventType = EventType::factory()->ownedBy($this->member)->create();
    disable($this->team);

    expect(fn () => app(CreateBooking::class)->handle(
        $eventType->fresh(),
        CarbonImmutable::now()->addDay()->setTime(10, 0),
        ['name' => 'Sam', 'email' => 'sam@example.com'],
    ))->toThrow(SlotUnavailableException::class, 'not taking bookings');
});

test('a disabled organizations api keys are refused', function () {
    ['token' => $token] = ApiKey::issue('CRM', null, $this->team);
    disable($this->team);

    $this->withToken($token)->getJson(route('api.v1.me'))->assertForbidden();
});

test('reminders for a disabled organization are dropped, not sent', function () {
    Notification::fake();
    $eventType = EventType::factory()->ownedBy($this->member)->create();
    $booking = Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'team_id' => $this->team->id,
        'user_id' => $this->member->id,
        'starts_at' => now()->addHour(),
        'ends_at' => now()->addHours(2),
    ]);
    $booking->hosts()->attach($this->member);
    $reminder = $booking->reminders()->create(['minutes_before' => 60, 'send_at' => now()->subMinute()]);
    disable($this->team);

    $this->artisan('bookings:send-reminders')->assertSuccessful();

    Notification::assertNotSentTo($this->member, BookingReminderNotification::class);
    expect($reminder->fresh())->toBeNull();
});

test('a super admin cannot sign in as a member of a disabled organization', function () {
    disable($this->team);

    $this->actingAs($this->operator)
        ->post(route('admin.impersonation.store', ['team' => $this->team->slug, 'user' => $this->member->id]))
        ->assertForbidden();

    $this->assertAuthenticatedAs($this->operator);
});

test('the disabled reason is shown to super admins only', function () {
    disable($this->team);

    $this->actingAs($this->operator)
        ->get(route('organizations.index', ['search' => 'Suspended']))
        ->assertInertia(fn ($page) => $page->where('organizations.0.admin.disabledReason', 'Unpaid invoice'));

    expect($this->team->fresh()->toArray())->not->toHaveKey('disabled_reason');
});
