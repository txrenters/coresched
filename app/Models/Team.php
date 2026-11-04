<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueTeamSlugs;
use App\Enums\TeamPlan;
use App\Enums\TeamRole;
use App\Services\Sms\TwilioClient;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $logo_path
 * @property string $timezone
 * @property string|null $sms_from_number
 * @property string|null $twilio_account_sid
 * @property string|null $twilio_auth_token
 * @property string|null $welcome_message
 * @property string|null $website_url
 * @property TeamPlan|null $plan
 * @property string|null $plan_note
 * @property string|null $mail_from_address
 * @property Carbon|null $disabled_at
 * @property string|null $disabled_reason
 * @property-read string|null $logo_url
 * @property-read int|null $calendar_members_count From OrganizationSummary::withCounts().
 * @property-read string|null $bookings_max_created_at From OrganizationSummary::withCounts().
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, TeamInvitation> $invitations
 * @property-read Collection<int, Membership> $memberships
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, EventType> $eventTypes
 * @property-read Collection<int, Group> $groups
 * @property-read Collection<int, Automation> $automations
 * @property-read Collection<int, Booking> $bookings
 */
#[Fillable(['name', 'slug', 'logo_path', 'timezone', 'sms_from_number', 'twilio_account_sid', 'twilio_auth_token', 'welcome_message', 'website_url', 'plan', 'plan_note', 'mail_from_address', 'disabled_at', 'disabled_reason'])]
/*
 * Hidden because the signed-in user is shared with every page and carries
 * their current organization along with it: Twilio credentials and the super
 * admin's plan, note and sender choice must never reach an organization's
 * members that way. Pages that need them build them explicitly.
 */
#[Hidden(['twilio_account_sid', 'twilio_auth_token', 'plan', 'plan_note', 'mail_from_address', 'disabled_reason'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use GeneratesUniqueTeamSlugs, HasFactory, SoftDeletes;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Team $team) {
            if (empty($team->slug)) {
                $team->slug = static::generateUniqueTeamSlug($team->name);
            }

            if (empty($team->timezone)) {
                $team->timezone = config('scheduling.default_timezone');
            }
        });

        static::updating(function (Team $team) {
            if ($team->isDirty('name')) {
                $team->slug = static::generateUniqueTeamSlug($team->name, $team->id);
            }
        });
    }

    /**
     * Get the public URL of the organization logo, if one has been uploaded.
     */
    public function logoUrl(): ?string
    {
        return $this->logo_path === null
            ? null
            : Storage::disk('public')->url($this->logo_path);
    }

    /**
     * Get the team owner.
     */
    public function owner(): ?Model
    {
        return $this->members()
            ->wherePivot('role', TeamRole::Admin->value)
            ->first();
    }

    /**
     * Get all members of this team.
     *
     * @return BelongsToMany<User, $this, Membership, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members', 'team_id', 'user_id')
            ->using(Membership::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Get all memberships for this team.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get all invitations for this team.
     *
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * Get the schedules shared across the organization.
     *
     * @return HasMany<AvailabilitySchedule, $this>
     */
    public function availabilitySchedules(): HasMany
    {
        return $this->hasMany(AvailabilitySchedule::class);
    }

    /**
     * Get the event types that belong to this team.
     *
     * @return HasMany<EventType, $this>
     */
    public function eventTypes(): HasMany
    {
        return $this->hasMany(EventType::class);
    }

    /**
     * Get the workflows that fire on this team's bookings.
     *
     * @return HasMany<Automation, $this>
     */
    public function automations(): HasMany
    {
        return $this->hasMany(Automation::class);
    }

    /**
     * Get the member groups defined for this team.
     *
     * @return HasMany<Group, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    /**
     * Get the public pages listing a chosen set of this team's groups.
     *
     * @return HasMany<DepartmentPage, $this>
     */
    public function departmentPages(): HasMany
    {
        return $this->hasMany(DepartmentPage::class);
    }

    /**
     * Get the bookings made against this team's event types.
     *
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'twilio_auth_token' => 'encrypted',
            'plan' => TeamPlan::class,
            'disabled_at' => 'datetime',
        ];
    }

    /**
     * Determine whether a super admin has disabled the organization.
     */
    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * Scope to organizations that are not disabled.
     *
     * @param  Builder<Team>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('disabled_at');
    }

    /**
     * Determine whether this organization has its own Twilio account set up.
     *
     * Without one its workflows cannot text anybody: there is deliberately no
     * installation-wide account to fall back on.
     */
    public function hasTwilio(): bool
    {
        return filled($this->twilio_account_sid) && filled($this->twilio_auth_token);
    }

    /**
     * The address this organization's booking mail is sent from.
     *
     * Its own sender only while that address is still in mail.org_senders:
     * removing one from the list puts every organization using it back on the
     * platform address, rather than sending through a mailbox the mailer is no
     * longer allowed to use.
     */
    public function bookingMailFrom(): string
    {
        $senders = (array) config('mail.org_senders');

        if (filled($this->mail_from_address) && in_array($this->mail_from_address, $senders, true)) {
            return $this->mail_from_address;
        }

        return (string) config('mail.from.address');
    }

    /**
     * Get a Twilio client signed in as this organization's own account.
     */
    public function twilio(): TwilioClient
    {
        return new TwilioClient($this->twilio_account_sid, $this->twilio_auth_token);
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
