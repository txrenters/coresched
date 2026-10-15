<?php

namespace App\Models;

use App\Actions\Bookings\NotifyBookingParties;
use App\Enums\BookingStatus;
use App\Enums\LocationType;
use App\Enums\QuestionType;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uid
 * @property int $event_type_id
 * @property int $team_id
 * @property int $user_id
 * @property BookingStatus $status
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $invitee_timezone
 * @property string $name
 * @property string $email
 * @property string|null $notes
 * @property string|null $reference
 * @property string|null $zone
 * @property int $webhook_sequence
 * @property string|null $host_notes
 * @property LocationType $location_type
 * @property string|null $location_detail
 * @property string|null $meeting_url
 * @property int|null $rescheduled_from_id
 * @property string|null $cancellation_reason
 * @property string|null $canceled_by
 * @property Carbon|null $canceled_at
 * @property Carbon|null $no_show_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read EventType $eventType
 * @property-read Team $team
 * @property-read User $host
 * @property-read Booking|null $rescheduledFrom
 * @property-read Collection<int, User> $hosts
 * @property-read Collection<int, BookingGuest> $guests
 * @property-read Collection<int, BookingAnswer> $answers
 * @property-read Collection<int, BookingReminder> $reminders
 * @property-read Collection<int, BookingCalendarEvent> $calendarEvents
 */
