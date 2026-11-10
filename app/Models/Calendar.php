<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $calendar_account_id
 * @property string $external_id
 * @property string $name
 * @property string|null $timezone
 * @property bool $is_primary
 * @property bool $checks_conflicts
 * @property bool $is_write_target
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CalendarAccount $account
 * @property-read Collection<int, BusyBlock> $busyBlocks
 */
#[Fillable([
    'calendar_account_id', 'external_id', 'name', 'timezone',
    'is_primary', 'checks_conflicts', 'is_write_target',
])]
class Calendar extends Model
{
    /**
     * Get the account the calendar belongs to.
     *
     * @return BelongsTo<CalendarAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CalendarAccount::class, 'calendar_account_id');
    }

    /**
     * Get the busy blocks synced from the calendar.
     *
     * @return HasMany<BusyBlock, $this>
     */
    public function busyBlocks(): HasMany
    {
        return $this->hasMany(BusyBlock::class);
    }

    /**
     * Determine if this is a provider's public holiday calendar, such as
     * Outlook's "United States holidays" or Google's "Holidays in United
     * States".
     *
     * Holidays are the organization's call (its advanced settings), so these
     * calendars never block time: otherwise a holiday the organization keeps
     * open would still close the day for anyone who subscribed to one.
     */
    public function isHolidayCalendar(): bool
    {
        if (str_contains($this->external_id, '#holiday@group.v.calendar.google.com')) {
            return true;
        }

        return ! $this->is_primary && preg_match('/\bholidays\b/i', $this->name) === 1;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'checks_conflicts' => 'boolean',
            'is_write_target' => 'boolean',
        ];
    }
}
