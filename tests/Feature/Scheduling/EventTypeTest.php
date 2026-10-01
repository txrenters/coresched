<?php

use App\Enums\EventTypeKind;
use App\Enums\TeamRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ActivityLog;
use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Group;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = $this->user->currentTeam;
});

function eventTypePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Discovery call',
        'slug' => 'discovery-call',
        'description' => 'A first chat.',
        'kind' => EventTypeKind::OneOnOne->value,
        'color' => '#0f766e',
        'duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'seats_per_slot' => 1,
        'date_range_type' => 'rolling_days',
        'rolling_days' => 60,
        'location_type' => 'custom_link',
        'location_detail' => 'https://example.com/room',
        'is_active' => true,
        'is_hidden' => false,
    ], $overrides);
}

test('the index lists the teams event types', function () {
    EventType::factory()->ownedBy($this->user)->create(['name' => 'Discovery call']);

    $this->actingAs($this->user)
        ->get(route('scheduling.index', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('scheduling/event-types/Index')
            ->has('eventTypes', 1)
            ->where('eventTypes.0.name', 'Discovery call'));
});

test('the index carries everything the quick create panel needs', function () {
    AvailabilitySchedule::factory()->for($this->user)->weekdays()->create(['name' => 'Working hours']);

    $this->actingAs($this->user)
        ->get(route('scheduling.index', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('kinds', 4)
            ->has('locationTypes')
            ->has('teamMembers', 1)
            ->where('currentUser.name', $this->user->name)
            ->where('schedules.0.name', 'Working hours')
            ->where('schedules.0.summary', 'Weekdays, 9 am - 5 pm'));
});

test('a schedule with no hours reports no availability', function () {
    AvailabilitySchedule::factory()->for($this->user)->create(['name' => 'Empty']);

    $this->actingAs($this->user)
        ->get(route('scheduling.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn ($page) => $page->where('schedules.0.summary', null));
});

test('an event type can be created from the quick create panel', function () {
    $this->actingAs($this->user)
        ->post(route('scheduling.store', ['current_team' => $this->team->slug]), eventTypePayload())
        ->assertRedirect(route('scheduling.index', ['current_team' => $this->team->slug]));

    $eventType = EventType::first();

    expect($eventType->name)->toBe('Discovery call')
        ->and($eventType->user_id)->toBe($this->user->id)
        ->and($eventType->team_id)->toBe($this->team->id)
        ->and($eventType->kind)->toBe(EventTypeKind::OneOnOne);
});

test('an event type cannot be given a minimum notice', function () {
    $this->actingAs($this->user)
        ->post(route('scheduling.store', ['current_team' => $this->team->slug]), eventTypePayload([
            'minimum_notice_minutes' => 240,
        ]))
        ->assertRedirect(route('scheduling.index', ['current_team' => $this->team->slug]));

    expect(EventType::first()->minimum_notice_minutes)->toBe(0);
});

test('the slug must be unique within the team', function () {
    EventType::factory()->ownedBy($this->user)->create(['slug' => 'discovery-call']);

    $this->actingAs($this->user)
        ->post(route('scheduling.store', ['current_team' => $this->team->slug]), eventTypePayload())
        ->assertSessionHasErrors('slug');
});

/*
 * The unique index is on (team_id, slug) alone while the validation rule
 * excludes soft deleted rows, so before the slug was released on delete this
 * combination passed validation and then died on INSERT with a raw 1062 --
 * a bare 500 on the save button.
 */
test('a slug can be reused after its event type is deleted', function () {
    $eventType = EventType::factory()->ownedBy($this->user)->create(['slug' => 'discovery-call']);

    $eventType->delete();

    expect($eventType->fresh()->slug)->not->toBe('discovery-call');

    $this->actingAs($this->user)
        ->post(route('scheduling.store', ['current_team' => $this->team->slug]), eventTypePayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('scheduling.index', ['current_team' => $this->team->slug]));

    expect(EventType::where('slug', 'discovery-call')->count())->toBe(1);
});

test('force deleting an event type leaves its slug alone', function () {
    $eventType = EventType::factory()->ownedBy($this->user)->create(['slug' => 'discovery-call']);

    $eventType->forceDelete();

    expect(EventType::withTrashed()->where('slug', 'discovery-call')->exists())->toBeFalse();
});

test('an event type keeps its own slug on update', function () {
    $eventType = EventType::factory()->ownedBy($this->user)->create(['slug' => 'discovery-call']);

    $this->actingAs($this->user)
        ->patch(route('scheduling.update', [
            'current_team' => $this->team->slug,
            'event_type' => $eventType->slug,
        ]), eventTypePayload())
        ->assertSessionHasNoErrors();

    expect($eventType->fresh()->slug)->toBe('discovery-call');
});

test('reserved slugs are rejected', function () {
    $this->actingAs($this->user)
        ->post(route('scheduling.store', ['current_team' => $this->team->slug]), eventTypePayload(['slug' => 'dashboard']))
        ->assertSessionHasErrors('slug');
});

test('a location that needs a detail cannot be saved without one', function () {
    $this->actingAs($this->user)
        ->post(route('scheduling.store', ['current_team' => $this->team->slug]), eventTypePayload([
            'location_type' => 'in_person',
            'location_detail' => '',
        ]))
        ->assertSessionHasErrors('location_detail');
});

test('a round robin needs at least one host', function () {
    $this->actingAs($this->user)
        ->post(route('scheduling.store', ['current_team' => $this->team->slug]), eventTypePayload([
            'kind' => EventTypeKind::RoundRobin->value,
            'host_ids' => [],
        ]))
        ->assertSessionHasErrors('host_ids');
});

test('a round robin stores its host pool in priority order', function () {
    $team = Team::factory()->create();
    $second = User::factory()->create();

    $team->members()->attach($this->user, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($second, ['role' => TeamRole::Member->value]);
    $this->user->switchTeam($team);

    $this->actingAs($this->user)
        ->post(route('scheduling.store', ['current_team' => $team->slug]), eventTypePayload([
            'kind' => EventTypeKind::RoundRobin->value,
            'host_ids' => [$second->id, $this->user->id],
        ]))
        ->assertSessionHasNoErrors();

    $hosts = EventType::first()->hosts;

    expect($hosts->pluck('id')->all())->toBe([$second->id, $this->user->id])
        ->and($hosts->first()->pivot->priority)->toBe(0);
});

test('custom questions are saved with the event type', function () {
    $this->actingAs($this->user)
        ->post(route('scheduling.store', ['current_team' => $this->team->slug]), eventTypePayload([
            'questions' => [
                ['type' => 'text', 'label' => 'Company', 'is_required' => true],
                ['type' => 'select', 'label' => 'Size', 'options' => ['1-10', '11-50'], 'is_required' => false],
            ],
        ]))
        ->assertSessionHasNoErrors();

    $questions = EventType::first()->questions;

    expect($questions)->toHaveCount(2)
        ->and($questions->first()->label)->toBe('Company')
        ->and($questions->first()->is_required)->toBeTrue()
        ->and($questions->last()->options)->toBe(['1-10', '11-50']);
});

test('updating replaces the question set', function () {
    $eventType = EventType::factory()->ownedBy($this->user)->create(['slug' => 'discovery-call']);
    $kept = $eventType->questions()->create(['type' => 'text', 'label' => 'Company', 'position' => 0]);
    $eventType->questions()->create(['type' => 'text', 'label' => 'Budget', 'position' => 1]);

    $this->actingAs($this->user)
        ->patch(route('scheduling.update', [
            'current_team' => $this->team->slug,
            'event_type' => $eventType->slug,
        ]), eventTypePayload([
            'questions' => [
                ['id' => $kept->id, 'type' => 'text', 'label' => 'Company name', 'is_required' => true],
            ],
        ]))
        ->assertSessionHasNoErrors();

    $questions = $eventType->fresh()->questions;

    expect($questions)->toHaveCount(1)
        ->and($questions->first()->id)->toBe($kept->id)
        ->and($questions->first()->label)->toBe('Company name');
});

test('requires confirmation round-trips through the editor', function () {
    $eventType = EventType::factory()->ownedBy($this->user)->create(['slug' => 'discovery-call']);

    $this->actingAs($this->user)
        ->patch(route('scheduling.update', [
            'current_team' => $this->team->slug,
            'event_type' => $eventType->slug,
        ]), eventTypePayload(['requires_confirmation' => true]))
        ->assertSessionHasNoErrors();

    expect($eventType->fresh()->requires_confirmation)->toBeTrue();

    $this->actingAs($this->user)
        ->get(route('scheduling.edit', [
            'current_team' => $this->team->slug,
            'event_type' => $eventType->slug,
        ]))
        ->assertInertia(fn ($page) => $page->where('eventType.requiresConfirmation', true));
});

test('a member of another team cannot edit an event type', function () {
    $eventType = EventType::factory()->ownedBy($this->user)->create();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('scheduling.edit', [
            'current_team' => $this->team->slug,
            'event_type' => $eventType->slug,
        ]))
        ->assertForbidden();
});

test('an event type slug resolves within the current team only', function () {
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($this->user, ['role' => TeamRole::Admin->value]);

    EventType::factory()->create([
        'team_id' => $otherTeam->id,
        'user_id' => $this->user->id,
        'slug' => 'shared-slug',
    ]);

    $this->actingAs($this->user)
        ->get(route('scheduling.edit', [
            'current_team' => $this->team->slug,
            'event_type' => 'shared-slug',
        ]))
        ->assertNotFound();
});

test('an event type can be deleted', function () {
    $eventType = EventType::factory()->ownedBy($this->user)->create();

    $this->actingAs($this->user)
        ->delete(route('scheduling.destroy', [
            'current_team' => $this->team->slug,
            'event_type' => $eventType->slug,
        ]))
        ->assertRedirect(route('scheduling.index', ['current_team' => $this->team->slug]));

    expect($eventType->fresh()->trashed())->toBeTrue();
});

test('an admin can create a one-on-one on behalf of another member', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();

    $team->members()->attach($this->user, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $this->user->switchTeam($team);

    $this->actingAs($this->user)
        ->post(route('scheduling.store', ['current_team' => $team->slug]), eventTypePayload([
            'user_id' => $member->id,
        ]))
        ->assertSessionHasNoErrors();

    expect(EventType::first()->user_id)->toBe($member->id);
});

test('a plain member cannot hand an event type to someone else', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $other = User::factory()->create();

    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $team->members()->attach($other, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);

    $this->actingAs($member)
        ->post(route('scheduling.store', ['current_team' => $team->slug]), eventTypePayload([
            'user_id' => $other->id,
        ]))
        ->assertSessionHasNoErrors();

    // The request is accepted, but ownership stays with the creator.
    expect(EventType::first()->user_id)->toBe($member->id);
});

test('a plain member cannot put a colleague\'s personal schedule on their own event type', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $colleague = User::factory()->create();

    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $team->members()->attach($colleague, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);

    $colleaguesSchedule = AvailabilitySchedule::factory()->for($colleague)->create();

    $this->actingAs($member)
        ->post(route('scheduling.store', ['current_team' => $team->slug]), eventTypePayload([
            'user_id' => $colleague->id,
            'availability_schedule_id' => $colleaguesSchedule->id,
        ]))
        ->assertSessionHasErrors('availability_schedule_id');

    expect(EventType::count())->toBe(0);
});

test('an event type cannot be handed to someone outside the team', function () {
    $stranger = User::factory()->create();

    $this->actingAs($this->user)
        ->post(route('scheduling.store', ['current_team' => $this->team->slug]), eventTypePayload([
            'user_id' => $stranger->id,
        ]))
        ->assertSessionHasErrors('user_id');
});

test('an admin can reassign an existing event type to another member', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();

    $team->members()->attach($this->user, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $this->user->switchTeam($team);

    $eventType = EventType::factory()->create([
        'team_id' => $team->id,
        'user_id' => $this->user->id,
        'slug' => 'discovery-call',
    ]);

    $this->actingAs($this->user)
        ->patch(route('scheduling.update', [
            'current_team' => $team->slug,
            'event_type' => $eventType->slug,
        ]), eventTypePayload(['user_id' => $member->id]))
        ->assertSessionHasNoErrors();

    expect($eventType->fresh()->user_id)->toBe($member->id);
});

test('the form knows whether the viewer may reassign ownership', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();

    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);

    $this->actingAs($member)
        ->get(route('scheduling.index', ['current_team' => $team->slug]))
        ->assertInertia(fn ($page) => $page->where('canAssignOwner', false));

    $this->actingAs($this->user)
        ->get(route('scheduling.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn ($page) => $page->where('canAssignOwner', true));
});

test('each event type carries an availability line and a shared flag', function () {
    AvailabilitySchedule::factory()->for($this->user)->weekdays('10:30:00', '16:00:00')->create();

    EventType::factory()->ownedBy($this->user)->create([
        'name' => 'Discovery call',
        'location_type' => 'in_person',
        'location_detail' => null,
    ]);

    $this->actingAs($this->user)
        ->get(route('scheduling.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn ($page) => $page
            ->where('eventTypes.0.availabilitySummary', 'Weekdays, 10:30 am - 4 pm')
            ->where('eventTypes.0.locationLabel', 'No location set')
            ->where('eventTypes.0.isShared', false)
            ->where('eventTypes.0.ownerName', $this->user->name));
});

test('a pooled event type whose hosts differ reports varying hours', function () {
    $team = Team::factory()->create();
    $second = User::factory()->create();

    $team->members()->attach($this->user, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($second, ['role' => TeamRole::Member->value]);
    $this->user->switchTeam($team);

    AvailabilitySchedule::factory()->for($this->user)->weekdays('09:00:00', '17:00:00')->create();
    AvailabilitySchedule::factory()->for($second)->weekdays('11:00:00', '15:00:00')->create();

    $eventType = EventType::factory()->roundRobin()->create([
        'team_id' => $team->id,
        'user_id' => $this->user->id,
    ]);
    $eventType->hosts()->attach([$this->user->id, $second->id]);

    $this->actingAs($this->user)
        ->get(route('scheduling.index', ['current_team' => $team->slug]))
        ->assertInertia(fn ($page) => $page
            ->where('eventTypes.0.availabilitySummary', 'Hours vary by host')
            ->where('eventTypes.0.isShared', true));
});

test('the index carries the settings the detail panel summarises', function () {
    $schedule = AvailabilitySchedule::factory()->for($this->user)->weekdays()->create(['name' => 'Working hours']);

    EventType::factory()->ownedBy($this->user)->create([
        'availability_schedule_id' => $schedule->id,
        'buffer_before_minutes' => 10,
        'date_range_type' => 'rolling_days',
        'rolling_days' => 60,
        'requires_confirmation' => true,
    ]);

    $this->actingAs($this->user)
        ->get(route('scheduling.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn ($page) => $page
            ->where('eventTypes.0.bufferBeforeMinutes', 10)
            ->where('eventTypes.0.dateRangeType', 'rolling_days')
            ->where('eventTypes.0.rollingDays', 60)
            ->where('eventTypes.0.requiresConfirmation', true)
            ->where('eventTypes.0.scheduleName', 'Working hours')
            ->where('eventTypes.0.canUpdate', true)
            ->where('eventTypes.0.canDelete', true));
});

test('the detail panel hides actions from a member who cannot manage the event type', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();

    $team->members()->attach($this->user, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);

    // A member only sees an event type they host, so hosting someone else's
    // is the one way to reach a listed event type you cannot manage.
    $eventType = EventType::factory()->create([
        'team_id' => $team->id,
        'user_id' => $this->user->id,
    ]);

    $eventType->hosts()->attach($member, ['priority' => 0]);

    $this->actingAs($member)
        ->get(route('scheduling.index', ['current_team' => $team->slug]))
        ->assertInertia(fn ($page) => $page
            ->where('eventTypes.0.canUpdate', false)
            ->where('eventTypes.0.canDelete', false));
});

test('an event type with no hours says so', function () {
    EventType::factory()->ownedBy($this->user)->create();

    $this->actingAs($this->user)
        ->get(route('scheduling.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn ($page) => $page
            ->where('eventTypes.0.availabilitySummary', 'No available days or times'));
});

test('the scope picker offers primary, group and user options', function () {
    $group = Group::factory()->create(['team_id' => $this->team->id, 'name' => 'Maintenance Team']);

    $this->actingAs($this->user)
        ->get(route('scheduling.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn ($page) => $page
            ->where('scope', 'all')
            ->has('scopeOptions.primary', 2)
            ->where('scopeOptions.groups.0.label', 'Maintenance Team')
            ->where('scopeOptions.users.0.label', $this->user->name));
});

test('scoping to a group narrows the list to that groups event types', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();

    $team->members()->attach($this->user, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $this->user->switchTeam($team);

    $group = Group::factory()->create(['team_id' => $team->id]);
    $group->members()->attach($member);

    EventType::factory()->create(['team_id' => $team->id, 'user_id' => $member->id, 'name' => 'Theirs']);
    EventType::factory()->create(['team_id' => $team->id, 'user_id' => $this->user->id, 'name' => 'Mine']);

    $this->actingAs($this->user)
        ->get(route('scheduling.index', ['current_team' => $team->slug, 'scope' => "group:{$group->id}"]))
        ->assertInertia(fn ($page) => $page
            ->has('eventTypes', 1)
            ->where('eventTypes.0.name', 'Theirs'));
});

test('an unknown scope falls back to showing everything', function () {
    EventType::factory()->ownedBy($this->user)->create();

    $this->actingAs($this->user)
        ->get(route('scheduling.index', ['current_team' => $this->team->slug, 'scope' => 'group:999']))
        ->assertInertia(fn ($page) => $page
            ->where('scope', 'all')
            ->has('eventTypes', 1));
});

test('team event types are listed under Shared with their host avatars', function () {
    $team = Team::factory()->create();
    $second = User::factory()->create(['name' => 'Kim Alvarez']);

    $team->members()->attach($this->user, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($second, ['role' => TeamRole::Member->value]);
    $this->user->switchTeam($team);

    $eventType = EventType::factory()->roundRobin()->create([
        'team_id' => $team->id,
        'user_id' => $this->user->id,
    ]);
    $eventType->hosts()->attach([$this->user->id, $second->id]);

    $this->actingAs($this->user)
        ->get(route('scheduling.index', ['current_team' => $team->slug]))
        ->assertInertia(fn ($page) => $page
            ->where('eventTypes.0.ownerName', 'Shared')
            ->has('eventTypes.0.hosts', 2)
            ->where('eventTypes.0.hosts.1.initial', 'K'));
});

test('a solo event type is listed under its owner', function () {
    EventType::factory()->ownedBy($this->user)->create();

    $this->actingAs($this->user)
        ->get(route('scheduling.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn ($page) => $page
            ->where('eventTypes.0.ownerName', $this->user->name)
            ->has('eventTypes.0.hosts', 1));
});

test('the create menu describes each kind the way the picker reads', function () {
    $this->actingAs($this->user)
        ->get(route('scheduling.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn ($page) => $page
            ->where('kinds.0.label', 'One-on-one')
            ->where('kinds.0.flow', '1 host → 1 invitee')
            ->where('kinds.0.description', 'Good for coffee chats, 1:1 interviews, etc.')
            ->where('kinds.2.label', 'Round robin')
            ->where('kinds.2.flow', 'Rotating hosts → 1 invitee'));
});

test('a group event type keeps its seats', function () {
    $this->actingAs($this->user)
        ->post(route('scheduling.store', ['current_team' => $this->team->slug]), eventTypePayload([
            'kind' => EventTypeKind::Group->value,
            'seats_per_slot' => 5,
        ]))
        ->assertSessionHasNoErrors();

    $eventType = EventType::first();

    expect($eventType->kind)->toBe(EventTypeKind::Group)
        ->and($eventType->seats_per_slot)->toBe(5)
        ->and($eventType->seats())->toBe(5);
});

test('a one-on-one always has a single seat', function () {
    $this->actingAs($this->user)
        ->post(route('scheduling.store', ['current_team' => $this->team->slug]), eventTypePayload([
            'seats_per_slot' => 5,
        ]))
        ->assertSessionHasNoErrors();

    // Seats only mean something for group events.
    expect(EventType::first()->seats())->toBe(1);
});

test('an event type can be disabled and enabled from the list', function () {
    $eventType = EventType::factory()->ownedBy($this->user)->create(['slug' => 'discovery-call']);
    $indexUrl = route('scheduling.index', ['current_team' => $this->team->slug]);
    $toggleUrl = route('scheduling.active.update', [
        'current_team' => $this->team->slug,
        'event_type' => $eventType->slug,
    ]);

    $this->actingAs($this->user)
        ->from($indexUrl)
        ->patch($toggleUrl, ['is_active' => false])
        ->assertRedirect($indexUrl);

    expect($eventType->fresh()->is_active)->toBeFalse();

    $log = ActivityLog::where('event', 'event_type.updated')->latest('id')->first();

    expect($log->description)->toContain('Disabled')
        ->and($log->properties['isActive'])->toBeFalse();

    $this->actingAs($this->user)
        ->from($indexUrl)
        ->patch($toggleUrl, ['is_active' => true]);

    expect($eventType->fresh()->is_active)->toBeTrue();
});

test('the active flag is validated', function () {
    $eventType = EventType::factory()->ownedBy($this->user)->create(['slug' => 'discovery-call']);

    $this->actingAs($this->user)
        ->from(route('scheduling.index', ['current_team' => $this->team->slug]))
        ->patch(route('scheduling.active.update', [
            'current_team' => $this->team->slug,
            'event_type' => $eventType->slug,
        ]), ['is_active' => 'banana'])
        ->assertSessionHasErrors('is_active');

    expect($eventType->fresh()->is_active)->toBeTrue();
});

test('a plain member cannot disable or duplicate a colleagues event type', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();

    $team->members()->attach($this->user, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);

    $eventType = EventType::factory()->create(['team_id' => $team->id, 'user_id' => $this->user->id]);

    $this->actingAs($member)
        ->patch(route('scheduling.active.update', [
            'current_team' => $team->slug,
            'event_type' => $eventType->slug,
        ]), ['is_active' => false])
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('scheduling.duplicate', [
            'current_team' => $team->slug,
            'event_type' => $eventType->slug,
        ]))
        ->assertForbidden();

    expect($eventType->fresh()->is_active)->toBeTrue()
        ->and(EventType::count())->toBe(1);
});

test('the toggle and duplicate resolve the slug within the current team only', function () {
    $otherOwner = User::factory()->create();
    $foreign = EventType::factory()->ownedBy($otherOwner)->create(['slug' => 'foreign-call']);

    $this->actingAs($this->user)
        ->patch(route('scheduling.active.update', [
            'current_team' => $this->team->slug,
            'event_type' => $foreign->slug,
        ]), ['is_active' => false])
        ->assertNotFound();

    $this->actingAs($this->user)
        ->post(route('scheduling.duplicate', [
            'current_team' => $this->team->slug,
            'event_type' => $foreign->slug,
        ]))
        ->assertNotFound();
});

test('an event type can be duplicated', function () {
    $eventType = EventType::factory()->ownedBy($this->user)->create([
        'name' => 'Discovery call',
        'slug' => 'discovery-call',
        'duration_minutes' => 45,
    ]);

    $this->actingAs($this->user)
        ->post(route('scheduling.duplicate', [
            'current_team' => $this->team->slug,
            'event_type' => $eventType->slug,
        ]))
        ->assertRedirect(route('scheduling.index', ['current_team' => $this->team->slug]));

    $copy = EventType::where('slug', 'discovery-call-2')->first();

    expect(EventType::count())->toBe(2)
        ->and($copy)->not->toBeNull()
        ->and($copy->name)->toBe('Discovery call (copy)')
        ->and($copy->kind)->toBe($eventType->kind)
        ->and($copy->duration_minutes)->toBe(45)
        ->and($copy->user_id)->toBe($this->user->id)
        ->and($copy->team_id)->toBe($this->team->id)
        ->and($copy->is_active)->toBeTrue();

    expect(ActivityLog::where('event', 'event_type.duplicated')->exists())->toBeTrue();
});

test('a duplicate copies questions and the host pool', function () {
    $second = User::factory()->create();
    $this->team->members()->attach($second, ['role' => TeamRole::Member->value]);

    $schedule = AvailabilitySchedule::factory()->for($this->user)->create();

    $eventType = EventType::factory()->ownedBy($this->user)->roundRobin()->create(['slug' => 'discovery-call']);
    $eventType->hosts()->attach($second->id, ['priority' => 0, 'availability_schedule_id' => $schedule->id]);
    $eventType->hosts()->attach($this->user->id, ['priority' => 1]);
    $eventType->questions()->create(['type' => 'text', 'label' => 'Company', 'is_required' => true, 'position' => 0]);
    $eventType->questions()->create(['type' => 'select', 'label' => 'Size', 'options' => ['1-10', '11-50'], 'position' => 1]);

    $this->actingAs($this->user)
        ->post(route('scheduling.duplicate', [
            'current_team' => $this->team->slug,
            'event_type' => $eventType->slug,
        ]));

    $copy = EventType::where('slug', 'discovery-call-2')->first();
    $hosts = $copy->hosts;
    $questions = $copy->questions;

    expect($hosts->pluck('id')->all())->toBe([$second->id, $this->user->id])
        ->and($hosts->first()->pivot->priority)->toBe(0)
        ->and($hosts->first()->pivot->availability_schedule_id)->toBe($schedule->id)
        ->and($questions)->toHaveCount(2)
        ->and($questions->first()->label)->toBe('Company')
        ->and($questions->first()->is_required)->toBeTrue()
        ->and($questions->last()->options)->toBe(['1-10', '11-50']);
});

test('a duplicated slug steps past legacy soft-deleted event types', function () {
    $eventType = EventType::factory()->ownedBy($this->user)->create(['slug' => 'discovery-call']);

    // Rows trashed before slugs were released on delete still hold their
    // original slug in the unique index, so recreate that state directly.
    $trashed = EventType::factory()->ownedBy($this->user)->create(['slug' => 'placeholder']);
    $trashed->delete();
    EventType::withTrashed()->whereKey($trashed->id)->update(['slug' => 'discovery-call-2']);

    $this->actingAs($this->user)
        ->post(route('scheduling.duplicate', [
            'current_team' => $this->team->slug,
            'event_type' => $eventType->slug,
        ]));

    expect(EventType::where('slug', 'discovery-call-3')->exists())->toBeTrue();
});

/**
 * Resolve the scheduling page's deferred calendar props via a partial reload.
 */
function schedulingCalendarProps(TestCase $test, Team $team, array $query = []): TestResponse
{
    return $test->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Data' => 'calendarMonth,calendarBookings',
        'X-Inertia-Partial-Component' => 'scheduling/event-types/Index',
        'X-Inertia-Version' => (new HandleInertiaRequests)->version(request()),
    ])->get(route('scheduling.index', ['current_team' => $team->slug, ...$query]));
}

test('the calendar groups the months hosted meetings by day in the users timezone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 08:00:00', 'UTC'));

    $user = User::factory()->create(['timezone' => 'America/Chicago']);
    $eventType = EventType::factory()->ownedBy($user)->create(['name' => 'Interview']);

    // 7:30 pm in Chicago on the 10th, although UTC has rolled to the 11th.
    Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'starts_at' => CarbonImmutable::parse('2026-09-11 00:30:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-11 01:00:00', 'UTC'),
    ]);

    Booking::factory()->pending()->create([
        'event_type_id' => $eventType->id,
        'starts_at' => CarbonImmutable::parse('2026-09-15 14:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-15 14:30:00', 'UTC'),
    ]);

    $response = schedulingCalendarProps($this->actingAs($user), $user->currentTeam)->assertOk();

    $bookings = $response->json('props.calendarBookings');

    expect($response->json('props.calendarMonth'))->toBe('2026-09')
        ->and($bookings['2026-09-10'])->toHaveCount(1)
        ->and($bookings['2026-09-10'][0]['eventTypeName'])->toBe('Interview')
        ->and($bookings['2026-09-10'][0]['timeLabel'])->toBe('7:30 pm')
        ->and($bookings['2026-09-10'][0]['endTimeLabel'])->toBe('8:00 pm')
        ->and($bookings['2026-09-15'][0]['status'])->toBe('pending');
});

