<?php

use App\Actions\Teams\ResetDemoOrganization;
use App\Enums\TeamPlan;
use App\Enums\TeamRole;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Team;
use App\Models\User;
use App\Services\Admin\PlatformAnalytics;
use Carbon\CarbonImmutable;

function consoleOperator(): User
{
    return User::factory()->create(['is_super_admin' => true]);
}

test('only super admins can open the admin console', function (string $role) {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => $role]);

    $this->actingAs($user)->get(route('admin.organizations.index'))->assertForbidden();
})->with([TeamRole::Admin->value, TeamRole::Member->value]);

test('the organizations page shows a super admin each organizations plan and setup', function () {
    $team = Team::factory()->create([
        'name' => 'Acme Services',
        'plan' => TeamPlan::Business,
        'plan_note' => 'Annual contract',
        'twilio_account_sid' => 'AC00000000000000000000000000000001',
        'twilio_auth_token' => 'token',
    ]);
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Admin->value]);

    $this->actingAs(consoleOperator())
        ->get(route('organizations.index', ['search' => 'Acme']))
        ->assertInertia(fn ($page) => $page
            ->component('organizations/Index')
            ->has('organizations', 1)
            ->where('organizations.0.admin.plan', 'business')
            ->where('organizations.0.admin.planNote', 'Annual contract')
            ->where('organizations.0.admin.setup.twilio', true)
            ->where('organizations.0.admin.setup.smsNumber', false)
            ->where('organizations.0.admin.setup.eventTypes', false)
            ->where('organizations.0.admin.openAs.0.id', $member->id)
            ->has('consoleOptions.plans'));
});

test('an organization that has taken bookings shows when it last did', function () {
    $host = User::factory()->create();
    $eventType = EventType::factory()->ownedBy($host)->create();
    Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'team_id' => $host->currentTeam->id,
        'user_id' => $host->id,
        'created_at' => now()->subDays(2),
    ]);

    $this->actingAs(consoleOperator())
        ->get(route('organizations.index', ['search' => $host->currentTeam->name]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('organizations.0.admin.lastBookedAt', '2 days ago')
            ->where('organizations.0.admin.setup.booked', true)
            ->where('organizations.0.admin.setup.eventTypes', true));
});

test('a super admin filters the organizations to those with no plan', function () {
    Team::factory()->create(['name' => 'Planned', 'plan' => TeamPlan::Team]);
    Team::factory()->create(['name' => 'Unplanned', 'plan' => null]);

    $this->actingAs(consoleOperator())
        ->get(route('organizations.index', ['plan' => 'none', 'search' => 'lanned']))
        ->assertInertia(fn ($page) => $page
            ->has('organizations', 1)
            ->where('organizations.0.name', 'Unplanned'));
});

test('a super admin records an organizations plan and it is logged', function () {
    $team = Team::factory()->create();

    $this->actingAs(consoleOperator())
        ->patch(route('admin.organizations.plan', ['team' => $team->slug]), [
            'plan' => 'enterprise',
            'plan_note' => 'Pilot until June',
        ])
        ->assertSessionHasNoErrors();

    expect($team->fresh())
        ->plan->toBe(TeamPlan::Enterprise)
        ->plan_note->toBe('Pilot until June');

    $this->assertDatabaseHas('activity_logs', ['team_id' => $team->id, 'event' => 'organization.plan_updated']);
});

test('a plan that does not exist is refused', function () {
    $team = Team::factory()->create();

    $this->actingAs(consoleOperator())
        ->patch(route('admin.organizations.plan', ['team' => $team->slug]), ['plan' => 'platinum'])
        ->assertSessionHasErrors('plan');
});

test('an organization admin cannot set their own plan', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->patch(route('admin.organizations.plan', ['team' => $admin->currentTeam->slug]), ['plan' => 'enterprise'])
        ->assertForbidden();

    expect($admin->currentTeam->fresh()->plan)->toBeNull();
});

test('a super admin lands on the admin console even with an organization selected', function () {
    $operator = consoleOperator();

    expect($operator->currentTeam)->not->toBeNull();

    $this->post(route('login.store'), [
        'email' => $operator->email,
        'password' => 'password',
    ])->assertRedirect(route('admin.organizations.index'));
});

test('a super admin with no organization lands on the admin console after signing in', function () {
    $operator = consoleOperator();
    $operator->teams()->detach();
    $operator->forceFill(['current_team_id' => null])->save();

    $this->post(route('login.store'), [
        'email' => $operator->email,
        'password' => 'password',
    ])->assertRedirect(route('admin.organizations.index'));

    $this->assertAuthenticatedAs($operator);
});

test('anyone else with no organization is still refused', function () {
    $user = User::factory()->create();
    $user->teams()->detach();
    $user->forceFill(['current_team_id' => null])->save();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertForbidden();
});

test('the dashboard reports bookings per week and the plan mix across every organization', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 12:00', 'UTC'));

    Team::factory()->create(['plan' => TeamPlan::Business]);
    $host = User::factory()->create();
    $eventType = EventType::factory()->ownedBy($host)->create();

    foreach (['2026-10-27 09:00', '2026-10-28 09:00', '2026-10-14 09:00', '2026-09-01 09:00'] as $createdAt) {
        Booking::factory()->create([
            'event_type_id' => $eventType->id,
            'team_id' => $host->currentTeam->id,
            'user_id' => $host->id,
            'created_at' => CarbonImmutable::parse($createdAt, 'UTC'),
        ]);
    }

    $this->actingAs(consoleOperator())
        ->get(route('admin.organizations.index'))
        ->assertInertia(fn ($page) => $page
            ->component('admin/Dashboard')
            ->has('analytics.bookingsByWeek', 12)
            ->where('analytics.bookingsByWeek.11', ['label' => 'Oct 26', 'value' => 2])
            ->where('analytics.bookingsByWeek.9', ['label' => 'Oct 12', 'value' => 1])
            ->where('analytics.stats.bookingsLast30Days', 3)
            ->where('analytics.stats.bookingsPrevious30Days', 1)
            ->where('analytics.planMix.2', ['label' => 'Business', 'value' => 1]));
});

test('an organization admin sees their organization without any of the super admin details', function () {
    $admin = User::factory()->create();
    $admin->currentTeam->update([
        'plan' => TeamPlan::Enterprise,
        'plan_note' => 'Discounted, do not mention',
    ]);

    $response = $this->actingAs($admin)->get(route('organizations.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('organizations.0.admin', null)
        ->where('consoleOptions', null));

    expect($response->getContent())->not->toContain('Discounted, do not mention');
});

test('an organization admin cannot filter by plan to learn other organizations plans', function () {
    $admin = User::factory()->create();
    Team::factory()->create(['name' => 'Someone Else', 'plan' => TeamPlan::Business]);

    $this->actingAs($admin)
        ->get(route('organizations.index', ['plan' => 'business']))
        ->assertInertia(fn ($page) => $page
            ->has('organizations', 1)
            ->where('organizations.0.id', $admin->currentTeam->id));
});

test('the dashboard leaves the public demo out of every figure', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 12:00', 'UTC'));

    $host = User::factory()->create();
    Booking::factory()->create([
        'event_type_id' => EventType::factory()->ownedBy($host)->create()->id,
        'created_at' => CarbonImmutable::parse('2026-10-27 09:00', 'UTC'),
    ]);

    $withoutDemo = app(PlatformAnalytics::class)->summary();

    // Five sample people, an Enterprise plan, event types and twenty bookings.
    app(ResetDemoOrganization::class)->handle();

    expect(app(PlatformAnalytics::class)->summary())->toBe($withoutDemo);
});
