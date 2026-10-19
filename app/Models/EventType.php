<?php

namespace App\Models;

use App\Enums\DateRangeType;
use App\Enums\EventTypeKind;
use App\Enums\LocationType;
use App\Enums\TeamPermission;
use Database\Factories\EventTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property int|null $group_id
 * @property int|null $availability_schedule_id
 * @property EventTypeKind $kind
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $color
 * @property int $duration_minutes
 * @property int|null $slot_interval_minutes
 * @property int $buffer_before_minutes
 * @property int $buffer_after_minutes
 * @property int $minimum_notice_minutes
 * @property int|null $daily_booking_limit
 * @property int $seats_per_slot
 * @property DateRangeType $date_range_type
 * @property int $rolling_days
 * @property Carbon|null $range_starts_on
 * @property Carbon|null $range_ends_on
 * @property LocationType $location_type
 * @property string|null $location_detail
 * @property bool $is_active
 * @property bool $is_hidden
 * @property bool $requires_confirmation
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Team $team
 * @property-read User $owner
 * @property-read AvailabilitySchedule|null $availabilitySchedule
 * @property-read Group|null $group
 * @property-read Collection<int, User> $hosts
 * @property-read Collection<int, EventTypeQuestion> $questions
 * @property-read Collection<int, Booking> $bookings
 */
