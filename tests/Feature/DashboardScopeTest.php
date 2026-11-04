<?php

use App\Enums\TeamRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->create(['name' => 'Ada Admin']);
    $this->member = User::factory()->create(['name' => 'Max Member']);
    $this->team = Team::factory()->create();

    $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin->value]);
    $this->team->members()->attach($this->member, ['role' => TeamRole::Member->value]);

    $this->admin->switchTeam($this->team);
    $this->member->switchTeam($this->team);

    Booking::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->member->id,
        'event_type_id' => EventType::factory()->ownedBy($this->member)->create(['team_id' => $this->team->id])->id,
        'name' => "Member's renter",
    ]);
});

/**
 * Load the dashboard's deferred meeting props for a user and scope.
 */
function dashboardScoped(User $user, Team $team, array $query = []): TestResponse
{
    return test()->actingAs($user)->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Data' => 'stats,upcoming',
        'X-Inertia-Partial-Component' => 'Dashboard',
        'X-Inertia-Version' => (new HandleInertiaRequests)->version(request()),
    ])->get(route('dashboard', ['current_team' => $team->slug, ...$query]));
}

test('an admin sees every member\'s meetings by default, with the host named', function () {
    $response = dashboardScoped($this->admin, $this->team)->assertOk();

    expect($response->json('props.stats.upcoming'))->toBe(1)
        ->and($response->json('props.upcoming.0.name'))->toBe("Member's renter")
        ->and($response->json('props.upcoming.0.hostName'))->toBe('Max Member');

    $this->flushHeaders()->actingAs($this->admin)
        ->get(route('dashboard', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope', 'all')
            ->where('showsOthers', true)
            ->has('scopeOptions.users', 2));
});

test('an admin can narrow the dashboard to their own meetings', function () {
    $response = dashboardScoped($this->admin, $this->team, ['scope' => 'mine'])->assertOk();

    expect($response->json('props.stats.upcoming'))->toBe(0)
        ->and($response->json('props.upcoming'))->toBe([]);
});

test('a member only sees their own meetings, even when asking for everyone', function () {
    Booking::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->admin->id,
        'event_type_id' => EventType::factory()->ownedBy($this->admin)->create(['team_id' => $this->team->id])->id,
        'name' => "Admin's renter",
    ]);

    $response = dashboardScoped($this->member, $this->team, ['scope' => 'all'])->assertOk();

    expect($response->json('props.stats.upcoming'))->toBe(1)
        ->and(collect($response->json('props.upcoming'))->pluck('name')->all())->toBe(["Member's renter"]);

    $this->flushHeaders()->actingAs($this->member)
        ->get(route('dashboard', ['current_team' => $this->team->slug, 'scope' => 'all']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope', 'mine')
            ->where('showsOthers', false)
            ->has('scopeOptions.primary', 1));
});
