<?php

namespace App\Http\Requests\Scheduling;

use App\Enums\DateRangeType;
use App\Enums\EventTypeKind;
use App\Enums\LocationType;
use App\Enums\QuestionType;
use App\Enums\TeamPermission;
use App\Models\EventType;
use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveEventTypeRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $eventType = $this->route('event_type');
        $teamId = $eventType instanceof EventType ? $eventType->team_id : $this->user()->current_team_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('event_types', 'slug')
                    ->where('team_id', $teamId)
                    ->ignore($eventType instanceof EventType ? $eventType->id : null)
                    ->whereNull('deleted_at'),
                Rule::notIn(['book', 'bookings', 'settings', 'dashboard', 'login', 'register']),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'kind' => ['required', Rule::enum(EventTypeKind::class)],
            'color' => ['nullable', 'string', 'max:20'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'slot_interval_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'buffer_before_minutes' => ['required', 'integer', 'min:0', 'max:720'],
            'buffer_after_minutes' => ['required', 'integer', 'min:0', 'max:720'],
            'minimum_notice_minutes' => ['required', 'integer', 'min:0', 'max:100000'],
            'daily_booking_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'seats_per_slot' => ['required', 'integer', 'min:1', 'max:500'],
            'date_range_type' => ['required', Rule::enum(DateRangeType::class)],
            'rolling_days' => ['required', 'integer', 'min:1', 'max:730'],
            'range_starts_on' => ['nullable', 'date', 'required_if:date_range_type,fixed_range'],
            'range_ends_on' => ['nullable', 'date', 'after_or_equal:range_starts_on', 'required_if:date_range_type,fixed_range'],
            'location_type' => ['required', Rule::enum(LocationType::class)],
            'location_detail' => ['nullable', 'string', 'max:500'],
            /*
             * The schedule has to belong to the event type's OWNER, not to
             * whoever is filling the form in. A super admin or an admin
             * editing someone else's event type submits that person's
             * schedule, and requiring their own rejected every such save with
             * "the selected availability schedule id is invalid".
             *
             * A schedule the organization shares is allowed too: those are the
             * hours an event type keeps whoever hosts it.
             */
            'availability_schedule_id' => [
                'nullable',
                Rule::exists('availability_schedules', 'id')->where(
                    fn ($query) => $query
                        ->where(fn ($owned) => $owned->where('user_id', $this->ownerId()))
                        ->orWhere(fn ($shared) => $shared->where('team_id', $teamId)),
                ),
            ],
            'is_active' => ['boolean'],
            'is_hidden' => ['boolean'],
            'requires_confirmation' => ['boolean'],

            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('team_members', 'user_id')->where('team_id', $teamId),
            ],

            'group_id' => [
                'nullable',
                'integer',
                Rule::exists('groups', 'id')->where('team_id', $teamId),
            ],

            'host_ids' => ['array'],
            'host_ids.*' => [
                'integer',
                Rule::exists('team_members', 'user_id')->where('team_id', $teamId),
            ],

            'questions' => ['array', 'max:20'],
            'questions.*.id' => ['nullable', 'integer'],
            'questions.*.type' => ['required', Rule::enum(QuestionType::class)],
            'questions.*.label' => ['required', 'string', 'max:255'],
            'questions.*.help_text' => ['nullable', 'string', 'max:255'],
            'questions.*.options' => ['nullable', 'array', 'max:50'],
            'questions.*.options.*' => ['string', 'max:255'],
            'questions.*.is_required' => ['boolean'],
        ];
    }

    /**
     * Get the id of the person the event type belongs to.
     *
     * The submitted owner when one is given and the person saving may hand
     * event types to someone else, the existing owner when editing, and
     * otherwise whoever is creating it. This mirrors
     * EventTypeController::resolveOwner(): honouring a user_id the controller
     * then ignores let a member attach a colleague's personal schedule to their
     * own event type.
     */
    protected function ownerId(): int
    {
        $eventType = $this->route('event_type');
        $currentOwnerId = $eventType instanceof EventType ? $eventType->user_id : (int) $this->user()->id;
        $submitted = $this->input('user_id');

        if (blank($submitted)) {
            return $currentOwnerId;
        }

        $team = Team::find($eventType instanceof EventType ? $eventType->team_id : $this->user()->current_team_id);

        return $team !== null && $this->user()->hasTeamPermission($team, TeamPermission::ManageTeamBookings)
            ? (int) $submitted
            : $currentOwnerId;
    }

    /**
     * Get the custom validation rules that run after the basic rules pass.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function ($validator) {
                $kind = EventTypeKind::tryFrom((string) $this->input('kind'));
                $location = LocationType::tryFrom((string) $this->input('location_type'));

                if ($kind?->hasHostPool()
                    && blank($this->input('group_id'))
                    && count($this->input('host_ids', [])) < 1
                ) {
                    $validator->errors()->add('host_ids', 'Pick a group, or at least one host, for this event type.');
                }

                if ($location?->requiresHostDetail() && blank($this->input('location_detail'))) {
                    $validator->errors()->add('location_detail', 'Add the '.strtolower($location->detailLabel()).'.');
                }
            },
        ];
    }
}
