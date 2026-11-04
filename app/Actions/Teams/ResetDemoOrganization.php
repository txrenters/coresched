<?php

namespace App\Actions\Teams;

use App\Actions\Scheduling\CreateDefaultAvailability;
use App\Enums\BookingStatus;
use App\Enums\EventTypeKind;
use App\Enums\LocationType;
use App\Enums\TeamPlan;
use App\Enums\TeamRole;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Group;
use App\Models\Team;
use App\Models\User;
use App\Support\Demo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Throws away the public demo organization and builds it again from scratch.
 *
 * Run nightly by demo:reset, so whatever visitors changed during the day is
 * gone by morning. Everything is written by hand rather than with factories:
 * this runs on production, where Faker is not installed.
 */
class ResetDemoOrganization
{
    public const TIMEZONE = 'America/Chicago';

    public function __construct(protected CreateDefaultAvailability $createDefaultAvailability) {}

    /**
     * Rebuild the demo organization and return it.
     */
    public function handle(): Team
    {
        return DB::transaction(function (): Team {
            $this->forgetCurrentDemo();

            $team = $this->createTeam();

            $admin = $this->member($team, 'Alex Morgan', Demo::ADMIN_EMAIL, TeamRole::Admin);
            $jordan = $this->member($team, 'Jordan Lee', 'demo+jordan@example.com');
            $priya = $this->member($team, 'Priya Shah', 'demo+priya@example.com');
            $marcus = $this->member($team, 'Marcus Reed', 'demo+marcus@example.com');
            $sofia = $this->member($team, 'Sofia Alvarez', 'demo+sofia@example.com');

            $leasing = $this->group($team, 'Leasing', 'Tours, applications and lease signings.', [$jordan, $priya]);
            $maintenance = $this->group($team, 'Maintenance', 'Repairs and inspections for residents.', [$marcus]);
            $owners = $this->group($team, 'Owner Services', 'For property owners working with us.', [$sofia]);

            $tour = $this->eventType($team, $admin, $leasing, EventTypeKind::RoundRobin, 'Property Tour', 'property-tour', 30, '#2563eb', LocationType::InPerson);
            $signing = $this->eventType($team, $admin, $leasing, EventTypeKind::RoundRobin, 'Lease Signing', 'lease-signing', 45, '#7c3aed', LocationType::GoogleMeet);
            $repair = $this->eventType($team, $admin, $maintenance, EventTypeKind::RoundRobin, 'Repair Visit', 'repair-visit', 60, '#ea580c', LocationType::InPerson);
            $consult = $this->eventType($team, $admin, $owners, EventTypeKind::RoundRobin, 'Owner Consultation', 'owner-consultation', 30, '#16a34a', LocationType::MicrosoftTeams);
            $intro = $this->eventType($team, $admin, null, EventTypeKind::OneOnOne, 'Intro Call', 'intro-call', 15, '#0891b2', LocationType::Phone);

            $residents = $team->departmentPages()->create([
                'name' => 'Residents',
                'description' => 'Book time with the team you need.',
            ]);
            $residents->groups()->attach([$leasing->id, $maintenance->id]);

            $this->bookings([
                [$tour, $jordan], [$tour, $priya], [$signing, $priya], [$repair, $marcus],
                [$consult, $sofia], [$intro, $admin], [$tour, $jordan], [$repair, $marcus],
            ]);

            return $team;
        });
    }

    /**
     * Delete the current demo organization, its data and its sample people.
     *
     * Organizations are soft deleted, so the demo is force deleted: that is
     * what cascades to everything belonging to it, and what frees its slug.
     * A person is deleted only when the demo was their sole organization, so
     * nobody real is ever removed by this.
     */
    protected function forgetCurrentDemo(): void
    {
        Team::withTrashed()->where('is_demo', true)->each(function (Team $team) {
            $memberIds = $team->members()->pluck('users.id')->all();

            $team->invitations()->delete();
            $team->memberships()->delete();
            $team->forceDelete();

            User::query()
                ->whereKey($memberIds)
                ->whereDoesntHave('teams')
                ->each(fn (User $user) => $user->delete());
        });

        User::query()
            ->where('email', Demo::ADMIN_EMAIL)
            ->whereDoesntHave('teams')
            ->each(fn (User $user) => $user->delete());
    }

