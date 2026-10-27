<?php

namespace App\Services\Admin;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * What a super admin sees about one organization on the Organizations page:
 * its plan and sender, how far it has got with setup, and who they can sign
 * in as. Organization admins never receive any of this.
 */
class OrganizationSummary
{
    /**
     * Add the counts the summary reads to an organizations query.
     *
     * @param  Builder<Team>  $query
     * @return Builder<Team>
     */
    public static function withCounts(Builder $query): Builder
    {
        return $query
            ->withCount([
                // Members who have connected Google or Microsoft: without at
                // least one, nothing is checked for conflicts or written back.
                'members as calendar_members_count' => fn (Builder $query) => $query->whereHas('calendarAccounts'),
            ])
            ->withMax('bookings', 'created_at');
    }

    /**
     * Describe the organization. Expects withCounts() plus event_types_count
     * and bookings_count, and members loaded with is_super_admin.
     *
     * @return array<string, mixed>
     */
    public static function for(Team $team): array
    {
        return [
            'disabledAt' => $team->disabled_at?->toFormattedDateString(),
            'disabledReason' => $team->disabled_reason,
            'plan' => $team->plan?->value,
            'planLabel' => $team->plan?->label(),
            'planNote' => $team->plan_note,
            'mailFrom' => $team->mail_from_address,
            'bookingMailFrom' => $team->bookingMailFrom(),
            'lastBookedAt' => $team->bookings_max_created_at
                ? Carbon::parse($team->bookings_max_created_at)->diffForHumans()
                : null,
            /*
             * The steps an organization needs before it is really running.
             * Each is a fact about its data, not a flag anyone ticks.
             */
            'setup' => [
                'calendar' => $team->calendar_members_count > 0,
                'eventTypes' => $team->event_types_count > 0,
                'twilio' => $team->hasTwilio(),
                'smsNumber' => filled($team->sms_from_number),
                'booked' => $team->bookings_count > 0,
            ],
            // Nobody signs in as a super admin.
            'openAs' => $team->members
                ->reject(fn (User $member) => $member->isSuperAdmin())
                ->values()
                ->map(fn (User $member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => $member->getRelation('pivot')->role->value,
                    'roleLabel' => $member->getRelation('pivot')->role->label(),
                ]),
        ];
    }
}
