<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\EventType;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EnsureEventTypeHost
{
    public function __construct(private CreateTeamUser $createTeamUser)
    {
        //
    }

    /**
     * Put somebody on an event type's rota, making their account if needed.
     *
     * Another application (TEXRA) calls this for an agent it knows by email.
     * An address that already has an account is used exactly as it is: no
     * rename, no new timezone, and never a second account, because a
     * duplicate would split one person's bookings and hours in two. Only a
     * new address gets an account, a seat in the organization, default
     * hours in the timezone given, and the welcome email.
     *
     * Either way the person ends up in the event type's host pool: its group
     * when it hosts from one, otherwise pinned as a host. Calling this twice
     * changes nothing the second time.
     *
     * @return array{user: User, created: bool}
     */
    public function handle(Team $team, EventType $eventType, string $name, string $email, ?string $timezone = null): array
    {
        $email = mb_strtolower(trim($email));

        return DB::transaction(function () use ($team, $eventType, $name, $email, $timezone) {
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
            $created = $user === null;

            if ($user === null) {
                $user = $this->createTeamUser->handle($team, $name, $email, TeamRole::Member);

                if ($timezone !== null) {
                    $user->forceFill(['timezone' => $timezone])->save();
                    $user->availabilitySchedules()->update(['timezone' => $timezone]);
                }
            } elseif (! $user->belongsToTeam($team)) {
                $team->memberships()->create([
                    'user_id' => $user->id,
                    'role' => TeamRole::Member,
                ]);
            }

            $this->addToPool($eventType, $user);

            return ['user' => $user, 'created' => $created];
        });
    }

    /**
     * Join the pool the way the settings screens would: at the back of the
     * group's order, or pinned on the event type when it has no group.
     */
    private function addToPool(EventType $eventType, User $user): void
    {
        $group = $eventType->group;

        if ($group !== null) {
            if (! $group->members()->whereKey($user->id)->exists()) {
                $next = (int) $group->members()->max('group_members.priority') + 1;
                $group->members()->attach($user->id, ['priority' => $next]);
            }

            return;
        }

        if (! $eventType->hosts()->whereKey($user->id)->exists()) {
            $next = (int) $eventType->hosts()->max('priority') + 1;
            $eventType->hosts()->attach($user->id, ['priority' => $next]);
        }
    }
}