test('the calendar leaves out other hosts, other teams, and canceled meetings', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 08:00:00', 'UTC'));

    $colleague = User::factory()->create();
    $this->team->members()->attach($colleague, ['role' => TeamRole::Member->value]);

    $mine = EventType::factory()->ownedBy($this->user)->create();
    Booking::factory()->create([
        'event_type_id' => $mine->id,
        'starts_at' => CarbonImmutable::parse('2026-09-08 15:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-08 15:30:00', 'UTC'),
    ]);
    Booking::factory()->canceled()->create([
        'event_type_id' => $mine->id,
        'starts_at' => CarbonImmutable::parse('2026-09-09 15:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-09 15:30:00', 'UTC'),
    ]);

    $colleagues = EventType::factory()->create(['team_id' => $this->team->id, 'user_id' => $colleague->id]);
    Booking::factory()->create([
        'event_type_id' => $colleagues->id,
        'starts_at' => CarbonImmutable::parse('2026-09-10 15:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-10 15:30:00', 'UTC'),
    ]);

    $elsewhere = EventType::factory()->ownedBy(User::factory()->create())->create();
    Booking::factory()->create([
        'event_type_id' => $elsewhere->id,
        'starts_at' => CarbonImmutable::parse('2026-09-11 15:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-11 15:30:00', 'UTC'),
    ]);

    $bookings = schedulingCalendarProps($this->actingAs($this->user), $this->team)
        ->assertOk()
        ->json('props.calendarBookings');

    expect($bookings)->toHaveCount(1)
        ->and($bookings['2026-09-08'])->toHaveCount(1);
});

