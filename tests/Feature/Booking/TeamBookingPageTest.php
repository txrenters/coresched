<?php

use App\Enums\EventTypeKind;
use App\Enums\TeamRole;
use App\Models\DepartmentPage;
use App\Models\EventType;
use App\Models\Group;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/**
 * A team (the Group model) gets its own public landing page at
 * /book/{organization}/{team}, listing only the event types pointed at it.
 * The organization page keeps listing everything.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = $this->user->currentTeam;

    $this->group = Group::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Leasing',
        'slug' => 'leasing',
    ]);

    $this->grouped = EventType::factory()->ownedBy($this->user)->create([
        'name' => 'Tour a property',
        'slug' => 'tour-a-property',
        'group_id' => $this->group->id,
    ]);

    $this->ungrouped = EventType::factory()->ownedBy($this->user)->create([
        'name' => 'Discovery call',
        'slug' => 'discovery-call',
    ]);
});

test('a team page lists only its own event types', function () {
    $this->get("/book/{$this->team->slug}/leasing")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('book/Page')
            ->where('page.name', 'Leasing')
            ->where('parent.name', $this->team->name)
            ->has('eventTypes', 1)
            ->where('eventTypes.0.slug', 'tour-a-property'),
        );
});

/*
 * An organization page advertises what the organization offers as a whole, so
 * it lists only the pooled kinds. Both fixtures above are one-on-ones, which
 * belong on their owner's personal link.
 */
test('the organization page lists only shared event types', function () {
    $shared = EventType::factory()->ownedBy($this->user)->create([
        'name' => 'Property tour',
        'slug' => 'property-tour',
        'kind' => EventTypeKind::RoundRobin,
        'group_id' => $this->group->id,
    ]);

    $this->get("/book/{$this->team->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('book/Page')
            ->has('eventTypes', 1)
            ->where('eventTypes.0.slug', $shared->slug),
        );
});

/*
 * Not listed is not the same as not reachable: a link already handed out for a
 * personal event type must keep working from the organization page.
 */
test('an unlisted personal event type still resolves by direct link', function () {
    $this->get("/book/{$this->team->slug}/discovery-call")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('book/EventType'));
});

test('a personal page still lists the owner own event types', function () {
    $this->user->forceFill(['booking_slug' => 'sam'])->save();

    $this->get('/book/sam')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('book/Page')
            ->has('eventTypes', 2),
        );
});

/*
 * A shared event draws its hosts from a pool, so it is the organization's to
 * advertise -- including on the page of the person who created it and then
 * handed the hosting to someone else. The link still resolves there.
 */
test('a shared event type is not listed on its creator personal page', function () {
    $this->user->forceFill(['booking_slug' => 'sam'])->save();

    $other = User::factory()->create();
    $this->team->members()->attach($other, ['role' => TeamRole::Member->value]);

    $shared = EventType::factory()->ownedBy($this->user)->roundRobin()->create([
        'name' => 'Property tour',
        'slug' => 'property-tour',
    ]);
    $shared->hosts()->sync([$other->id => ['priority' => 0]]);

    $this->get('/book/sam')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('eventTypes', 2)
            ->where('eventTypes.0.slug', 'discovery-call')
            ->where('eventTypes.1.slug', 'tour-a-property'),
        );

    $this->get('/book/sam/property-tour')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('book/EventType'));
});

test('a personal page lists the shared event types its person hosts, with their kind', function () {
    $host = User::factory()->create(['booking_slug' => 'robin']);
    $this->team->members()->attach($host, ['role' => TeamRole::Member->value]);

    $pinned = EventType::factory()->ownedBy($this->user)->roundRobin()->create([
        'name' => 'Lease signing',
        'slug' => 'lease-signing',
    ]);
    $pinned->hosts()->sync([$host->id => ['priority' => 0]]);

    $this->group->members()->attach($host);

    EventType::factory()->ownedBy($this->user)->create([
        'name' => 'Move-out inspection',
        'slug' => 'move-out-inspection',
        'kind' => EventTypeKind::Collective,
        'group_id' => $this->group->id,
    ]);

    // Shared, but hosted by someone else.
    EventType::factory()->ownedBy($this->user)->roundRobin()->create(['slug' => 'repair-visit']);

    $this->get('/book/robin')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('eventTypes', 2)
            ->where('eventTypes.0.slug', 'lease-signing')
            ->where('eventTypes.0.kindLabel', 'Round robin')
            ->where('eventTypes.0.isShared', true)
            ->where('eventTypes.0.url', route('book.event-type', ['page' => $this->team->slug, 'eventType' => 'lease-signing']))
            ->where('eventTypes.1.slug', 'move-out-inspection')
            ->where('eventTypes.1.kindLabel', 'Collective'),
        );
});

