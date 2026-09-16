<?php

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Group;
use App\Models\SingleUseLink;
use App\Models\Team;
use App\Models\User;
use App\Services\Scheduling\AvailabilityEngine;
use App\Services\Scheduling\BookingPageResolver;
use App\Support\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BookingPageController extends Controller
{
    public function __construct(
        protected BookingPageResolver $pages,
        protected AvailabilityEngine $availability,
    ) {
        //
    }

    /**
     * Display the event types someone can book.
     */
    public function show(string $page): Response
    {
        $owner = $this->pages->resolve($page);

        abort_if($owner === null, 404);

        $eventTypes = $this->pages->eventTypes($owner);

        return Inertia::render('book/Page', [
            'page' => [
                'slug' => $page,
                'name' => $this->pages->name($owner),
                'welcomeMessage' => $owner->welcome_message,
                'isTeam' => $owner instanceof Team,
                'logoUrl' => $this->pages->logoUrl($owner),
                'websiteUrl' => $this->pages->websiteUrl($owner),
            ],
            'eventTypes' => $eventTypes->map(fn (EventType $eventType) => [
                'slug' => $eventType->slug,
                'name' => $eventType->name,
                'description' => $eventType->description,
                'color' => $eventType->color,
                'durationMinutes' => $eventType->duration_minutes,
                'locationType' => $eventType->location_type->value,
                'locationLabel' => $eventType->location_type->label(),
                'url' => route('book.event-type', ['page' => $page, 'eventType' => $eventType->slug]),
            ]),
        ]);
    }

    /**
     * Display one team's own landing page.
     *
     * Rendered with the same component as the organization page: it is the
     * same list of event types with a different heading, and a second component
     * would only be a copy to keep in step. The `parent` prop is what tells it
     * to offer a way back up to the organization.
     */
    protected function teamPage(User|Team $owner, string $page, Group $group): Response
    {
        $eventTypes = $this->pages->eventTypesForGroup($group);

        return Inertia::render('book/Page', [
            'page' => [
                'slug' => $page,
                'name' => $group->name,
                'welcomeMessage' => $group->description,
                'isTeam' => true,
                'logoUrl' => $this->pages->logoUrl($owner),
                'websiteUrl' => $this->pages->websiteUrl($owner),
            ],
            'parent' => [
                'name' => $this->pages->name($owner),
                'url' => route('book.page', ['page' => $page]),
            ],
            'eventTypes' => $eventTypes->map(fn (EventType $eventType) => [
                'slug' => $eventType->slug,
                'name' => $eventType->name,
                'description' => $eventType->description,
                'color' => $eventType->color,
                'durationMinutes' => $eventType->duration_minutes,
                'locationType' => $eventType->location_type->value,
                'locationLabel' => $eventType->location_type->label(),
                'url' => route('book.event-type', ['page' => $page, 'eventType' => $eventType->slug]),
            ]),
        ]);
    }

    /**
     * Display the slot picker for a single event type.
     */
    public function eventType(Request $request, string $page, string $eventType): Response
    {
        $owner = $this->pages->resolve($page);

        abort_if($owner === null, 404);

        $type = $this->pages->eventType($owner, $eventType);

        /*
         * A team's landing page shares the /book/{page}/{slug} shape with an
         * event type, so the slug is resolved as an event type FIRST and only
         * then as a team. Existing booking links therefore keep working even if
         * someone later names a team after one of them.
         */
        if ($type === null) {
            $group = $this->pages->group($owner, $eventType);

            abort_if($group === null, 404);

            return $this->teamPage($owner, $page, $group);
        }

        $timezone = $this->resolveTimezone($owner);
        $month = $this->resolveMonth($request, $timezone);
        $isRescheduling = $request->string('reschedule')->toString() !== '';
        $bookingOnBehalf = ! $isRescheduling && $type->canBeBookedOnBehalfBy($request->user());
        $chosenHost = $isRescheduling
            ? $this->rescheduleHost($request, $type)
            : ($bookingOnBehalf ? $type->assignableHosts()->firstWhere('id', $request->integer('host')) : null);

        return Inertia::render('book/EventType', [
            'page' => [
                'slug' => $page,
                'name' => $this->pages->name($owner),
                'url' => route('book.page', ['page' => $page]),
                'logoUrl' => $this->pages->logoUrl($owner),
            ],
            'eventType' => $this->toPayload($type),
            'timezone' => $timezone,
            'month' => $month->format('Y-m'),
            'reschedule' => $request->string('reschedule')->toString() ?: null,
            'chosenHost' => $chosenHost === null ? null : [
                'id' => $chosenHost->id,
                'name' => $chosenHost->name,
            ],
            'bookingOnBehalf' => $bookingOnBehalf,
            'slots' => Inertia::defer(fn () => $this->slotsForMonth($type, $month, $timezone, $chosenHost)),
        ]);
    }

    /**
     * Display the slot picker behind a single-use link.
     *
     * The link works even for an event type that is switched off or hidden,
     * which is the point: it books something that has no public link.
     */
    public function singleUseLink(Request $request, SingleUseLink $singleUseLink): Response
    {
        $type = $singleUseLink->eventType;

        abort_if($type === null, 404);

        $owner = $type->kind->hasHostPool() || blank($type->owner->booking_slug) ? $type->team : $type->owner;
        $pageSlug = $owner instanceof Team ? $owner->slug : $owner->booking_slug;

        if ($singleUseLink->used_at !== null) {
            return Inertia::render('book/LinkUsed', [
                'eventTypeName' => $type->name,
                'pageName' => $this->pages->name($owner),
                'pageUrl' => route('book.page', ['page' => $pageSlug]),
            ]);
        }

        $timezone = $this->resolveTimezone($owner);
        $month = $this->resolveMonth($request, $timezone);

        return Inertia::render('book/EventType', [
            'page' => [
                'slug' => $pageSlug,
                'name' => $this->pages->name($owner),
                'url' => route('book.page', ['page' => $pageSlug]),
                'logoUrl' => $this->pages->logoUrl($owner),
            ],
            'eventType' => $this->toPayload($type),
            'timezone' => $timezone,
            'month' => $month->format('Y-m'),
            'reschedule' => null,
            'chosenHost' => null,
            'bookingOnBehalf' => false,
            'singleUseLink' => $singleUseLink->uid,
            'slots' => Inertia::defer(fn () => $this->slotsForMonth($type, $month, $timezone)),
        ]);
    }

    /**
     * Group the bookable slots of a month by local date.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function slotsForMonth(EventType $eventType, CarbonImmutable $month, string $timezone, ?User $host = null): array
    {
        $window = new TimeRange(
            $month->startOfMonth()->startOfDay()->utc(),
            $month->endOfMonth()->endOfDay()->utc(),
        );

        return $this->availability
            ->slots($eventType, $window)
            ->when($host !== null, fn ($slots) => $slots->filter(fn ($slot) => in_array($host->id, $slot->hostIds, true)))
            ->groupBy(fn ($slot) => $slot->startsAt->setTimezone($timezone)->toDateString())
            ->map(fn ($slots) => $slots->map(fn ($slot) => [
                'startsAt' => $slot->startsAt->toIso8601String(),
                'label' => $slot->startsAt->setTimezone($timezone)->format('g:ia'),
                'seatsRemaining' => $slot->seatsRemaining,
            ])->values()->all())
            ->all();
    }

    /**
     * Get the host a signed-in organizer chose to hand a rescheduled meeting to.
     */
    protected function rescheduleHost(Request $request, EventType $eventType): ?User
    {
        $uid = $request->string('reschedule')->toString();

        if ($uid === '' || $request->integer('host') === 0) {
            return null;
        }

        $booking = Booking::query()
            ->where('uid', $uid)
            ->where('event_type_id', $eventType->id)
            ->first();

        return $booking?->chosenHostFor($request->user(), $request->integer('host'));
    }

    /**
     * Work out which month the picker should show.
     */
    protected function resolveMonth(Request $request, string $timezone): CarbonImmutable
    {
        $requested = $request->string('month')->toString();
        $now = CarbonImmutable::now($timezone);

        if ($requested === '' || ! preg_match('/^\d{4}-\d{2}$/', $requested)) {
            return $now->startOfMonth();
        }

        $month = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $requested.'-01 00:00:00', $timezone);

        return $month->lessThan($now->startOfMonth()) ? $now->startOfMonth() : $month;
    }

    /**
     * Work out which timezone to show times in.
     */
    protected function resolveTimezone(User|Team $owner): string
    {
        /*
         * The organizer's timezone, and only theirs. The invitee used to be
         * able to pick one; times are now stated in the organizer's zone and
         * labelled with it, so there is one answer to "what time is this?"
         * rather than one per reader.
         */
        return $owner->timezone ?: config('scheduling.default_timezone');
    }

    /**
     * Present an event type for the public picker.
     *
     * @return array<string, mixed>
     */
    protected function toPayload(EventType $eventType): array
    {

        return [
            'slug' => $eventType->slug,
            'name' => $eventType->name,
            'description' => $eventType->description,
            'color' => $eventType->color,
            'durationMinutes' => $eventType->duration_minutes,
            'kind' => $eventType->kind->value,
            'locationType' => $eventType->location_type->value,
            'locationLabel' => $eventType->location_type->label(),
            'locationDetail' => $eventType->location_type->requiresHostDetail() ? $eventType->location_detail : null,
            'needsInviteePhone' => $eventType->location_type->requiresInviteeInput(),
            'seatsPerSlot' => $eventType->seats(),
            /*
              * Only a definite host is named. A pooled event type is assigned
              * when it is booked, so listing everyone who might take it tells
              * the invitee nothing and publishes the roster; the organization's
              * own name is already at the top of the page.
              */
            'hostNames' => $eventType->kind->hasHostPool()
                ? []
                : [$eventType->owner->name],
            'questions' => $eventType->questions->map(fn ($question) => [
                'id' => $question->id,
                'type' => $question->type->value,
                'label' => $question->label,
                'helpText' => $question->help_text,
                'options' => $question->options ?? [],
                'isRequired' => $question->is_required,
            ])->values(),
        ];
    }
}