    /**
     * Create the demo organization itself.
     */
    protected function createTeam(): Team
    {
        if (Team::withTrashed()->where('slug', Demo::TEAM_SLUG)->exists()) {
            throw new RuntimeException('The demo slug "'.Demo::TEAM_SLUG.'" belongs to a real organization.');
        }

        $team = Team::create([
            'name' => 'Sunrise Property Group',
            'slug' => Demo::TEAM_SLUG,
            'timezone' => self::TIMEZONE,
            'welcome_message' => 'This is the CoreSched demo. Book anything you like: no emails or texts are sent.',
            'plan' => TeamPlan::Enterprise,
        ]);

        $team->forceFill(['is_demo' => true])->save();

        return $team;
    }

    /**
     * Create a verified sample person in the demo organization.
     *
     * Their password is random and never shown: the demo admin is signed in
     * by DemoController, and the others are never signed in at all.
     */
    protected function member(Team $team, string $name, string $email, TeamRole $role = TeamRole::Member): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Str::password(40),
            'timezone' => self::TIMEZONE,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $team->members()->attach($user, ['role' => $role->value]);
        $user->switchTeam($team);

        $this->createDefaultAvailability->handle($user, self::TIMEZONE);

        return $user;
    }

    /**
     * Create a group with its members in priority order.
     *
     * @param  array<int, User>  $members
     */
    protected function group(Team $team, string $name, string $description, array $members): Group
    {
        $group = $team->groups()->create(['name' => $name, 'description' => $description]);

        foreach ($members as $priority => $member) {
            $group->members()->attach($member, ['priority' => $priority]);
        }

        return $group;
    }

    /**
     * Create an active event type.
     */
    protected function eventType(Team $team, User $owner, ?Group $group, EventTypeKind $kind, string $name, string $slug, int $minutes, string $color, LocationType $location): EventType
    {
        return EventType::create([
            'team_id' => $team->id,
            'user_id' => $owner->id,
            'group_id' => $group?->id,
            'kind' => $kind,
            'name' => $name,
            'slug' => $slug,
            'color' => $color,
            'duration_minutes' => $minutes,
            'location_type' => $location,
            'is_active' => true,
        ]);
    }

    /**
     * Fill the calendar with meetings from last week to the week after next,
     * so the dashboard, meetings list and reports all have something to show.
     *
     * Written straight to the table rather than through CreateBooking, which
     * would sync calendars and notify people.
     *
     * @param  array<int, array{0: EventType, 1: User}>  $hosting
     */
    protected function bookings(array $hosting): void
    {
        $invitees = [
            'Taylor Brooks', 'Morgan Diaz', 'Casey Nguyen', 'Riley Patel', 'Jamie Carter',
            'Avery Kim', 'Drew Thompson', 'Quinn Foster', 'Harper Wells', 'Rowan Price',
            'Emerson Hayes', 'Logan Ortiz', 'Parker Bennett', 'Reese Coleman', 'Skyler Ward',
            'Dakota Ross', 'Cameron Bell', 'Finley Gray', 'Sage Murphy', 'Blake Rivera',
        ];

        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $hours = [9, 10, 11, 13, 14, 15, 16];

        foreach ($invitees as $index => $invitee) {
            // Day offsets from -6 to +13, skipping weekends.
            $day = $today->addDays($index - 6);

            if ($day->isWeekend()) {
                $day = $day->next('Monday');
            }

            [$eventType, $host] = $hosting[$index % count($hosting)];
            $startsAt = $day->setTime($hours[$index % count($hours)], $index % 2 === 0 ? 0 : 30);

            $status = match (true) {
                $index === 9, $index === 15 => BookingStatus::Pending,
                $index === 4, $index === 12 => BookingStatus::Canceled,
                default => BookingStatus::Confirmed,
            };

            $booking = Booking::create([
                'event_type_id' => $eventType->id,
                'team_id' => $eventType->team_id,
                'user_id' => $host->id,
                'status' => $status,
                'starts_at' => $startsAt->utc(),
                'ends_at' => $startsAt->addMinutes($eventType->duration_minutes)->utc(),
                'invitee_timezone' => self::TIMEZONE,
                'name' => $invitee,
                'email' => 'demo+'.Str::slug($invitee, '.').'@example.com',
                'location_type' => $eventType->location_type,
                'notes' => $index % 3 === 0 ? 'Looking for a two bedroom near downtown.' : null,
                'canceled_at' => $status === BookingStatus::Canceled ? $startsAt->subDay()->utc() : null,
                'canceled_by' => $status === BookingStatus::Canceled ? 'invitee' : null,
                'cancellation_reason' => $status === BookingStatus::Canceled ? 'Found another place.' : null,
            ]);

            $booking->hosts()->attach($host);
        }
    }
}
