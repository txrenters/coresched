<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One area a host takes meetings for, within one organization.
 *
 * The zone is an opaque key agreed with the application that sends invitees
 * here: it writes a host's zones through the API and puts the invitee's zone
 * in the booking link. This app only matches the two; it never interprets
 * what a zone is.
 *
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property string $zone
 */
#[Fillable(['team_id', 'user_id', 'zone'])]
class HostZone extends Model
{
    /** What a zone key may look like, on a link and on a write alike. */
    public const PATTERN = '/^[a-z0-9-]{1,100}$/';

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Normalise a zone key from a link or a request; null when it is not one.
     */
    public static function normalise(?string $zone): ?string
    {
        $zone = mb_strtolower(trim((string) $zone));

        return preg_match(self::PATTERN, $zone) === 1 ? $zone : null;
    }
}
