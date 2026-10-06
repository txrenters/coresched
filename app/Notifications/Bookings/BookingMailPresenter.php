<?php

namespace App\Notifications\Bookings;

use App\Models\Booking;
use App\Models\BookingAnswer;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Formats booking details for whichever party is being emailed, so times
 * always land in the reader's own timezone.
 */
class BookingMailPresenter
{
    public function __construct(
        protected Booking $booking,
        protected object $notifiable,
    ) {
        //
    }

    /**
     * Get the recipient as a host, or null when the invitee is being emailed.
     */
    public function hostRecipient(): ?User
    {
        return $this->notifiable instanceof User ? $this->notifiable : null;
    }

    /**
     * Determine if the email is going to one of the hosts.
     */
    public function isHost(): bool
    {
        return $this->hostRecipient() !== null;
    }

    /**
     * Get the name to greet the recipient by.
     */
    public function recipientName(): string
    {
        $host = $this->hostRecipient();
        $name = $host !== null ? $host->name : $this->booking->name;

        return str($name)->before(' ')->toString();
    }

    /**
     * Get the timezone the recipient reads times in.
     */
    public function timezone(): string
    {
        $host = $this->hostRecipient();
        $timezone = $host !== null ? $host->timezone : $this->booking->invitee_timezone;

        return $timezone ?: config('scheduling.default_timezone');
    }

    /**
     * Get the meeting time formatted for the recipient.
     */
    public function when(): string
    {
        $timezone = $this->timezone();

        return $this->booking->starts_at->setTimezone($timezone)->format('l, j F Y \a\t g:ia')
            .' - '.$this->booking->ends_at->setTimezone($timezone)->format('g:ia')
            .' ('.$timezone.')';
    }

    /**
     * Get the line summarising who is meeting whom.
     */
    public function participants(): string
    {
        $hosts = $this->booking->hosts->isNotEmpty()
            ? $this->booking->hosts->pluck('name')
            : collect([$this->booking->host->name]);

        return $hosts->implode(', ').' and '.$this->booking->name;
    }

    /**
     * Get the opening line for a confirmation email.
     */
    public function confirmationLine(): string
    {
        return $this->isHost()
            ? $this->booking->name.' booked '.$this->booking->eventType->name.' with you.'
            : 'Your '.$this->booking->eventType->name.' is confirmed.';
    }

    /**
     * Get the joining details for the meeting.
     */
    public function location(): ?string
    {
        return $this->booking->meeting_url ?: $this->booking->location_detail;
    }

    /**
     * Get the lines describing where the meeting happens.
     *
     * A joining link becomes a button: a Teams URL runs to hundreds of
     * characters and reads as noise. An address or phone number stays as text.
     *
     * @return array<int, string|HtmlString>
     */
    public function locationLines(): array
    {
        $location = $this->location();

        if (blank($location)) {
            return [];
        }

        if (! Str::startsWith($location, ['https://', 'http://'])) {
            return ['**Where:** '.$location];
        }

        $place = $this->booking->location_type->isGenerated()
            ? $this->booking->location_type->label()
            : 'Online meeting';

        return [
            '**Where:** '.$place,
            new HtmlString(view('mail.join-button', ['url' => $location, 'label' => 'Join meeting'])->render()),
        ];
    }

    /**
     * Get the event type's own description, which says what the meeting is
     * for in the organiser's words.
     */
    public function eventDescription(): ?string
    {
        $description = trim((string) $this->booking->eventType->description);

        return $description === '' ? null : $description;
    }

    /**
     * Get the invitee's contact details.
     *
     * Only the hosts see them: they are the ones who may need to reach out
     * beforehand, and reading their own address back to the invitee adds
     * nothing.
     *
     * @return array<int, string>
     */
    public function inviteeLines(): array
    {
        if (! $this->isHost()) {
            return [];
        }

        return ['**Invitee:** '.$this->booking->name.' ('.$this->booking->email.')'];
    }

    /**
     * Get the booking questions alongside what the invitee answered.
     *
     * @return array<int, string>
     */
    public function answerLines(): array
    {
        $lines = $this->booking->answers
            ->filter(fn (BookingAnswer $answer) => filled($answer->answer))
            ->map(fn (BookingAnswer $answer) => '**'.$answer->label.'** '.$answer->answer)
            ->values()
            ->all();

        if (filled($this->booking->notes)) {
            $lines[] = '**Notes** '.$this->booking->notes;
        }

        return $lines;
    }

    /**
     * Get the addresses a reply should go to.
     *
     * Everything sends as the scheduling mailbox, which is shared, has no
     * password and nobody reads -- so a reply to the From address is lost.
     * Point it at the other party instead: a host hears back from the invitee,
     * and the invitee reaches whoever is hosting them.
     *
     * @return array<int, array{address: string, name: string}>
     */
    public function replyToAddresses(): array
    {
        if ($this->isHost()) {
            return [['address' => $this->booking->email, 'name' => $this->booking->name]];
        }

        return $this->booking->attendingHosts()
            ->map(fn (User $host) => ['address' => $host->email, 'name' => $host->name])
            ->values()
            ->all();
    }

    /**
     * Get the organization the email is sent on behalf of, for its header.
     *
     * @return array{name: string, url: string}
     */
    public function organization(): array
    {
        $team = $this->booking->team;

        return [
            'name' => $team->name,
            'url' => $team->slug === null ? config('app.url') : route('book.page', ['page' => $team->slug]),
        ];
    }

    /**
     * Brand the email as the organization's and point replies at a person.
     *
     * Only the display name changes: the address stays the scheduling mailbox,
     * which is the one Graph is allowed to send as. Every booking email needs
     * this, including the ones with no footer (canceled, declined): a reply to
     * the shared mailbox is never read.
     */
    public function withSender(MailMessage $message): MailMessage
    {
        $message->from(config('mail.from.address'), $this->booking->team->name);

        foreach ($this->replyToAddresses() as $replyTo) {
            $message->replyTo($replyTo['address'], $replyTo['name']);
        }

        $message->viewData = [...$message->viewData, 'organization' => $this->organization()];

        return $message;
    }

    /**
     * Add the sender details and the manage links appropriate to the recipient.
     *
     * Only the host gets a link. An invitee is deliberately offered no way to
     * reschedule or cancel themselves -- changes go through the organizer, so
     * the confirmation is a statement rather than a menu. The routes still
     * exist and still work: the host's own Meetings page uses them, and a link
     * handed out before this change must not start 404ing.
     */
    public function withFooter(MailMessage $message): MailMessage
    {
        $this->withSender($message);

        if ($this->isHost()) {
            return $message->action('View booking', route('meetings.index', ['current_team' => $this->booking->team->slug]));
        }

        return $message;
    }
}
