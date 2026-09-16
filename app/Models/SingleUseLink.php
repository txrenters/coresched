<?php

namespace App\Models;

use Database\Factories\SingleUseLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A private booking link to one event type that stops working once used.
 *
 * @property int $id
 * @property string $uid
 * @property int $team_id
 * @property int $event_type_id
 * @property int|null $created_by
 * @property int|null $booking_id
 * @property Carbon|null $used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read EventType|null $eventType
 * @property-read User|null $creator
 * @property-read Booking|null $booking
 */
#[Fillable(['uid', 'team_id', 'event_type_id', 'created_by', 'booking_id', 'used_at'])]
class SingleUseLink extends Model
{
    /** @use HasFactory<SingleUseLinkFactory> */
    use HasFactory;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (SingleUseLink $link) {
            if (empty($link->uid)) {
                $link->uid = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the event type the link books.
     *
     * Excludes a deleted event type, so a link to one stops working.
     *
     * @return BelongsTo<EventType, $this>
     */
    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    /**
     * Get the person who made the link.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the booking made with the link.
     *
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Get the public address of the link.
     */
    public function url(): string
    {
        return route('book.once', ['singleUseLink' => $this->uid]);
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'uid';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
        ];
    }
}
