<?php

namespace App\Models;

use Database\Factories\TeamClosureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Dates an organization blocks for everyone, such as a company outing:
 * nobody in it can be booked on any of its event types between the two days.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 */
#[Fillable(['team_id', 'name', 'starts_on', 'ends_on'])]
class TeamClosure extends Model
{
    /** @use HasFactory<TeamClosureFactory> */
    use HasFactory;

    /**
     * Get the organization the closure belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }
}
