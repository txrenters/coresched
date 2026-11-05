<?php

namespace App\Models;

use Database\Factories\PageVisitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One view of a public marketing page by a signed-out visitor, recorded by
 * RecordPageVisit and summed on the admin dashboard.
 *
 * Nothing here identifies a person: no IP address or user agent is stored.
 * visitor_hash is a SHA-256 of the day, the app key, the IP and the user
 * agent, so one visitor counts once per day and cannot be followed from one
 * day to the next. Rows are pruned after 13 months.
 *
 * @property int $id
 * @property string $path
 * @property string|null $referrer_host
 * @property string $visitor_hash
 * @property string $device
 * @property Carbon $created_at
 */
#[Fillable(['path', 'referrer_host', 'visitor_hash', 'device', 'created_at'])]
class PageVisit extends Model
{
    /** @use HasFactory<PageVisitFactory> */
    use HasFactory, MassPrunable;

    public const UPDATED_AT = null;

    /**
     * Get the prunable model query.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subMonths(13));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
