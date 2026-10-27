<?php

namespace App\Http\Requests\Teams;

use App\Models\Team;
use App\Rules\TeamName;
use App\Services\Sms\TwilioClient;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveTeamRequest extends FormRequest
{
    /**
     * The shape of a Twilio Account SID.
     */
    protected const ACCOUNT_SID_PATTERN = '/^AC[0-9a-fA-F]{32}$/';

    /**
     * Determine if the user is authorized to make this request.
     *
     * Checked here, before validation, and not only in the controller: the
     * rules below call Twilio with the organization's saved credentials (its
     * numbers, a credential check), so someone without the right to change
     * the organization must be turned away before any of that runs, or the
     * validation errors would tell them which numbers its account owns.
     */
    public function authorize(): bool
    {
        $team = $this->team();

        return $team !== null
            ? (bool) $this->user()?->can('update', $team)
            : (bool) $this->user()?->can('create', Team::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Everything past the name is organization branding, which only the update
     * path sends — creation just names the organization.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', new TeamName],
            'welcome_message' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'website_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'timezone' => ['sometimes', 'nullable', 'timezone'],
            /*
             * The organization's own Twilio account. The token is write-only:
             * the page never receives it, so a blank one means "keep the saved
             * token", and remove_twilio is the only way to clear it.
             */
            'twilio_account_sid' => ['sometimes', 'nullable', 'string', 'regex:'.self::ACCOUNT_SID_PATTERN],
            'twilio_auth_token' => ['sometimes', 'nullable', 'string', 'max:255'],
            'remove_twilio' => ['sometimes', 'boolean'],
            /*
             * Only a number the organization's Twilio account actually owns:
             * the list is what the picker offers, and a number typed anywhere
             * else would fail at send time rather than here. When the account
             * cannot be reached the only allowed answer is "none", so a hiccup
             * cannot silently point an organization at a number nobody has
             * checked.
             */
            'sms_from_number' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(array_column($this->twilioAfterSave()?->numbers() ?? [], 'number')),
            ],
            /**
             * SVG is deliberately excluded: logos are served from the app's own
             * origin, and an SVG can carry script.
             */
            'logo' => [
                'sometimes',
                'nullable',
                'image',
                'mimes:png,jpg,jpeg,webp',
                'max:2048',
                Rule::dimensions()->maxWidth(2000)->maxHeight(2000),
            ],
            'remove_logo' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * New credentials are tried against Twilio before they are saved, so a
     * mistyped token is refused here instead of failing the first text.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || ! $this->changesTwilioCredentials()) {
                    return;
                }

                $twilio = $this->twilioAfterSave();

                if ($twilio === null) {
                    $validator->errors()->add('twilio_auth_token', __('Enter the Auth Token for this Account SID.'));

                    return;
                }

                if (! $twilio->credentialsAreValid()) {
                    $validator->errors()->add('twilio_auth_token', __('Twilio did not accept this Account SID and Auth Token.'));
                }
            },
        ];
    }

    /**
     * Determine whether this save disconnects Twilio: the explicit remove, or
     * an emptied Account SID with no token alongside it.
     */
    public function removesTwilio(): bool
    {
        return $this->boolean('remove_twilio')
            || ($this->has('twilio_account_sid') && blank($this->input('twilio_account_sid')) && blank($this->input('twilio_auth_token')));
    }

    /**
     * Determine whether this save sets a different Twilio account or token.
     */
    public function changesTwilioCredentials(): bool
    {
        if ($this->removesTwilio()) {
            return false;
        }

        $storedSid = $this->team()?->twilio_account_sid;

        return filled($this->input('twilio_auth_token'))
            || ($this->has('twilio_account_sid') && $this->input('twilio_account_sid') !== $storedSid);
    }

    /**
     * The Twilio account the organization will have once this save lands, or
     * null when it will have none.
     *
     * A changed Account SID never reuses the saved token, which belongs to
     * the old account. This runs on raw input (the number picker's rule is
     * built from it), so a SID that is not shaped like one never reaches
     * Twilio.
     */
    public function twilioAfterSave(): ?TwilioClient
    {
        if ($this->removesTwilio()) {
            return null;
        }

        $team = $this->team();
        $sid = $this->has('twilio_account_sid') ? $this->input('twilio_account_sid') : $team?->twilio_account_sid;
        $token = $this->input('twilio_auth_token');

        if (blank($token) && $sid === $team?->twilio_account_sid) {
            $token = $team?->twilio_auth_token;
        }

        if (! is_string($sid) || preg_match(self::ACCOUNT_SID_PATTERN, $sid) !== 1) {
            return null;
        }

        $twilio = new TwilioClient($sid, is_string($token) ? $token : null);

        return $twilio->isConfigured() ? $twilio : null;
    }

    /**
     * The organization being updated; null when one is being created.
     */
    protected function team(): ?Team
    {
        $team = $this->route('team');

        return $team instanceof Team ? $team : null;
    }

    /**
     * Get the validation messages that apply to the request.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo.mimes' => __('The logo must be a PNG, JPG or WebP image.'),
            'logo.max' => __('The logo must be 2 MB or smaller.'),
            'logo.dimensions' => __('The logo must be no larger than 2000 x 2000 pixels.'),
            'twilio_account_sid.regex' => __('A Twilio Account SID starts with AC followed by 32 letters and numbers.'),
        ];
    }
}
