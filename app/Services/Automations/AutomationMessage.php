<?php

namespace App\Services\Automations;

use App\Models\Automation;
use App\Models\Booking;
use App\Models\BookingAnswer;

/**
 * Fills the {{ variables }} in an automation's subject and body with the
 * details of the booking it is being sent about.
 *
 * The variable names mirror Calendly's editor one for one, so a workflow
 * copied across from there reads the same.
 */
class AutomationMessage
{
    public function __construct(
        protected Automation $automation,
        protected Booking $booking,
        protected string $timezone,
        protected bool $markdown = true,
    ) {
        //
    }

    /**
     * Get the variables the editor offers, with what each one renders.
     *
     * @return array<int, array{name: string, description: string}>
     */
    public static function variables(): array
    {
        return [
            ['name' => 'event_name', 'description' => 'The event type that was booked'],
            ['name' => 'event_organizer', 'description' => 'The hosts of the meeting'],
            ['name' => 'event_date', 'description' => 'The day the meeting falls on'],
            ['name' => 'event_time', 'description' => 'The start and end time, with timezone'],
            ['name' => 'invitee_name', 'description' => 'The invitee\'s full name'],
            ['name' => 'invitee_first_name', 'description' => 'The invitee\'s first name'],
            ['name' => 'invitee_last_name', 'description' => 'The invitee\'s last name'],
            ['name' => 'invitee_email', 'description' => 'The invitee\'s email address'],
            ['name' => 'invitee_phone', 'description' => 'The phone number asked for when booking, if any'],
            ['name' => 'location', 'description' => 'The meeting link, address or phone number'],
            ['name' => 'event_description', 'description' => 'The event type\'s own description'],
            ['name' => 'questions_and_answers', 'description' => 'Every booking question and what was answered'],
        ];
    }

    /**
     * Get the subject line with its variables filled in.
     */
    public function subject(): string
    {
        return $this->render((string) $this->automation->subject);
    }

    /**
     * Get the body with its variables filled in.
     *
     * This is markdown, written by the organizer: it goes into the mail view
     * as the message's whole content, so their bold, links and lists survive.
     */
    public function body(): string
    {
        return trim($this->render((string) $this->automation->body));
    }

    /**
     * Get the text message with its variables filled in.
     *
     * Plain text: a phone shows markdown as the asterisks they are, so the
     * editor asks for the wording separately rather than reusing the email's.
     */
    public function smsBody(): string
    {
        return trim($this->render((string) $this->automation->sms_body));
    }

    /**
     * Replace every known variable in a piece of text.
     *
     * An unrecognised name is deliberately left alone: it is far easier to
     * spot a stray {{ invite_name }} in a test send than a silent blank.
     */
    protected function render(string $text): string
    {
        $values = $this->values();

        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/i',
            fn (array $matches) => $values[strtolower($matches[1])] ?? $matches[0],
            $text,
        );
    }

    /**
     * Work out what each variable stands for on this booking.
     *
     * @return array<string, string>
     */
    protected function values(): array
    {
        $booking = $this->booking;
        $startsAt = $booking->starts_at->setTimezone($this->timezone);
        $endsAt = $booking->ends_at->setTimezone($this->timezone);
        $name = trim($booking->name);

        return [
            'event_name' => $booking->eventType->name ?? '',
            'event_organizer' => $booking->attendingHosts()->pluck('name')->implode(', '),
            'event_date' => $startsAt->format('l, j F Y'),
            'event_time' => $startsAt->format('g:ia').' - '.$endsAt->format('g:ia').' ('.$this->timezone.')',
            'invitee_name' => $name,
            'invitee_first_name' => str($name)->before(' ')->toString(),
            'invitee_last_name' => str($name)->contains(' ') ? str($name)->after(' ')->toString() : '',
            'invitee_email' => $booking->email,
            'invitee_phone' => $this->inviteePhone(),
            'location' => $this->location(),
            'event_description' => trim((string) ($booking->eventType->description ?? '')),
            'questions_and_answers' => $this->questionsAndAnswers(),
        ];
    }

    /**
     * Get the invitee's phone number, from wherever it was collected.
     *
     * A phone question is the usual place, but an event type that has the host
     * call the invitee asks for the number as the location instead. Neither is
     * compulsory, so this is often blank.
     */
    protected function inviteePhone(): string
    {
        return (string) $this->booking->inviteePhone();
    }

    /**
     * Get the joining details, falling back to the kind of meeting it is.
     */
    protected function location(): string
    {
        return $this->booking->meeting_url
            ?: $this->booking->location_detail
            ?: $this->booking->location_type->shortLabel();
    }

    /**
     * Get the booking questions alongside what the invitee answered, one per
     * line, the way the reminder email lists them.
     *
     * In an email they are joined with a markdown hard break rather than a
     * bare newline, which markdown would swallow into the surrounding
     * paragraph. A text message takes the newline as it is.
     */
    protected function questionsAndAnswers(): string
    {
        return $this->booking->answers
            ->filter(fn (BookingAnswer $answer) => filled($answer->answer))
            ->map(fn (BookingAnswer $answer) => $answer->label.': '.$answer->answer)
            ->implode($this->markdown ? "  \n" : "\n");
    }
}
