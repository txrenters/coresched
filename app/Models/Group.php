<?php

namespace App\Models;

use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A reusable pool of team members that event types can host from.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int|null $availability_schedule_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, EventType> $eventTypes
 * @property-read Collection<int, EventType> $pageEventTypes
 */
#[Fillable(['team_id', 'name', 'slug', 'description', 'availability_schedule_id'])]
class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (Group $group) {
            if (empty($group->slug) || $group->isDirty('name')) {
                $group->slug = static::generateUniqueSlug($group->name, $group->team_id, $group->id);
            }
        });
    }

    /**
     * Get the team the group belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the hours the team keeps, if it keeps its own.
     *
     * @return BelongsTo<AvailabilitySchedule, $this>
     */
    public function availabilitySchedule(): BelongsTo
    {
        return $this->belongsTo(AvailabilitySchedule::class);
    }

    /**
     * Get the members of the group, in the order they should be offered.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_members')
            ->withPivot(['priority'])
            ->withTimestamps()
            ->orderByPivot('priority');
    }

    /**
     * Get the event types hosting from this group.
     *
     * @return HasMany<EventType, $this>
     */
    public function eventTypes(): HasMany
    {
        return $this->hasMany(EventType::class);
    }

    /**
     * Get the event types picked to show on the team's public page.
     *
     * Listing only: unlike eventTypes(), this never changes who hosts them.
     *
     * @return BelongsToMany<EventType, $this>
     */
    public function pageEventTypes(): BelongsToMany
    {
        return $this->belongsToMany(EventType::class, 'group_page_event_types')
            ->withTimestamps();
    }

    /**
     * Build a slug that is unique inside the team.
     */
    public static function generateUniqueSlug(string $name, int $teamId, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'group';
        $slug = $base;
        $suffix = 1;

        while (static::query()
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
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
