<?php

namespace Database\Factories;

use App\Enums\AutomationChannel;
use App\Enums\AutomationRecipient;
use App\Enums\AutomationTrigger;
use App\Models\Automation;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Automation>
 */
class AutomationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => 'Email reminder to someone else',
            'trigger' => AutomationTrigger::Booked,
            'offset_minutes' => null,
            'recipient' => AutomationRecipient::Someone,
            'channels' => [AutomationChannel::Email],
            'recipient_emails' => [fake()->unique()->safeEmail()],
            'recipient_phones' => null,
            'subject' => 'New booking: {{ event_name }}',
            'body' => '{{ invitee_name }} booked {{ event_name }} on {{ event_date }} at {{ event_time }}.',
            'is_active' => true,
        ];
    }

    /**
     * Send the given number of minutes before the meeting starts.
     */
    public function beforeStart(int $minutes): static
    {
        return $this->state(fn (array $attributes) => [
            'trigger' => AutomationTrigger::BeforeStart,
            'offset_minutes' => $minutes,
        ]);
    }

    /**
     * Send the given number of minutes after the meeting ends.
     */
    public function afterEnd(int $minutes): static
    {
        return $this->state(fn (array $attributes) => [
            'trigger' => AutomationTrigger::AfterEnd,
            'offset_minutes' => $minutes,
        ]);
    }

    /**
     * Send when the invitee is marked as a no-show.
     */
    public function onNoShow(): static
    {
        return $this->state(fn (array $attributes) => [
            'trigger' => AutomationTrigger::NoShow,
            'offset_minutes' => null,
        ]);
    }

    /**
     * Send to the meeting's hosts rather than an address typed in.
     */
    public function toHosts(): static
    {
        return $this->state(fn (array $attributes) => [
            'recipient' => AutomationRecipient::Host,
            'recipient_emails' => null,
        ]);
    }

    /**
     * Send to the invitee and their guests.
     */
    public function toInvitee(): static
    {
        return $this->state(fn (array $attributes) => [
            'recipient' => AutomationRecipient::Invitee,
            'recipient_emails' => null,
        ]);
    }

    /**
     * Send a text message instead of an email.
     */
    public function byText(string $to = '+15125550100'): static
    {
        return $this->state(fn (array $attributes) => [
            'channels' => [AutomationChannel::Sms],
            'recipient_emails' => null,
            'recipient_phones' => [$to],
            'sms_body' => '{{ invitee_name }} booked {{ event_name }} on {{ event_date }}.',
        ]);
    }

    /**
     * Send both an email and a text message.
     */
    public function byEmailAndText(): static
    {
        return $this->state(fn (array $attributes) => [
            'channels' => [AutomationChannel::Email, AutomationChannel::Sms],
            'recipient_phones' => ['+15125550100'],
            'sms_body' => '{{ invitee_name }} booked {{ event_name }}.',
        ]);
    }

    /**
     * Indicate that the automation is switched off.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
