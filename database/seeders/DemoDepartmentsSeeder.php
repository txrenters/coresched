<?php

namespace Database\Seeders;

use App\Actions\Scheduling\CreateDefaultAvailability;
use App\Enums\EventTypeKind;
use App\Enums\LocationType;
use App\Enums\TeamRole;
use App\Models\EventType;
use App\Models\Group;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A demo organization for trying the departments page locally.
 *
 * Not called from DatabaseSeeder, which also runs on production. Run it with
 * `php artisan db:seed --class=DemoDepartmentsSeeder`. Rerunning only adds
 * the department pages, and only if the demo organization has none.
 */
class DemoDepartmentsSeeder extends Seeder
{
    /**
     * Seed the demo organization, its teams and their event types.
     */
    public function run(): void
    {
        if (Team::query()->where('slug', 'demo-realty')->exists()) {
            $this->pages(Team::query()->where('slug', 'demo-realty')->sole());

            return;
        }

        DB::transaction(function () {
            $team = Team::create([
                'name' => 'Demo Realty',
                'slug' => 'demo-realty',
                'timezone' => 'America/Chicago',
            ]);

            $admin = $this->member($team, 'Demo Admin', 'demo-admin@example.test', TeamRole::Admin);
            $leasingAgents = [
                $this->member($team, 'Lena Leasing', 'lena@example.test'),
                $this->member($team, 'Luis Leasing', 'luis@example.test'),
            ];
            $technician = $this->member($team, 'Max Maintenance', 'max@example.test');
            $ownerManager = $this->member($team, 'Olivia Owners', 'olivia@example.test');

            $leasing = $this->team($team, 'Leasing', 'Tours, applications and lease signings.', $leasingAgents);
            $maintenance = $this->team($team, 'Maintenance', 'Repairs and inspections for residents.', [$technician]);
            $owners = $this->team($team, 'Owner Services', 'For property owners working with us.', [$ownerManager]);
            $this->team($team, 'Internal', 'Staff only; on no page.', [$admin]);

            $this->eventType($admin, $leasing, 'Property Tour', 'property-tour', 30, '#2563eb');
            $this->eventType($admin, $leasing, 'Lease Signing', 'lease-signing', 45, '#7c3aed');
            $this->eventType($admin, $maintenance, 'Repair Visit', 'repair-visit', 60, '#ea580c');
            $this->eventType($admin, $maintenance, 'Move-out Inspection', 'move-out-inspection', 30, '#0891b2');
            $this->eventType($admin, $owners, 'Owner Consultation', 'owner-consultation', 30, '#16a34a');

            // Listed on Leasing's page too, without changing who hosts it.
            $leasing->pageEventTypes()->attach(
                $this->eventType($admin, null, 'General Question', 'general-question', 15, '#64748b'),
            );

            $this->pages($team);
        });
    }

    /**
     * Create the demo department pages, unless they already exist.
     */
    protected function pages(Team $team): void
    {
        if ($team->departmentPages()->exists()) {
            return;
        }

        $groups = $team->groups()->pluck('id', 'name');

        $residents = $team->departmentPages()->create([
            'name' => 'Residents',
            'description' => 'Book time with the team you need.',
        ]);
        $residents->groups()->attach([$groups['Leasing'], $groups['Maintenance']]);

        $owners = $team->departmentPages()->create([
            'name' => 'Owners',
            'description' => 'For property owners working with Demo Realty.',
        ]);
        $owners->groups()->attach([$groups['Owner Services'], $groups['Leasing']]);
    }

    /**
     * Create a verified user inside the demo organization.
     */
    protected function member(Team $team, string $name, string $email, TeamRole $role = TeamRole::Member): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => 'password']);
        $user->forceFill(['email_verified_at' => now()])->save();

        $team->members()->attach($user, ['role' => $role->value]);
        $user->switchTeam($team);

        app(CreateDefaultAvailability::class)->handle($user, $team->timezone);

        return $user;
    }

    /**
     * Create a team (the Group model) with its members in priority order.
     *
     * @param  array<int, User>  $members
     */
    protected function team(Team $team, string $name, string $description, array $members): Group
    {
        $group = $team->groups()->create([
            'name' => $name,
            'description' => $description,
        ]);

        foreach ($members as $priority => $member) {
            $group->members()->attach($member, ['priority' => $priority]);
        }

        return $group;
    }

    /**
     * Create a round robin event type, hosting from a team when one is given.
     */
    protected function eventType(User $owner, ?Group $group, string $name, string $slug, int $minutes, string $color): EventType
    {
        return EventType::create([
            'team_id' => $owner->current_team_id,
            'user_id' => $owner->id,
            'group_id' => $group?->id,
            'kind' => EventTypeKind::RoundRobin,
            'name' => $name,
            'slug' => $slug,
            'color' => $color,
            'duration_minutes' => $minutes,
            'location_type' => LocationType::Phone,
            'is_active' => true,
        ]);
    }
}
