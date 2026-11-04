<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\HasScheduling;
use App\Concerns\HasTeams;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $avatar_path
 * @property bool $is_super_admin
 * @property string|null $booking_slug
 * @property string $timezone
 * @property string|null $holiday_country
 * @property string|null $welcome_message
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $currentTeam
 * @property-read Collection<int, Team> $ownedTeams
 * @property-read Collection<int, Membership> $teamMemberships
 * @property-read Collection<int, Team> $teams
 * @property-read Collection<int, AvailabilitySchedule> $availabilitySchedules
 * @property-read Collection<int, EventType> $eventTypes
 * @property-read Collection<int, Booking> $bookings
 * @property-read Collection<int, CalendarAccount> $calendarAccounts
 * @property-read Pivot|null $pivot Present when fetched through a belongsToMany relation.
 */
#[Fillable(['name', 'email', 'phone', 'password', 'is_super_admin', 'current_team_id', 'booking_slug', 'timezone', 'welcome_message', 'holiday_country'])]
#[Appends(['avatar'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasScheduling, HasTeams, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (User $user) {
            if (empty($user->booking_slug)) {
                $user->booking_slug = static::generateUniqueBookingSlug($user->name);
            }

            if (empty($user->timezone)) {
                $user->timezone = config('scheduling.default_timezone');
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * Determine whether this account can see and act across every organization.
     *
     * A super admin is not a member of every organization — they bypass the
     * membership check instead, so nothing about org rosters changes.
     */
    /**
     * Get the URL of the user's photo, if they have uploaded one.
     */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path === null
            ? null
            : Storage::disk('public')->url($this->avatar_path);
    }

    /**
     * Expose the photo's URL wherever the user is serialised, which is how the
     * front end's `user.avatar` has always been typed.
     */
    public function getAvatarAttribute(): ?string
    {
        return $this->avatarUrl();
    }

    /**
     * Scope to people who use the platform for real: not super admins, and
     * not the public demo's sample people. Someone in no organization at
     * all still counts.
     *
     * @param  Builder<User>  $query
     */
    public function scopeRealUsers(Builder $query): void
    {
        $query->where('is_super_admin', false)
            ->where(fn (Builder $query) => $query
                ->whereDoesntHave('teams')
                ->orWhereHas('teams', fn (Builder $teams) => $teams->where('is_demo', false)));
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }
}
