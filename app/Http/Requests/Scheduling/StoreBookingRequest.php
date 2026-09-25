<?php

namespace App\Http\Requests\Scheduling;

use App\Enums\QuestionType;
use App\Models\EventType;
use App\Models\SingleUseLink;
use App\Services\Scheduling\BookingPageResolver;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookingRequest extends FormRequest
{
    /**
     * The event type resolved from the route, cached for the request.
     */
    protected ?EventType $resolvedEventType = null;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'location_detail' => ['nullable', 'string', 'max:500'],
            'guests' => ['array', 'max:10'],
            'guests.*' => ['email', 'max:255'],
            'answers' => ['array'],
            'answers.*.*' => ['string', 'max:2000'],
            'host_id' => ['nullable', 'integer'],
            // Opaque to us: the caller that sent the invitee here reads it back.
            'reference' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,100}$/'],
        ];
    }

    /**
     * Resolve the event type being booked from the public page slugs.
     */
    public function eventType(): EventType
    {
        if ($this->resolvedEventType !== null) {
            return $this->resolvedEventType;
        }

        $link = $this->route('singleUseLink');

        if ($link instanceof SingleUseLink) {
            abort_if($link->eventType === null, 404);

            return $this->resolvedEventType = $link->eventType;
        }

        $resolver = app(BookingPageResolver::class);
        $page = $resolver->resolve((string) $this->route('page'));

        abort_if($page === null, 404);

        $eventType = $resolver->eventType($page, (string) $this->route('eventType'));

        abort_if($eventType === null, 404);

        return $this->resolvedEventType = $eventType;
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
                $eventType = $this->eventType();

                if ($eventType->location_type->requiresInviteeInput()) {
                    if (blank($this->input('location_detail'))) {
                        $validator->errors()->add('location_detail', 'Add the number we should call you on.');
                    } elseif (! PhoneNumber::isValid((string) $this->input('location_detail'))) {
                        $validator->errors()->add('location_detail', 'Enter a 10 digit phone number.');
                    }
                }

                $answers = $this->input('answers');
                $answers = is_array($answers) ? $answers : [];

                foreach ($eventType->questions as $question) {
                    $answer = $answers[$question->id] ?? null;

                    if ($question->is_required && blank($answer)) {
                        $validator->errors()->add("answers.{$question->id}", 'This field is required.');

                        continue;
                    }

                    // A list holding anything but text is already refused by
                    // the answers.*.* rule and cannot be checked any further.
                    if (blank($answer) || (is_array($answer) && count(array_filter($answer, 'is_string')) !== count($answer))) {
                        continue;
                    }

                    /*
                     * A phone question asks for a US number: the form puts the
                     * +1 in place and the invitee types the ten digits, so the
                     * answer has to be one PhoneNumber can dial. Punctuation is
                     * still the writer's business; CreateBooking stores E.164.
                     */
                    if ($question->type === QuestionType::Phone) {
                        if (is_array($answer)) {
                            $validator->errors()->add("answers.{$question->id}", 'Enter a phone number.');

                            continue;
                        }

                        if (preg_match('/[^0-9+()\s.\-]/', (string) $answer) || ! PhoneNumber::isValid((string) $answer)) {
                            $validator->errors()->add("answers.{$question->id}", 'Enter a 10 digit phone number.');
                        }

                        continue;
                    }

                    if (! $question->type->isConstrained()) {
                        continue;
                    }

                    // A question with a fixed set of answers only accepts those:
                    // the invitee picks from a list, so anything else was not
                    // typed into this form.
                    $allowed = $question->type->answerOptions($question->options ?? []);
                    $given = is_array($answer) ? $answer : [$answer];

                    if (array_diff($given, $allowed) !== []) {
                        $validator->errors()->add("answers.{$question->id}", 'Choose one of the offered answers.');
                    }
                }
            },
        ];
    }

    /**
     * Get the custom attribute names for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'location_detail' => 'phone number',
        ];
    }
}
