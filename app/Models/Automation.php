<?php

namespace App\Models;

use App\Enums\AutomationChannel;
use App\Enums\AutomationRecipient;
use App\Enums\AutomationTrigger;
use Carbon\CarbonInterface;
use Database\Factories\AutomationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A rule that sends an email when something happens to a booking.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property AutomationTrigger $trigger
 * @property int|null $offset_minutes
 * @property AutomationRecipient $recipient
 * @property array<int, string>|null $recipient_emails
 * @property array<int, string>|null $recipient_phones
 * @property \Illuminate\Support\Collection<int, AutomationChannel> $channels
 * @property string|null $subject
 * @property string|null $body
 * @property string|null $sms_body
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, EventType> $eventTypes
 * @property-read Collection<int, AutomationRun> $runs
 */
#[Fillable([
    'team_id', 'name', 'trigger', 'offset_minutes', 'recipient', 'channels',
    'recipient_emails', 'recipient_phones', 'subject', 'body', 'sms_body', 'is_active',
])]
class Automation extends Model
{
    /** @use HasFactory<AutomationFactory> */
    use HasFactory;

    /**
     * The model's default attribute values.
     *
     * Email is what a workflow does unless it says otherwise, so a rule
     * written without naming its channels still has one to send over.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'channels' => '["email"]',
    ];

    /**
     * Get the organization the automation belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the event types the automation is limited to.
     *
     * None means every event type in the organization.
     *
     * @return BelongsToMany<EventType, $this>
     */
    public function eventTypes(): BelongsToMany
    {
        return $this->belongsToMany(EventType::class, 'automation_event_type');
    }

    /**
     * Get the sends this automation has queued.
     *
     * @return HasMany<AutomationRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    /**
     * Scope the query to the automations that are switched on.
     *
     * @param  Builder<Automation>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Determine whether the automation covers the given event type.
     */
    public function covers(EventType $eventType): bool
    {
        return $this->eventTypes->isEmpty()
            || $this->eventTypes->contains('id', $eventType->id);
    }

    /**
     * Say when this automation fires, the way the listing puts it.
     */
    public function describeWhen(): string
    {
        return match ($this->trigger) {
            AutomationTrigger::Booked => $this->offset_minutes > 0
                ? $this->describeOffset().' after it is booked'
                : 'When a meeting is booked',
            AutomationTrigger::BeforeStart => $this->describeOffset().' before it starts',
            AutomationTrigger::AfterEnd => $this->describeOffset().' after it ends',
        };
    }

    /**
     * Determine whether this automation sends over the given channel.
     */
    public function sends(AutomationChannel $channel): bool
    {
        return $this->channels->contains($channel);
    }

    /**
     * Say what this automation does, the way the listing puts it.
     */
    public function describeAction(): string
    {
        $recipient = match ($this->recipient) {
            AutomationRecipient::Host => 'the hosts',
            AutomationRecipient::Invitee => 'the invitee',
            AutomationRecipient::Someone => $this->describeSomeone(),
        };

        return $this->describeChannels().' '.$recipient;
    }

    /**
     * Say which channels are in play: "Email", "Text", or both.
     */
    protected function describeChannels(): string
    {
        $labels = $this->channels
            ->map(fn (AutomationChannel $channel) => $channel->shortLabel())
            ->all();

        return $labels === [] ? 'Send nothing to' : implode(' and ', $labels);
    }

    /**
     * Name who "someone else" is.
     *
     * With one channel the actual list is worth showing; with both there are
     * two lists, and the listing is not the place to unpack them.
     */
    protected function describeSomeone(): string
    {
        if ($this->channels->count() !== 1) {
            return 'someone else';
        }

        $recipients = $this->sends(AutomationChannel::Sms)
            ? $this->recipient_phones
            : $this->recipient_emails;

        return $this->describeList($recipients ?? []);
    }

    /**
     * Name a list of recipients without letting a long one run away with the
     * listing.
     *
     * @param  array<int, string>  $recipients
     */
    protected function describeList(array $recipients): string
    {
        return match (true) {
            $recipients === [] => 'someone else',
            count($recipients) === 1 => $recipients[0],
            count($recipients) === 2 => $recipients[0].' and 1 other',
            default => $recipients[0].' and '.(count($recipients) - 1).' others',
        };
    }

    /**
     * Put the offset in the largest unit it divides into cleanly, so 1440
     * minutes reads as "1 day" rather than a number nobody typed.
     */
    public function describeOffset(): string
    {
        $minutes = (int) $this->offset_minutes;

        [$count, $unit] = match (true) {
            $minutes >= 1440 && $minutes % 1440 === 0 => [intdiv($minutes, 1440), 'day'],
            $minutes >= 60 && $minutes % 60 === 0 => [intdiv($minutes, 60), 'hour'],
            default => [$minutes, 'minute'],
        };

        return $count.' '.str($unit)->plural($count)->toString();
    }

    /**
     * Work out when this automation should fire for a booking, or null when
     * that moment has already passed.
     */
    public function sendAtFor(Booking $booking): ?CarbonInterface
    {
        $sendAt = match ($this->trigger) {
            // A delay after booking is allowed: "24 hours after it is booked".
            AutomationTrigger::Booked => now()->addMinutes($this->offset_minutes ?? 0),
            AutomationTrigger::BeforeStart => $booking->starts_at->copy()->subMinutes($this->offset_minutes ?? 0),
            AutomationTrigger::AfterEnd => $booking->ends_at->copy()->addMinutes($this->offset_minutes ?? 0),
        };

        // A meeting booked for later today can already be past its "an hour
        // before" moment; there is nothing sensible to send then.
        return $sendAt->isPast() && $this->trigger !== AutomationTrigger::Booked
            ? null
            : $sendAt;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => AutomationTrigger::class,
            'recipient' => AutomationRecipient::class,
            'channels' => AsEnumCollection::class.':'.AutomationChannel::class,
            'recipient_emails' => 'array',
            'recipient_phones' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
