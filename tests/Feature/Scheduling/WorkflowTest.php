<?php

use App\Enums\AutomationRecipient;
use App\Enums\AutomationTrigger;
use App\Enums\LocationType;
use App\Enums\QuestionType;
use App\Models\Automation;
use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Bookings\AutomationEmail;
use App\Services\Automations\AutomationMessage;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-31 08:00:00', 'UTC'));

    $this->host = User::factory()->create([
        'name' => 'Dana Reed',
        'email' => 'dana@example.test',
        'booking_slug' => 'dana',
        'timezone' => 'UTC',
    ]);

    $this->team = $this->host->currentTeam;

    AvailabilitySchedule::factory()->for($this->host)->everyDay()->create();

    $this->eventType = EventType::factory()->ownedBy($this->host)->create([
        'name' => 'Leasing Team Meeting',
        'slug' => 'intro',
        'duration_minutes' => 60,
    ]);
});

/**
 * Book the slot the availability schedule leaves free on 2 September.
 */
function bookASlot(array $overrides = []): Booking
{
    test()->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), array_merge([
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC')->toIso8601String(),
        'timezone' => 'UTC',
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
    ], $overrides));

    return Booking::latest('id')->first();
}

test('the workflows page lists only this organizations workflows', function () {
    Automation::factory()->create(['team_id' => $this->team->id, 'name' => 'Tell accounting']);
    Automation::factory()->create(['name' => 'Someone elses workflow']);

    $this->actingAs($this->host)
        ->get(route('automations.index', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('scheduling/automations/Index')
            ->has('automations', 1)
            ->where('automations.0.name', 'Tell accounting')
            ->where('automations.0.appliesTo', ['All event types'])
            ->where('automations.0.when', 'When a meeting is booked')
            ->where('canManage', true));
});

test('the editor offers the organizations event types and the message variables', function () {
    $automation = Automation::factory()->create(['team_id' => $this->team->id]);
    $automation->eventTypes()->attach($this->eventType);

    $this->actingAs($this->host)
        ->get(route('automations.edit', [
            'current_team' => $this->team->slug,
            'automation' => $automation->id,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('scheduling/automations/Edit')
            ->where('automation.name', $automation->name)
            ->where('automation.eventTypeIds', [$this->eventType->id])
            ->has('eventTypes', 1)
            ->has('triggerOptions', 4)
            ->has('recipientOptions', 3)
            ->has('variables', 13));
});

test('a workflow can be edited, and switching it off leaves it in place', function () {
    $automation = Automation::factory()->create(['team_id' => $this->team->id]);
    $automation->eventTypes()->attach($this->eventType);

    $this->actingAs($this->host)
        ->patch(route('automations.update', [
            'current_team' => $this->team->slug,
            'automation' => $automation->id,
        ]), [
            'name' => 'Email reminder to someone else - Accounting',
            'trigger' => AutomationTrigger::AfterEnd->value,
            'offset_minutes' => 1440,
            'channels' => ['email'],
            'recipient' => AutomationRecipient::Someone->value,
            'recipient_emails' => ['accounting@texasrenters.com'],
            'subject' => 'Meeting done',
            'body' => '{{ event_name }} has finished.',
            'is_active' => false,
            'event_type_ids' => [],
        ])
        ->assertRedirect(route('automations.index', ['current_team' => $this->team->slug]));

    $automation->refresh();

    expect($automation->name)->toBe('Email reminder to someone else - Accounting')
        ->and($automation->trigger)->toBe(AutomationTrigger::AfterEnd)
        ->and($automation->is_active)->toBeFalse()
        ->and($automation->describeWhen())->toBe('1 day after it ends')
        ->and($automation->eventTypes)->toHaveCount(0);
});

test('deleting a workflow takes its queued sends with it', function () {
    Notification::fake();

    $automation = Automation::factory()->beforeStart(60)->create(['team_id' => $this->team->id]);
    $booking = bookASlot();

    expect($booking->automationRuns)->toHaveCount(1);

    $this->actingAs($this->host)
        ->delete(route('automations.destroy', [
            'current_team' => $this->team->slug,
            'automation' => $automation->id,
        ]))
        ->assertRedirect(route('automations.index', ['current_team' => $this->team->slug]));

    expect(Automation::count())->toBe(0)
        ->and($booking->fresh()->automationRuns)->toHaveCount(0);
});

test('a workflow belonging to another organization cannot be opened', function () {
    $theirs = Automation::factory()->create();

    $this->actingAs($this->host)
        ->get(route('automations.edit', [
            'current_team' => $this->team->slug,
            'automation' => $theirs->id,
        ]))
        ->assertNotFound();
});

test('a workflow can be created against chosen event types', function () {
    $this->actingAs($this->host)
        ->post(route('automations.store', ['current_team' => $this->team->slug]), [
            'name' => 'Email reminder to someone else',
            'trigger' => AutomationTrigger::Booked->value,
            'channels' => ['email'],
            'recipient' => AutomationRecipient::Someone->value,
            'recipient_emails' => ['leasing@texasrenters.com', 'ntm@texasrenters.com'],
            'subject' => 'New booking',
            'body' => '{{ invitee_name }} booked {{ event_name }}.',
            'is_active' => true,
            'event_type_ids' => [$this->eventType->id],
        ])
        ->assertRedirect(route('automations.index', ['current_team' => $this->team->slug]));

    $automation = Automation::first();

    expect($automation->name)->toBe('Email reminder to someone else')
        ->and($automation->team_id)->toBe($this->team->id)
        ->and($automation->recipient)->toBe(AutomationRecipient::Someone)
        ->and($automation->recipient_emails)->toBe(['leasing@texasrenters.com', 'ntm@texasrenters.com'])
        ->and($automation->eventTypes->pluck('id')->all())->toBe([$this->eventType->id]);
});

test('a workflow can send an email and a text at once, with the numbers tidied up', function () {
    $this->actingAs($this->host)
        ->post(route('automations.store', ['current_team' => $this->team->slug]), [
            'name' => 'Tell leasing both ways',
            'trigger' => AutomationTrigger::Booked->value,
            'channels' => ['email', 'sms'],
            'recipient' => AutomationRecipient::Someone->value,
            'recipient_emails' => ['leasing@texasrenters.com'],
            'recipient_phones' => ['(512) 555-0100', '512.555.0101'],
            'subject' => 'New booking',
            'body' => '{{ invitee_name }} booked {{ event_name }}.',
            'sms_body' => '{{ invitee_name }} booked {{ event_name }}.',
        ])
        ->assertRedirect(route('automations.index', ['current_team' => $this->team->slug]));

    $automation = Automation::first();

    expect($automation->recipient_phones)->toBe(['+15125550100', '+15125550101'])
        ->and($automation->channels->map(fn ($channel) => $channel->value)->all())->toBe(['email', 'sms'])
        ->and($automation->describeAction())->toBe('Email and Text someone else');
});

test('a workflow can only send a text, leaving the email wording blank', function () {
    $this->actingAs($this->host)
        ->post(route('automations.store', ['current_team' => $this->team->slug]), [
            'name' => 'Text leasing',
            'trigger' => AutomationTrigger::Booked->value,
            'channels' => ['sms'],
            'recipient' => AutomationRecipient::Someone->value,
            'recipient_phones' => ['(512) 555-0100'],
            'subject' => '',
            'body' => '',
            'sms_body' => '{{ invitee_name }} booked {{ event_name }}.',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('automations.index', ['current_team' => $this->team->slug]));

    expect(Automation::first()->describeAction())->toBe('Text +15125550100');
});

test('a workflow that texts has to say what the text says', function () {
    $this->actingAs($this->host)
        ->post(route('automations.store', ['current_team' => $this->team->slug]), [
            'name' => 'Text with nothing to say',
            'trigger' => AutomationTrigger::Booked->value,
            'channels' => ['sms'],
            'recipient' => AutomationRecipient::Someone->value,
            'recipient_phones' => ['(512) 555-0100'],
        ])
        ->assertSessionHasErrors('sms_body');
});

test('a number nobody could dial is refused', function () {
    $this->actingAs($this->host)
        ->post(route('automations.store', ['current_team' => $this->team->slug]), [
            'name' => 'Bad number',
            'trigger' => AutomationTrigger::Booked->value,
            'channels' => ['sms'],
            'recipient' => AutomationRecipient::Someone->value,
            'recipient_phones' => ['555'],
            'sms_body' => 'Hello',
        ])
        ->assertSessionHasErrors('recipient_phones.0');
});

test('a timed workflow has to say how long before or after', function () {
    $this->actingAs($this->host)
        ->post(route('automations.store', ['current_team' => $this->team->slug]), [
            'name' => 'Nudge the host',
            'trigger' => AutomationTrigger::BeforeStart->value,
            'channels' => ['email'],
            'recipient' => AutomationRecipient::Host->value,
            'subject' => 'Starting soon',
            'body' => 'Your meeting starts soon.',
        ])
        ->assertSessionHasErrors('offset_minutes');
});

test('an address is only kept when the email goes to someone typed in', function () {
    $this->actingAs($this->host)
        ->post(route('automations.store', ['current_team' => $this->team->slug]), [
            'name' => 'Tell the hosts',
            'trigger' => AutomationTrigger::Booked->value,
            'channels' => ['email'],
            'recipient' => AutomationRecipient::Host->value,
            'recipient_emails' => ['leftover@texasrenters.com'],
            'subject' => 'New booking',
            'body' => 'A meeting was booked.',
        ]);

    expect(Automation::first()->recipient_emails)->toBeNull();
});

test('booking a meeting queues the workflows that cover its event type', function () {
    Notification::fake();

    $everyEventType = Automation::factory()->create(['team_id' => $this->team->id]);

    $otherEventType = EventType::factory()->ownedBy($this->host)->create(['slug' => 'other']);
    $limited = Automation::factory()->create(['team_id' => $this->team->id]);
    $limited->eventTypes()->attach($otherEventType);

    $switchedOff = Automation::factory()->inactive()->create(['team_id' => $this->team->id]);

    $booking = bookASlot();

    expect($booking->automationRuns->pluck('automation_id')->all())->toBe([$everyEventType->id])
        ->and($booking->automationRuns()->where('automation_id', $limited->id)->exists())->toBeFalse()
        ->and($booking->automationRuns()->where('automation_id', $switchedOff->id)->exists())->toBeFalse();
});

test('a workflow limited to this event type is queued for it', function () {
    Notification::fake();

    $automation = Automation::factory()->create(['team_id' => $this->team->id]);
    $automation->eventTypes()->attach($this->eventType);

    expect(bookASlot()->automationRuns)->toHaveCount(1);
});

test('timed workflows are queued either side of the meeting', function () {
    Notification::fake();

    $before = Automation::factory()->beforeStart(60)->create(['team_id' => $this->team->id]);
    $after = Automation::factory()->afterEnd(30)->create(['team_id' => $this->team->id]);

    $booking = bookASlot();

    $sendAt = $booking->automationRuns->keyBy('automation_id');

    expect($sendAt[$before->id]->send_at->toDateTimeString())->toBe('2026-09-02 09:00:00')
        ->and($sendAt[$after->id]->send_at->toDateTimeString())->toBe('2026-09-02 11:30:00');
});

test('a before start workflow whose moment has already passed is skipped', function () {
    Notification::fake();

    // The meeting is two days out, so "a week before" is already behind us.
    Automation::factory()->beforeStart(7 * 24 * 60)->create(['team_id' => $this->team->id]);

    expect(bookASlot()->automationRuns)->toHaveCount(0);
});

test('canceling a meeting clears what its workflows had queued', function () {
    Notification::fake();

    Automation::factory()->beforeStart(60)->create(['team_id' => $this->team->id]);

    $booking = bookASlot();

    expect($booking->automationRuns)->toHaveCount(1);

    $this->delete(route('booking.cancel.store', ['booking' => $booking->uid]), ['reason' => 'Sick']);

    expect($booking->fresh()->automationRuns)->toHaveCount(0);
});

test('rescheduling re-times what is pending and does not resend what went out', function () {
    Notification::fake();

    $announcement = Automation::factory()->create(['team_id' => $this->team->id]);
    $reminder = Automation::factory()->beforeStart(60)->create(['team_id' => $this->team->id]);

    $booking = bookASlot();

    $booking->automationRuns()
        ->where('automation_id', $announcement->id)
        ->update(['sent_at' => now()]);

    $this->post(route('booking.reschedule.store', ['booking' => $booking->uid]), [
        'starts_at' => CarbonImmutable::parse('2026-09-03 14:00:00', 'UTC')->toIso8601String(),
    ]);

    $replacement = Booking::where('rescheduled_from_id', $booking->id)->first();

    expect($replacement->automationRuns->pluck('automation_id')->all())->toBe([$reminder->id])
        ->and($replacement->automationRuns->first()->send_at->toDateTimeString())->toBe('2026-09-03 13:00:00')
        ->and($booking->fresh()->automationRuns()->whereNull('sent_at')->count())->toBe(0);
});

test('due workflow emails are sent once, to every address on the list', function () {
    Notification::fake();

    $automation = Automation::factory()->create([
        'team_id' => $this->team->id,
        'recipient_emails' => ['leasing@texasrenters.com', 'ntm@texasrenters.com'],
    ]);

    $booking = bookASlot();
    $run = $booking->automationRuns()->first();
    $run->update(['send_at' => now()->subMinute()]);

    $this->artisan('automations:run')->assertSuccessful();

    expect($run->fresh()->sent_at)->not->toBeNull();

    foreach (['leasing@texasrenters.com', 'ntm@texasrenters.com'] as $email) {
        Notification::assertSentOnDemand(
            AutomationEmail::class,
            fn (AutomationEmail $notification, array $channels, object $notifiable) => $notifiable->routes['mail'] === $email
                && $notification->automation->is($automation),
        );
    }

    Notification::fake();
    $this->artisan('automations:run')->assertSuccessful();
    Notification::assertNothingSent();
});

test('a workflow for the hosts reaches every host attending', function () {
    Notification::fake();

    Automation::factory()->toHosts()->create(['team_id' => $this->team->id]);

    $booking = bookASlot();
    $booking->automationRuns()->update(['send_at' => now()->subMinute()]);

    $this->artisan('automations:run')->assertSuccessful();

    Notification::assertSentTo($this->host, AutomationEmail::class);
});

test('a workflow queued against a meeting that was called off is dropped', function () {
    Notification::fake();

    $automation = Automation::factory()->create(['team_id' => $this->team->id]);

    $booking = Booking::factory()->canceled()->create([
        'event_type_id' => $this->eventType->id,
        'team_id' => $this->team->id,
        'user_id' => $this->host->id,
    ]);

    $run = $booking->automationRuns()->create([
        'automation_id' => $automation->id,
        'send_at' => now()->subMinute(),
    ]);

    $this->artisan('automations:run')->assertSuccessful();

    expect($run->fresh())->toBeNull();

    Notification::assertNothingSent();
});

test('the message variables are filled in from the booking', function () {
    $question = $this->eventType->questions()->create([
        'type' => QuestionType::Phone,
        'label' => 'Best number',
        'position' => 0,
    ]);

    $booking = Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'team_id' => $this->team->id,
        'user_id' => $this->host->id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 15:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 16:00:00', 'UTC'),
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'location_type' => LocationType::InPerson,
        'location_detail' => '12 Main St',
        'notes' => 'Bringing my co-signer.',
    ]);

    $booking->hosts()->attach($this->host);
    $booking->answers()->create([
        'event_type_question_id' => $question->id,
        'label' => 'Best number',
        'answer' => '512-555-0100',
    ]);

    $automation = Automation::factory()->create([
        'team_id' => $this->team->id,
        'subject' => 'New booking: {{ event_name }}',
        'body' => "{{ invitee_first_name }} ({{ invitee_email }}, {{ invitee_phone }}) booked {{ event_name }}.\n{{ event_date }} at {{ event_time }}, {{ location }}, hosted by {{ event_organizer }}.\n{{ questions_and_answers }}\nNotes: {{ invitee_notes }}\n{{ not_a_variable }}",
    ]);

    $message = new AutomationMessage(
        $automation,
        $booking->load(['eventType', 'hosts', 'answers.question']),
        'America/Chicago',
    );

    expect($message->subject())->toBe('New booking: Leasing Team Meeting')
        ->and($message->body())->toBe(
            "Sam (sam@example.com, 512-555-0100) booked Leasing Team Meeting.\n"
            ."Wednesday, 2 September 2026 at 10:00am - 11:00am (America/Chicago), 12 Main St, hosted by Dana Reed.\n"
            ."Best number: 512-555-0100\n"
            ."Notes: Bringing my co-signer.\n"
            .'{{ not_a_variable }}',
        );
});

test('emailing someone else needs at least one address', function () {
    $this->actingAs($this->host)
        ->post(route('automations.store', ['current_team' => $this->team->slug]), [
            'name' => 'Tell nobody',
            'trigger' => AutomationTrigger::Booked->value,
            'channels' => ['email'],
            'recipient' => AutomationRecipient::Someone->value,
            'recipient_emails' => [],
            'subject' => 'New booking',
            'body' => 'A meeting was booked.',
        ])
        ->assertSessionHasErrors('recipient_emails');
});

test('the message keeps the formatting it was written with', function () {
    $booking = Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'team_id' => $this->team->id,
        'user_id' => $this->host->id,
        'name' => 'Sam Rivera',
    ]);

    $automation = Automation::factory()->create([
        'team_id' => $this->team->id,
        'body' => "**{{ invitee_name }}** booked a meeting.\n\n- Where: {{ location }}\n- [Open the app](https://tx-bookings.test)",
    ]);

    $rendered = (string) (new AutomationEmail($automation, $booking))
        ->toMail(new AnonymousNotifiable)
        ->render();

    // Laravel inlines the theme's CSS, so the tags carry a style attribute.
    expect($rendered)->toContain('>Sam Rivera</strong>')
        ->and($rendered)->toContain('<li')
        ->and($rendered)->toContain('>Open the app</a>');
});