#[Fillable([
    'team_id', 'user_id', 'group_id', 'availability_schedule_id', 'kind', 'name', 'slug', 'description', 'color',
    'duration_minutes', 'slot_interval_minutes', 'buffer_before_minutes', 'buffer_after_minutes',
    'minimum_notice_minutes', 'daily_booking_limit', 'seats_per_slot', 'date_range_type', 'rolling_days',
    'range_starts_on', 'range_ends_on', 'location_type', 'location_detail', 'is_active', 'is_hidden',
    'requires_confirmation',
])]
class EventType extends Model
{
    /** @use HasFactory<EventTypeFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => 'one_on_one',
        'color' => '#0f766e',
        'duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'minimum_notice_minutes' => 0,
        'seats_per_slot' => 1,
        'date_range_type' => 'rolling_days',
        'rolling_days' => 60,
        'location_type' => 'microsoft_teams',
        'is_active' => true,
        'is_hidden' => false,
        'requires_confirmation' => false,
    ];

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        /*
         * Release the slug when an event type is soft deleted.
         *
         * The unique index is on (team_id, slug) alone, while
         * SaveEventTypeRequest scopes its uniqueness rule with
         * whereNull('deleted_at') -- so a deleted event type is invisible to
         * validation but still occupies the index. Creating another with the
         * same name then passed validation and died on INSERT with a 1062,
         * surfacing as a bare 500 on save.
         *
         * Renaming here honours what the validation rule already intends:
         * deleting an event type frees its name for reuse. The row keeps its id,
         * so bookings that reference it are unaffected, and its public URL is
         * dead either way once deleted.
         */
        static::deleting(function (EventType $eventType) {
            if ($eventType->isForceDeleting()) {
                return;
            }

            $eventType->slug = Str::limit($eventType->slug, 200, '').'-deleted-'.$eventType->id;
            $eventType->saveQuietly();
        });
    }

    /**
     * Get the team the event type belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user that owns the event type.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the schedule used when a host has no dedicated schedule.
     *
     * @return BelongsTo<AvailabilitySchedule, $this>
     */
    public function availabilitySchedule(): BelongsTo
    {
        return $this->belongsTo(AvailabilitySchedule::class);
    }

    /**
     * Get the group this event type hosts from, if any.
     *
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Get the hosts pinned directly to this event type.
     *
     * @return BelongsToMany<User, $this>
     */
    public function hosts(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_type_hosts')
            ->withPivot(['availability_schedule_id', 'priority'])
            ->withTimestamps()
            ->orderByPivot('priority');
    }

    /**
     * Get the custom booking questions.
     *
     * @return HasMany<EventTypeQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(EventTypeQuestion::class)->orderBy('position');
    }

    /**
     * Get the bookings made against the event type.
     *
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Scope the query to event types that can be booked publicly.
     *
     * @param  Builder<EventType>  $query
     */
    public function scopeBookable(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Get the pool of people who can host this event type.
     *
     * A group wins when one is set, so adding someone to the group adds them
     * to every event type hosting from it. Otherwise the pinned hosts are
     * used, falling back to the owner alone.
     *
     * @return Collection<int, User>
     */
    public function hostPool(): Collection
    {
        if (! $this->kind->hasHostPool()) {
            return new Collection([$this->owner]);
        }

        $group = $this->group;

        if ($group !== null) {
            $members = $group->members;

            if ($members->isNotEmpty()) {
                return $members;
            }
        }

        $hosts = $this->hosts;

        return $hosts->isEmpty() ? new Collection([$this->owner]) : $hosts;
    }

    /**
     * Get the single-use links made for this event type.
     *
     * @return HasMany<SingleUseLink, $this>
     */
    public function singleUseLinks(): HasMany
    {
        return $this->hasMany(SingleUseLink::class);
    }

    /**
     * Get the hosts someone booking on an invitee's behalf may pick between.
     *
     * Only a pooled event type that one host takes offers a choice; a
     * one-on-one belongs to its owner and a collective event needs everyone.
     *
     * @return Collection<int, User>
     */
    public function assignableHosts(): Collection
    {
        if (! $this->kind->hasHostPool() || $this->kind->requiresEveryHost()) {
            return new Collection;
        }

        return $this->hostPool()->values();
    }

    /**
     * Determine if a signed-in user may book this event type for someone else.
     *
     * Anyone who could host it, or who manages the organization's bookings.
     */
    public function canBeBookedOnBehalfBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        /*
         * Permission first: a super admin belongs to no organization but has
         * every permission, and a membership check ahead of it shut them out.
         */
        if ($user->hasTeamPermission($this->team, TeamPermission::ManageTeamBookings)) {
            return true;
        }

        return $user->belongsToTeam($this->team)
            && $this->hostPool()->contains('id', $user->id);
    }

    /**
     * Get the public page this event type is booked on.
     */
    public function publicUrl(): string
    {
        $page = $this->kind->hasHostPool()
            ? $this->team->slug
            : ($this->owner->booking_slug ?? $this->team->slug);

        return route('book.event-type', ['page' => $page, 'eventType' => $this->slug]);
    }

    /**
     * Get the users whose calendars gate this event type's availability.
     *
     * With a zone, only the hosts in the pool who list that zone: the
     * invitee's link named where they are, and they should be booked with
     * someone who covers it. A zone nobody in the pool lists narrows nothing,
     * so a new area, or one the hosts have not set up yet, still books with
     * the whole pool rather than with nobody. This is the one place a zone
     * narrows the pool; everything that offers or assigns hosts comes
     * through here.
     *
     * @return Collection<int, User>
     */
    /**
     * A host on this call's rota by the email the sending application knows
     * them by, so a link can pin a booking to them; null when nobody on the
     * rota has that address.
     */
    public function hostByEmail(?string $email): ?User
    {
        $email = mb_strtolower(trim((string) $email));

        if ($email === '') {
            return null;
        }

        return $this->hostPool()->first(fn (User $host): bool => mb_strtolower((string) $host->email) === $email);
    }

    public function schedulingHosts(?string $zone = null): Collection
    {
        $pool = $this->hostPool();

        if ($zone === null || ! $this->kind->hasHostPool()) {
            return $pool;
        }

        $inZone = $this->hostIdsInZone($zone);

        if ($inZone === []) {
            return $pool;
        }

        return $pool->filter(fn (User $host): bool => in_array($host->id, $inZone, true))->values();
    }

    /**
     * Determine whether anybody in the host pool lists the zone.
     */
    public function poolCoversZone(string $zone): bool
    {
        return $this->hostIdsInZone($zone) !== [];
    }

    /**
     * The hosts in the pool who list the zone.
     *
     * @return list<int>
     */
    private function hostIdsInZone(string $zone): array
    {
        return array_values(HostZone::query()
            ->where('team_id', $this->team_id)
            ->where('zone', $zone)
            ->whereIn('user_id', $this->hostPool()->modelKeys())
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all());
    }

    /**
     * Get the interval between offered start times, in minutes.
     */
    public function slotInterval(): int
    {
        return $this->slot_interval_minutes ?: $this->duration_minutes;
    }

    /**
     * Get the number of seats available for each slot.
     */
    public function seats(): int
    {
        return $this->kind->allowsMultipleInvitees() ? max(1, $this->seats_per_slot) : 1;
    }

    /**
     * Get a slug that is free within the team, suffixing past any taken one.
     *
     * The database unique index on (team_id, slug) counts soft-deleted rows,
     * so trashed event types are collisions too even though validation
     * ignores them.
     */
    public static function generateUniqueSlug(string $slug, int $teamId, ?int $ignoreId = null): string
    {
        $base = $slug;
        $suffix = 1;

        while (static::withTrashed()
            ->where('team_id', $teamId)
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = "{$base}-".(++$suffix);
        }

        return $slug;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => EventTypeKind::class,
            'date_range_type' => DateRangeType::class,
            'location_type' => LocationType::class,
            'range_starts_on' => 'date',
            'range_ends_on' => 'date',
            'is_active' => 'boolean',
            'is_hidden' => 'boolean',
            'requires_confirmation' => 'boolean',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