test('the calendar can page to another month', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 08:00:00', 'UTC'));

    $eventType = EventType::factory()->ownedBy($this->user)->create();
    Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'starts_at' => CarbonImmutable::parse('2026-09-08 15:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-08 15:30:00', 'UTC'),
    ]);
    Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'starts_at' => CarbonImmutable::parse('2026-10-20 15:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-20 15:30:00', 'UTC'),
    ]);

    $response = schedulingCalendarProps($this->actingAs($this->user), $this->team, ['calendarMonth' => '2026-10'])
        ->assertOk();

    $bookings = $response->json('props.calendarBookings');

    expect($response->json('props.calendarMonth'))->toBe('2026-10')
        ->and($bookings)->toHaveCount(1)
        ->and($bookings['2026-10-20'])->toHaveCount(1);
});

test('a malformed calendar month falls back to the current month', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 08:00:00', 'UTC'));

    schedulingCalendarProps($this->actingAs($this->user), $this->team, ['calendarMonth' => 'nope-13'])
        ->assertOk()
        ->assertJsonPath('props.calendarMonth', '2026-09');
});

test('a pooled event type pointed at a team is listed under that team', function () {
    $group = Group::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Leasing',
        'slug' => 'leasing',
    ]);

    EventType::factory()->ownedBy($this->user)->create([
        'name' => 'Leasing Team Meeting',
        'kind' => EventTypeKind::RoundRobin,
        'group_id' => $group->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('scheduling.index', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('eventTypes.0.ownerName', 'Leasing')
            ->where('eventTypes.0.ownerLandingUrl', route('book.event-type', [
                'page' => $this->team->slug,
                'eventType' => 'leasing',
            ])));
});

