<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Scheduling\SaveAvailabilityScheduleRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * A host's personal hours as another application saves them.
 *
 * The same weekly blocks, days off and overlap check as the settings form,
 * minus what the API does not get to choose: the schedule is always the
 * host's default personal one, named and kept active by the host.
 */
class SaveHostScheduleRequest extends SaveAvailabilityScheduleRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        unset($rules['name'], $rules['is_default'], $rules['is_shared'], $rules['is_active']);

        return [
            ...$rules,
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'rules' => ['present', 'array', 'max:70'],
            'overrides' => ['sometimes', 'array', 'max:200'],
        ];
    }
}