test('a reply to a workflow email reaches a person, not the shared mailbox', function () {
    $booking = Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'team_id' => $this->team->id,
        'user_id' => $this->host->id,
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
    ]);

    $booking->hosts()->attach($this->host);

    $automation = Automation::factory()->create(['team_id' => $this->team->id]);
    $notification = new AutomationEmail($automation, $booking->fresh(['hosts']));

    // Emailed to someone who is not a host: they reply to whoever is hosting.
    expect($notification->toMail(new AnonymousNotifiable)->replyTo)
        ->toBe([['dana@example.test', 'Dana Reed']]);

    // Emailed to a host: they reply to the invitee.
    expect($notification->toMail($this->host)->replyTo)
        ->toBe([['sam@example.com', 'Sam Rivera']]);
});

test('a plain member of another organization cannot create a workflow in this one', function () {
    $stranger = User::factory()->create();
    $otherTeam = Team::factory()->create();
    $stranger->switchTeam($otherTeam);

    $this->actingAs($stranger)
        ->post(route('automations.store', ['current_team' => $this->team->slug]), [
            'name' => 'Sneaky',
            'trigger' => AutomationTrigger::Booked->value,
            'channels' => ['email'],
            'recipient' => AutomationRecipient::Host->value,
            'subject' => 'Hello',
            'body' => 'Hello',
        ])
        ->assertForbidden();
});
