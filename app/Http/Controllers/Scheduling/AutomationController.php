<?php

namespace App\Http\Controllers\Scheduling;

use App\Enums\AutomationChannel;
use App\Enums\AutomationRecipient;
use App\Enums\AutomationTrigger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\SaveAutomationRequest;
use App\Models\Automation;
use App\Models\EventType;
use App\Models\Team;
use App\Services\Automations\AutomationMessage;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Automation model is a "workflow" in the UI, the name the team already
 * uses for the same thing in Calendly.
 */
class AutomationController extends Controller
{
    /**
     * Display the organization's workflows.
     */
    public function index(Request $request, Team $current_team): Response
    {
        Gate::authorize('viewAny', Automation::class);

        $automations = $current_team->automations()
            ->with('eventTypes:id,name')
            ->orderBy('name')
            ->get();

        return Inertia::render('scheduling/automations/Index', [
            'automations' => $automations->map(fn (Automation $automation) => $this->toListItem($automation)),
            'canManage' => $request->user()->can('create', [Automation::class, $current_team]),
        ]);
    }

    /**
     * Show the form for building a new workflow.
     */
    public function create(Request $request, Team $current_team): Response
    {
        Gate::authorize('create', [Automation::class, $current_team]);

        return Inertia::render('scheduling/automations/Edit', [
            ...$this->formOptions($current_team),
            'automation' => null,
        ]);
    }

    /**
     * Store a new workflow.
     */
    public function store(SaveAutomationRequest $request, Team $current_team): RedirectResponse
    {
        Gate::authorize('create', [Automation::class, $current_team]);

        DB::transaction(function () use ($request, $current_team) {
            $automation = $current_team->automations()->create($this->attributes($request));

            $automation->eventTypes()->sync($request->validated('event_type_ids', []));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workflow created.')]);

        return to_route('automations.index', ['current_team' => $current_team->slug]);
    }

    /**
     * Show the form for editing a workflow.
     */
    public function edit(Team $current_team, Automation $automation): Response
    {
        Gate::authorize('update', $automation);

        $automation->load('eventTypes:id');

        return Inertia::render('scheduling/automations/Edit', [
            ...$this->formOptions($current_team),
            'automation' => $this->toFormPayload($automation),
        ]);
    }

    /**
     * Update a workflow.
     *
     * Runs already queued against bookings are left where they are: editing
     * the wording of a message is not a reason to re-time what it is attached
     * to, and anything already sent is history.
     */
    public function update(SaveAutomationRequest $request, Team $current_team, Automation $automation): RedirectResponse
    {
        Gate::authorize('update', $automation);

        DB::transaction(function () use ($request, $automation) {
            $automation->update($this->attributes($request));

            $automation->eventTypes()->sync($request->validated('event_type_ids', []));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workflow updated.')]);

        return to_route('automations.index', ['current_team' => $current_team->slug]);
    }

    /**
     * Delete a workflow, and with it anything it had queued but not sent.
     */
    public function destroy(Team $current_team, Automation $automation): RedirectResponse
    {
        Gate::authorize('delete', $automation);

        $automation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workflow deleted.')]);

        return to_route('automations.index', ['current_team' => $current_team->slug]);
    }

    /**
     * Get the attributes to write.
     *
     * @return array<string, mixed>
     */
    protected function attributes(SaveAutomationRequest $request): array
    {
        $attributes = $request->safe()->only([
            'name', 'trigger', 'offset_minutes', 'recipient', 'channels',
            'recipient_emails', 'recipient_phones', 'subject', 'body', 'sms_body', 'is_active',
        ]);

        $typedIn = ($attributes['recipient'] ?? null) === AutomationRecipient::Someone->value;

        // Addresses and numbers only mean something when typed in by hand.
        $attributes['recipient_emails'] = $typedIn
            ? array_values(array_unique($attributes['recipient_emails'] ?? []))
            : null;

        // Stored the way Twilio wants them, whatever punctuation was typed.
        $attributes['recipient_phones'] = $typedIn
            ? array_values(array_unique(array_filter(array_map(
                fn (string $phone) => PhoneNumber::toE164($phone),
                $attributes['recipient_phones'] ?? [],
            ))))
            : null;

        /*
         * Only what was sent is applied: a save that says nothing about the
         * switch leaves a workflow where it was, and a new one starts on.
         */
        if (array_key_exists('is_active', $attributes)) {
            $attributes['is_active'] = $request->boolean('is_active');
        }

        return $attributes;
    }

    /**
     * Get the choices the editor offers.
     *
     * @return array<string, mixed>
     */
    protected function formOptions(Team $current_team): array
    {
        return [
            'eventTypes' => $current_team->eventTypes()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (EventType $eventType) => [
                    'value' => $eventType->id,
                    'label' => $eventType->name,
                ]),
            'triggerOptions' => AutomationTrigger::options(),
            'recipientOptions' => AutomationRecipient::options(),
            'channelOptions' => AutomationChannel::options(),
            'variables' => AutomationMessage::variables(),
            /*
             * Text messages need two things: this organization's own Twilio
             * account, and a number it has chosen to send from. The editor
             * says which is missing rather than letting someone build a
             * workflow that can never send.
             */
            'sms' => [
                'configured' => $current_team->hasTwilio(),
                'fromNumber' => $current_team->sms_from_number,
                'settingsUrl' => route('teams.edit', ['team' => $current_team->slug]),
            ],
        ];
    }

    /**
     * Present a workflow for the listing.
     *
     * @return array<string, mixed>
     */
    protected function toListItem(Automation $automation): array
    {
        return [
            'id' => $automation->id,
            'name' => $automation->name,
            'isActive' => $automation->is_active,
            // No event types means every one of them, including any added later.
            'appliesTo' => $automation->eventTypes->isEmpty()
                ? ['All event types']
                : $automation->eventTypes->pluck('name')->all(),
            'when' => $automation->describeWhen(),
            'action' => $automation->describeAction(),
        ];
    }

    /**
     * Present a workflow for the editor.
     *
     * @return array<string, mixed>
     */
    protected function toFormPayload(Automation $automation): array
    {
        return [
            'id' => $automation->id,
            'name' => $automation->name,
            'trigger' => $automation->trigger->value,
            'offsetMinutes' => $automation->offset_minutes,
            'recipient' => $automation->recipient->value,
            'recipientEmails' => $automation->recipient_emails ?? [],
            'recipientPhones' => $automation->recipient_phones ?? [],
            'channels' => $automation->channels->map(fn (AutomationChannel $channel) => $channel->value)->all(),
            'smsBody' => $automation->sms_body,
            'subject' => $automation->subject,
            'body' => $automation->body,
            'isActive' => $automation->is_active,
            'eventTypeIds' => $automation->eventTypes->pluck('id')->all(),
        ];
    }
}
