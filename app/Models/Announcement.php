<?php

namespace App\Models;

use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A message a super admin sends to everyone on the platform, such as a move
 * to a new address. It lands in each person's notification bell, and while
 * banner_until is ahead it also shows as a banner across the app.
 *
 * @property int $id
 * @property string $title
 * @property string $body
 * @property Carbon|null $banner_until
 * @property int $recipients_count
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $author
 */
#[Fillable(['title', 'body', 'banner_until', 'recipients_count', 'created_by'])]
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'banner_until' => 'datetime',
        ];
    }

    /**
     * Get the super admin who sent the announcement.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope to announcements whose banner is still showing.
     *
     * @param  Builder<Announcement>  $query
     */
    public function scopeWithLiveBanner(Builder $query): void
    {
        $query->where('banner_until', '>', now());
    }

    /**
     * Determine whether the banner is still showing.
     */
    public function hasLiveBanner(): bool
    {
        return $this->banner_until !== null && $this->banner_until->isFuture();
    }
}