test('a personal page tags its own event types with their kind', function () {
    $this->user->forceFill(['booking_slug' => 'sam'])->save();

    $this->get('/book/sam')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('eventTypes.0.kindLabel', 'One-on-one')
            ->where('eventTypes.0.isShared', false)
            ->where('eventTypes.0.url', route('book.event-type', ['page' => 'sam', 'eventType' => 'discovery-call'])),
        );
});

/*
 * A team page and an event type share the /book/{page}/{slug} shape, so the
 * order they resolve in decides which wins. Event types go first, or naming a
 * team after one would silently break its booking links.
 */
test('an event type wins over a team with the same slug', function () {
    Group::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Discovery call',
        'slug' => 'discovery-call',
    ]);

    $this->get("/book/{$this->team->slug}/discovery-call")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('book/EventType'));
});

test('a team from another organization is not reachable', function () {
    $other = User::factory()->create();

    Group::factory()->create([
        'team_id' => $other->currentTeam->id,
        'slug' => 'maintenance',
    ]);

    $this->get("/book/{$this->team->slug}/maintenance")->assertNotFound();
});

test('a personal booking page has no team pages', function () {
    $this->user->forceFill(['booking_slug' => 'sam'])->save();

    $this->get('/book/sam/leasing')->assertNotFound();
});

/*
 * Picking an event type for a team page only lists it there: it can be anyone's
 * one-on-one or a shared event hosting from elsewhere.
 */
test('a team page also lists event types picked for it', function () {
    $this->group->pageEventTypes()->attach($this->ungrouped);

    $this->get("/book/{$this->team->slug}/leasing")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('eventTypes', 2)
            ->where('eventTypes.0.slug', 'discovery-call')
            ->where('eventTypes.1.slug', 'tour-a-property'),
        );
});

test('a hidden event type picked for a team page is not listed', function () {
    $this->ungrouped->update(['is_hidden' => true]);
    $this->group->pageEventTypes()->attach($this->ungrouped);

    $this->get("/book/{$this->team->slug}/leasing")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('eventTypes', 1)
            ->where('eventTypes.0.slug', 'tour-a-property'),
        );
});

/*
 * A department page lists only the teams picked for it, and each card leads on
 * to that team's page -- which then leads back to the department page.
 */
test('a department page lists the teams picked for it', function () {
    Group::factory()->create(['team_id' => $this->team->id, 'name' => 'Internal']);
    $residents = DepartmentPage::factory()->create(['team_id' => $this->team->id, 'name' => 'Residents']);
    $residents->groups()->attach($this->group);

    $this->get("/book/{$this->team->slug}/departments/{$residents->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('book/Departments')
            ->where('page.name', 'Residents')
            ->where('organizationName', $this->team->name)
            ->has('departments', 1)
            ->where('departments.0.name', 'Leasing')
            ->where('departments.0.eventTypeCount', 1)
            ->where('departments.0.url', route('book.event-type', [
                'page' => $this->team->slug,
                'eventType' => 'leasing',
                'from' => $residents->slug,
            ])),
        );

    $this->get("/book/{$this->team->slug}/leasing?from={$residents->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('parent.name', 'Residents')
            ->where('parent.url', route('book.departments', ['page' => $this->team->slug, 'departmentPage' => $residents->slug])),
        );
});

test('a team page only leads back to a department page that lists it', function () {
    $owners = DepartmentPage::factory()->create(['team_id' => $this->team->id, 'name' => 'Owners']);

    $this->get("/book/{$this->team->slug}/leasing?from={$owners->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('parent.url', route('book.page', ['page' => $this->team->slug])),
        );
});

test('a department page from another organization is not reachable', function () {
    $other = User::factory()->create();
    $foreign = DepartmentPage::factory()->create(['team_id' => $other->currentTeam->id, 'name' => 'Residents']);

    $this->get("/book/{$this->team->slug}/departments/{$foreign->slug}")->assertNotFound();
});

test('the website link is labelled with the organization name on team and department pages', function () {
    $residents = DepartmentPage::factory()->create(['team_id' => $this->team->id, 'name' => 'Residents']);
    $residents->groups()->attach($this->group);

    $this->get("/book/{$this->team->slug}/leasing")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('page.websiteName', $this->team->name));

    $this->get("/book/{$this->team->slug}/departments/{$residents->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('page.websiteName', $this->team->name));
});
