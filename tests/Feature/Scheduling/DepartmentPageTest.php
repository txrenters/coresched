<?php

use App\Enums\TeamRole;
use App\Models\DepartmentPage;
use App\Models\Group;
use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->member = User::factory()->create();

    $this->team->members()->attach($this->owner, ['role' => TeamRole::Admin->value]);
    $this->team->members()->attach($this->member, ['role' => TeamRole::Member->value]);
    $this->owner->switchTeam($this->team);

    $this->leasing = Group::factory()->create(['team_id' => $this->team->id, 'name' => 'Leasing']);
    $this->maintenance = Group::factory()->create(['team_id' => $this->team->id, 'name' => 'Maintenance']);
});

test('an admin can create a page for a set of teams', function () {
    $this->actingAs($this->owner)
        ->post(route('department-pages.store', ['current_team' => $this->team->slug]), [
            'name' => 'Residents',
            'group_ids' => [$this->leasing->id, $this->maintenance->id],
        ])
        ->assertRedirect(route('groups.index', ['current_team' => $this->team->slug]));

    $created = $this->team->departmentPages()->sole();

    expect($created->slug)->toMatch('/^[a-z0-9]{8}$/')
        ->and($created->groups->pluck('id')->sort()->values()->all())->toBe([$this->leasing->id, $this->maintenance->id]);

    $this->get(route('groups.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn ($page) => $page
            ->where('departmentPages.0.name', 'Residents')
            ->where('departmentPages.0.groupNames', ['Leasing', 'Maintenance'])
            ->where('departmentPages.0.url', route('book.departments', ['page' => $this->team->slug, 'departmentPage' => $created->slug])));
});

test('updating a page replaces its teams', function () {
    $page = DepartmentPage::factory()->create(['team_id' => $this->team->id]);
    $page->groups()->attach($this->leasing);

    $this->actingAs($this->owner)
        ->patch(route('department-pages.update', ['current_team' => $this->team->slug, 'department_page' => $page->slug]), [
            'name' => 'Owners',
            'group_ids' => [$this->maintenance->id],
        ])
        ->assertSessionHasNoErrors();

    expect($page->fresh()->name)->toBe('Owners')
        ->and($page->fresh()->slug)->toBe($page->slug)
        ->and($page->fresh()->groups->pluck('id')->all())->toBe([$this->maintenance->id]);
});

test('an admin can delete a page without touching its teams', function () {
    $page = DepartmentPage::factory()->create(['team_id' => $this->team->id]);
    $page->groups()->attach($this->leasing);

    $this->actingAs($this->owner)
        ->delete(route('department-pages.destroy', ['current_team' => $this->team->slug, 'department_page' => $page->slug]))
        ->assertRedirect();

    expect(DepartmentPage::query()->count())->toBe(0)
        ->and($this->leasing->fresh())->not->toBeNull();
});

test('a page needs at least one team', function () {
    $this->actingAs($this->owner)
        ->post(route('department-pages.store', ['current_team' => $this->team->slug]), ['name' => 'Residents', 'group_ids' => []])
        ->assertSessionHasErrors('group_ids');
});

test('a team from another organization cannot be put on a page', function () {
    $foreign = Group::factory()->create();

    $this->actingAs($this->owner)
        ->post(route('department-pages.store', ['current_team' => $this->team->slug]), [
            'name' => 'Residents',
            'group_ids' => [$foreign->id],
        ])
        ->assertSessionHasErrors('group_ids.0');
});

test('a plain member cannot create a page', function () {
    $this->member->switchTeam($this->team);

    $this->actingAs($this->member)
        ->post(route('department-pages.store', ['current_team' => $this->team->slug]), [
            'name' => 'Residents',
            'group_ids' => [$this->leasing->id],
        ])
        ->assertForbidden();
});

test('a page from another organization cannot be edited through this one', function () {
    $foreign = DepartmentPage::factory()->create();

    $this->actingAs($this->owner)
        ->patch(route('department-pages.update', ['current_team' => $this->team->slug, 'department_page' => $foreign->slug]), [
            'name' => 'Stolen',
            'group_ids' => [$this->leasing->id],
        ])
        ->assertNotFound();
});
