<?php

namespace App\Services\Activity;

use App\Models\ActivityLog;
use App\Models\Team;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Throwable;

/**
 * Writes the organization audit trail.
 *
 * Recording is best effort: an audit row must never be the reason a booking or
 * a settings change fails, so a write that blows up is swallowed and reported
 * to the log rather than bubbling into the request.
 *
 * During an impersonation the trail still names the member, as the organization
 * sees it; who really did it goes to the super admins' impersonation log.
 */
class ActivityLogger
{
    /**
     * Record one thing that happened.
     *
     * @param  array<string, mixed>  $properties
     */
    public function record(
        Team $team,
        string $event,
        string $description,
        ?Model $subject = null,
        array $properties = [],
        ?User $actor = null,
    ): ?ActivityLog {
        try {
            $actor ??= Auth::user();

            $entry = ActivityLog::create([
                'team_id' => $team->id,
                'user_id' => $actor?->id,
                'event' => $event,
                'description' => $description,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'properties' => $properties === [] ? null : $properties,
                // Kept separately so the trail still reads correctly after the
                // account is renamed or deleted.
                'actor_name' => $actor?->name,
                'ip_address' => Request::ip(),
            ]);

            $this->attributeToImpersonator($entry);

            return $entry;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Note the super admin behind an entry made while signed in as a member.
     */
    private function attributeToImpersonator(ActivityLog $entry): void
    {
        $impersonator = Impersonation::impersonator(Request::instance());

        if ($impersonator === null) {
            return;
        }

        Log::channel('impersonation')->info('A super admin acted while signed in as a member.', [
            'admin_id' => $impersonator->id,
            'admin_name' => $impersonator->name,
            'member_id' => $entry->user_id,
            'member_name' => $entry->actor_name,
            'team_id' => $entry->team_id,
            'activity_log_id' => $entry->id,
            'event' => $entry->event,
            'description' => $entry->description,
            'ip_address' => $entry->ip_address,
        ]);
    }
}