test('a pooled event type pointed at no team is listed under Shared with no page of its own', function () {
    EventType::factory()->ownedBy($this->user)->create([
        'name' => 'Round robin call',
        'kind' => EventTypeKind::RoundRobin,
    ]);

    $this->actingAs($this->user)
        ->get(route('scheduling.index', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('eventTypes.0.ownerName', 'Shared')
            ->where('eventTypes.0.ownerLandingUrl', null));
});

test('a super admin can update somebody elses event type', function () {
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);

    $schedule = AvailabilitySchedule::factory()->for($owner)->create();

    $eventType = EventType::factory()->ownedBy($owner)->create([
        'team_id' => $team->id,
        'availability_schedule_id' => $schedule->id,
    ]);

    // The schedule belongs to the owner, not to the operator editing it.
    $this->actingAs($superAdmin)
        ->patch(route('scheduling.update', [
            'current_team' => $team->slug,
            'event_type' => $eventType->slug,
        ]), eventTypePayload([
            'name' => 'Renamed by the operator',
            'slug' => $eventType->slug,
            'user_id' => $owner->id,
            'availability_schedule_id' => $schedule->id,
        ]))
        ->assertSessionHasNoErrors();

    expect($eventType->fresh()->name)->toBe('Renamed by the operator');
});