#[Fillable([
    'uid', 'event_type_id', 'team_id', 'user_id', 'status', 'starts_at', 'ends_at', 'invitee_timezone',
    'name', 'email', 'notes', 'reference', 'zone', 'webhook_sequence', 'host_notes', 'location_type', 'location_detail',
    'meeting_url', 'rescheduled_from_id', 'cancellation_reason', 'canceled_by', 'canceled_at', 'no_show_at',
])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    /**
     * Every saved change is offered to the webhook, whoever made it; the
     * webhook decides whether it concerns anyone outside.
     */
    protected static function booted(): void
    {
        static::updated(function (Booking $booking): void {
            app(NotifyBookingParties::class)->changed($booking, array_keys($booking->getChanges()));
        });
    }

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'confirmed',
    ];

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Booking $booking) {
            if (empty($booking->uid)) {
                $booking->uid = (string) Str::uuid();
            }

            if (empty($booking->invitee_timezone)) {
                $booking->invitee_timezone = config('scheduling.default_timezone');
            }
        });
    }

    /**
     * Get the event type that was booked.
     *
     * Includes a soft-deleted event type: deleting one leaves its bookings in
     * place, and they still need its name and buffers to be shown, reminded
     * about and counted as busy time.
     *
     * @return BelongsTo<EventType, $this>
     */
    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class)->withTrashed();
    }

    /**
     * Get the team the booking belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the primary host of the booking.
     *
     * @return BelongsTo<User, $this>
     */
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the booking this one replaced.
     *
     * @return BelongsTo<Booking, $this>
     */
    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'rescheduled_from_id');
    }

    /**
     * Get every host attending the booking.
     *
     * @return BelongsToMany<User, $this>
     */
    public function hosts(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'booking_hosts')->withTimestamps();
    }

    /**
     * Get every host who should hear about the booking or hold it on a calendar.
     *
     * One-on-one bookings predate the booking_hosts pivot and collective ones
     * fill it, so fall back to the primary host when the pivot is empty. Every
     * caller that fans out to hosts must go through here rather than repeating
     * the fallback, which is how reminders drifted out of sync.
     *
     * @return Collection<int, User>
     */
    public function attendingHosts(): Collection
    {
        if ($this->hosts->isNotEmpty()) {
            return $this->hosts;
        }

        return new Collection(array_filter([$this->host]));
    }

    /**
     * Get the addresses of the invitee and every additional guest.
     *
     * @return SupportCollection<int, string>
     */
    public function inviteeEmails(): SupportCollection
    {
        return collect([$this->email])
            ->merge($this->guests->pluck('email'))
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * Get the invitee's phone number, from wherever it was collected.
     *
     * A phone question is the usual place, but an event type where the host
     * calls the invitee asks for the number as the location instead. Neither
     * is compulsory, so this is often null.
     */
    public function inviteePhone(): ?string
    {
        $answer = $this->answers
            ->first(fn (BookingAnswer $answer) => $answer->question?->type === QuestionType::Phone
                && filled($answer->answer));

        if ($answer !== null) {
            return $answer->answer;
        }

        return $this->location_type === LocationType::Phone
            ? $this->location_detail
            : null;
    }

    /**
     * Get the additional guests invited to the booking.
     *
     * @return HasMany<BookingGuest, $this>
     */
    public function guests(): HasMany
    {
        return $this->hasMany(BookingGuest::class);
    }

    /**
     * Get the answers to the event type's custom questions.
     *
     * @return HasMany<BookingAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(BookingAnswer::class);
    }

    /**
     * Get the automations queued against the booking.
     *
     * @return HasMany<AutomationRun, $this>
     */
    public function automationRuns(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    /**
     * Get the scheduled reminders for the booking.
     *
     * @return HasMany<BookingReminder, $this>
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(BookingReminder::class);
    }

    /**
     * Get the external calendar events created for the booking.
     *
     * @return HasMany<BookingCalendarEvent, $this>
     */
    public function calendarEvents(): HasMany
    {
        return $this->hasMany(BookingCalendarEvent::class);
    }

    /**
     * Scope the query to bookings that still occupy their slot.
     *
     * @param  Builder<Booking>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed]);
    }

    /**
     * Scope the query to bookings that have not started yet.
     *
     * @param  Builder<Booking>  $query
     */
    public function scopeUpcoming(Builder $query): void
    {
        $query->where('starts_at', '>=', now());
    }

    /**
     * Scope the query to bookings the given user hosts.
     *
     * A booking's host pool lives on the pivot, so a user can host a meeting
     * they do not own — check both.
     *
     * @param  Builder<Booking>  $query
     */
    public function scopeHostedBy(Builder $query, User $user): void
    {
        $query->where(fn ($inner) => $inner
            ->where('user_id', $user->id)
            ->orWhereHas('hosts', fn ($hosts) => $hosts->where('users.id', $user->id)));
    }

    /**
     * Scope the query to bookings with a host who is on leave when they fall.
     *
     * Leave only stops new bookings (it is merged into busy time), so a
     * meeting booked before the host set their auto-reply still sits with
     * them. This finds those.
     *
     * @param  Builder<Booking>  $query
     */
    public function scopeWithHostAway(Builder $query): void
    {
        $overlapping = fn (Builder $leave) => $leave
            ->whereColumn('leave_periods.starts_at', '<', 'bookings.ends_at')
            ->whereColumn('leave_periods.ends_at', '>', 'bookings.starts_at');

        $query->where(fn (Builder $inner) => $inner
            ->whereHas('hosts.leavePeriods', $overlapping)
            ->orWhere(fn (Builder $single) => $single
                ->whereDoesntHave('hosts')
                ->whereHas('host.leavePeriods', $overlapping)));
    }

    /**
     * Get the hosts who are on leave when this meeting takes place.
     *
     * Reads each host's loaded leavePeriods, so eager load them for a list.
     *
     * @return Collection<int, User>
     */
    public function awayHosts(): Collection
    {
        return $this->attendingHosts()
            ->filter(fn (User $host) => $host->leavePeriods->contains(
                fn (LeavePeriod $leave) => $leave->starts_at < $this->ends_at && $leave->ends_at > $this->starts_at,
            ))
            ->values();
    }

    /**
     * Determine if the booking can still be changed by the invitee.
     */
    public function isChangeable(): bool
    {
        return $this->status->isActive() && $this->starts_at->isFuture();
    }

    /**
     * Determine if the invitee can be recorded as not turning up.
     *
     * Only a confirmed meeting that has already started: before then nobody
     * can know, and a cancelled one was never going to happen.
     */
    public function canBeMarkedNoShow(): bool
    {
        return $this->status->isConfirmed() && $this->starts_at->isPast();
    }

    /**
     * Get the hosts this meeting could be handed to when it is rescheduled.
     *
     * @return Collection<int, User>
     */
    public function reassignableHosts(): Collection
    {
        return $this->eventType->assignableHosts();
    }

    /**
     * Get the host a viewer has chosen to hand this meeting to.
     *
     * Null when nobody was chosen, the viewer may not change the meeting, or
     * the chosen person is not in the pool.
     */
    public function chosenHostFor(?User $viewer, int $hostId): ?User
    {
        if ($hostId === 0 || $viewer === null || $viewer->cannot('cancel', $this)) {
            return null;
        }

        return $this->reassignableHosts()->firstWhere('id', $hostId);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'location_type' => LocationType::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'canceled_at' => 'datetime',
            'no_show_at' => 'datetime',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'uid';
    }
}
