<?php

namespace App\Http\Requests\Scheduling;

use App\Enums\QuestionType;
use App\Models\EventType;
use App\Services\Scheduling\BookingPageResolver;
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

                if ($eventType->location_type->requiresInviteeInput() && blank($this->input('location_detail'))) {
                    $validator->errors()->add('location_detail', 'Add the number we should call you on.');
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
                     * A phone question asks for a number, so a sentence is not
                     * an answer to it. Deliberately loose about SHAPE -- digits,
                     * spaces, + ( ) - and at least seven digits -- because
                     * numbers are written differently around the world and this
                     * is a booking form, not a carrier.
                     */
                    if ($question->type === QuestionType::Phone) {
                        if (is_array($answer)) {
                            $validator->errors()->add("answers.{$question->id}", 'Enter a phone number.');

                            continue;
                        }

                        $digits = preg_replace('/\D/', '', (string) $answer);

                        if (mb_strlen((string) $digits) < 7 || preg_match('/[^0-9+()\s.\-]/', (string) $answer)) {
                            $validator->errors()->add("answers.{$question->id}", 'Enter a phone number.');
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
