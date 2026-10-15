<?php

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Enums\QuestionType;
use App\Exceptions\SlotUnavailableException;
use App\Jobs\SyncBookingToCalendars;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\EventTypeQuestion;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\Scheduling\AvailabilityEngine;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CreateBooking
{
    public function __construct(
        protected AvailabilityEngine $availability,
        protected AssignHosts $assignHosts,
        protected ScheduleReminders $scheduleReminders,
        protected ScheduleAutomations $scheduleAutomations,
        protected NotifyBookingParties $notify,
        protected ActivityLogger $activity,
    ) {
        //
    }

    /**
     * Book a slot on an event type.
     *
     * @param  array{name: string, email: string, notes?: string|null, timezone?: string|null, location_detail?: string|null, guests?: array<int, string>, answers?: array<int|string, mixed>, reference?: string|null, zone?: string|null}  $attributes
     *
     * The host pool is resolved before the transaction opens, so that locking
     * the hosts is the first read inside it. A caller that wraps this in its
     * own transaction must resolve the pool first for the same reason.
     *
     * A chosen host skips automatic assignment, and the slot must be free for
     * that host in particular rather than for anyone in the pool.
     *
     * A zone (from the booking link) keeps the slot and the host to those who
     * cover it: the slot is looked up among them, so a chosen host outside
     * the zone is refused the same way as a busy one.
     *
     * @throws SlotUnavailableException
     */
    public function handle(EventType $eventType, CarbonImmutable $startsAt, array $attributes, ?Booking $replacing = null, ?User $host = null): Booking
    {
        $zone = $attributes['zone'] ?? null;
        $candidateHostIds = $eventType->schedulingHosts($zone)->modelKeys();

        $booking = DB::transaction(function () use ($eventType, $startsAt, $attributes, $replacing, $host, $candidateHostIds, $zone) {
            $this->lockHosts($candidateHostIds);

            $slot = $this->availability->findSlot($eventType, $startsAt, $replacing?->id, $zone);

            if ($slot === null || ($host !== null && ! in_array($host->id, $slot->hostIds, true))) {
                throw SlotUnavailableException::taken();
            }

            $hosts = $host !== null
                ? new Collection([$host])
                : $this->assignHosts->handle($eventType, $slot->hostIds, $zone);

            $booking = Booking::create([
                'event_type_id' => $eventType->id,
                'team_id' => $eventType->team_id,
                'user_id' => $hosts->first()->id,
                'status' => $eventType->requires_confirmation ? BookingStatus::Pending : BookingStatus::Confirmed,
                'starts_at' => $slot->startsAt,
                'ends_at' => $slot->endsAt,
                'invitee_timezone' => $attributes['timezone'] ?? config('scheduling.default_timezone'),
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'notes' => $attributes['notes'] ?? null,
                'reference' => $attributes['reference'] ?? null,
                'zone' => $zone,
                'location_type' => $eventType->location_type,
                'location_detail' => $this->locationDetail($eventType, $attributes['location_detail'] ?? null),
                'rescheduled_from_id' => $replacing?->id,
            ]);

            $booking->hosts()->sync($hosts->pluck('id'));

            $this->storeGuests($booking, $attributes['guests'] ?? []);
            $this->storeAnswers($booking, $eventType, $attributes['answers'] ?? []);

            /*
             * A pending booking gets its reminders when a host approves it:
             * SendBookingReminders hard-deletes reminders for non-confirmed
             * bookings, so rows created now would never survive to be sent.
             */
            if ($booking->status->isConfirmed()) {
                $this->scheduleReminders->handle($booking);
                $this->scheduleAutomations->handle($booking);
            }

            return $booking;
        });

        $booking->load(['eventType', 'host', 'hosts', 'guests', 'answers']);

        // A pending booking is written to calendars on approval, not before.
        if ($booking->status->isConfirmed()) {
            SyncBookingToCalendars::dispatch($booking)->afterCommit();
        }

        /*
         * A reschedule builds its replacement through this action, but the move
         * is announced and logged once by RescheduleBooking. Confirming here as
         * well would mail everyone a second, contradictory "booking confirmed"
         * and record a booking.created the invitee never performed.
         */
        if ($replacing === null) {
            $booking->status->isConfirmed()
                ? $this->notify->confirmed($booking)
                : $this->notify->pending($booking);

            $eventTypeName = $booking->eventType->name ?? 'a meeting';
            $properties = ['startsAt' => $booking->starts_at->toIso8601String(), 'email' => $booking->email];

            if (! $booking->status->isConfirmed()) {
                $properties['requiresApproval'] = true;
            }

            $this->activity->record(
                $booking->team,
                'booking.created',
                $booking->status->isConfirmed()
                    ? $booking->name.' booked '.$eventTypeName
                    : $booking->name.' requested '.$eventTypeName,
                $booking,
                $properties,
            );
        }

        return $booking;
    }

    /**
     * Hold the hosts' rows until the booking commits.
     *
     * Two invitees picking the same slot at the same moment would otherwise
     * both find it free and both be booked. Locking in id order keeps
     * overlapping pools from deadlocking; under MySQL's repeatable read the
     * lock must also come before any plain read in the transaction, or the
     * availability check reads a snapshot taken before the other booking
     * committed.
     *
     * @param  array<int, int>  $hostIds
     */
    protected function lockHosts(array $hostIds): void
    {
        User::query()
            ->whereKey($hostIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');
    }

    /**
     * Store the additional guests invited alongside the invitee.
     *
     * @param  array<int, string>  $guests
     */
    protected function storeGuests(Booking $booking, array $guests): void
    {
        foreach (array_unique(array_filter($guests)) as $email) {
            $booking->guests()->firstOrCreate(['email' => $email]);
        }
    }

    /**
     * Store the answers given to the event type's custom questions.
     *
     * @param  array<int|string, mixed>  $answers
     */
    protected function storeAnswers(Booking $booking, EventType $eventType, array $answers): void
    {
        if ($answers === []) {
            return;
        }

        $questions = $eventType->questions()->get()->keyBy('id');

        foreach ($answers as $questionId => $answer) {
            /** @var EventTypeQuestion|null $question */
            $question = $questions->get((int) $questionId);

            if ($question === null || blank($answer)) {
                continue;
            }

            $text = is_array($answer) ? implode(', ', $answer) : (string) $answer;

            $booking->answers()->create([
                'event_type_question_id' => $question->id,
                'label' => $question->label,
                'answer' => $question->type === QuestionType::Phone
                    ? (PhoneNumber::toE164($text) ?? $text)
                    : $text,
            ]);
        }
    }

    /**
     * Get the location detail to store on the booking.
     *
     * A number the invitee gave to be called on is stored in E.164, the form
     * Twilio dials and every other number in the app is kept in. One that
     * cannot be put in that form is kept as written rather than lost.
     */
    protected function locationDetail(EventType $eventType, ?string $given): ?string
    {
        if ($given === null) {
            return $eventType->location_detail;
        }

        return $eventType->location_type->requiresInviteeInput()
            ? (PhoneNumber::toE164($given) ?? $given)
            : $given;
    }
}
