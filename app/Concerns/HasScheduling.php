<?php

namespace App\Concerns;

use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\CalendarAccount;
use App\Models\EventType;
use App\Models\Group;
use App\Models\HostZone;
use App\Models\LeavePeriod;
use App\Models\MeetingLimit;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait HasScheduling
{
    /**
     * Get the availability schedules owned by the user.
     *
     * @return HasMany<AvailabilitySchedule, $this>
     */
    public function availabilitySchedules(): HasMany
    {
        return $this->hasMany(AvailabilitySchedule::class);
    }

    /**
     * Get the event types owned by the user.
     *
     * @return HasMany<EventType, $this>
     */
    public function eventTypes(): HasMany
    {
        return $this->hasMany(EventType::class);
    }

    /**
     * Get the event types the user is a pooled host on.
     *
     * @return BelongsToMany<EventType, $this>
     */
    public function hostedEventTypes(): BelongsToMany
    {
        return $this->belongsToMany(EventType::class, 'event_type_hosts')
            ->withPivot(['availability_schedule_id', 'priority'])
            ->withTimestamps();
    }

    /**
     * Get the bookings where the user is the primary host.
     *
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Get the connected calendar accounts.
     *
     * @return HasMany<CalendarAccount, $this>
     */
    public function calendarAccounts(): HasMany
    {
        return $this->hasMany(CalendarAccount::class);
    }

    /**
     * Get the stretches of leave the user is away for.
     *
     * @return HasMany<LeavePeriod, $this>
     */
    public function leavePeriods(): HasMany
    {
        return $this->hasMany(LeavePeriod::class);
    }

    /**
     * Determine whether the user is away at the given moment.
     */
    public function isOnLeave(?CarbonImmutable $moment = null): bool
    {
        return $this->leavePeriods()
            ->covering($moment ?? CarbonImmutable::now())
            ->exists();
    }

    /**
     * Get the teams (the Group model) the user hosts with.
     *
     * @return BelongsToMany<Group, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_members')
            ->withPivot(['priority'])
            ->withTimestamps();
    }

    /**
     * Get the caps on how many meetings the user will take.
     *
     * @return HasMany<MeetingLimit, $this>
     */
    public function meetingLimits(): HasMany
    {
        return $this->hasMany(MeetingLimit::class);
    }

    /**
     * Get the areas the user takes meetings for, across their organizations.
     *
     * @return HasMany<HostZone, $this>
     */
    public function hostZones(): HasMany
    {
        return $this->hasMany(HostZone::class);
    }

    /**
     * Get the holidays the user is marked unavailable on.
     *
     * @return array<int, string>
     */
    public function enabledHolidays(): array
    {
        return DB::table('user_holidays')
            ->where('user_id', $this->id)
            ->pluck('holiday_key')
            ->all();
    }

    /**
     * Get the user's default availability schedule.
     */
    public function defaultAvailabilitySchedule(): ?AvailabilitySchedule
    {
        return $this->availabilitySchedules()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    /**
     * Generate a unique public booking slug from the given name.
     *
     * People and organizations share one /book/{slug} namespace, and the
     * resolver checks people first — so a personal slug that collides with an
     * organization slug would make that organization's page unreachable. Both
     * tables are checked here for that reason.
     */
    public static function generateUniqueBookingSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'user';
        $slug = $base;
        $suffix = 1;

        while (static::bookingSlugTaken($slug, $ignoreId)) {
            $slug = "{$base}-".(++$suffix);
        }

        return $slug;
    }

    /**
     * Determine whether a public booking slug is already spoken for.
     */
    protected static function bookingSlugTaken(string $slug, ?int $ignoreId = null): bool
    {
        $takenByPerson = static::query()
            ->where('booking_slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        return $takenByPerson || Team::withTrashed()->where('slug', $slug)->exists();
    }
}
