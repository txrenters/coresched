<?php

namespace App\Http\Requests\Teams;

use App\Models\Team;
use App\Services\Scheduling\HolidayCalendar;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeamHolidaysRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $team = $this->route('team');

        return $team instanceof Team && (bool) $this->user()?->can('update', $team);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $holidays = app(HolidayCalendar::class);
        $country = $this->input('holiday_country');

        return [
            'holiday_country' => ['nullable', 'string', Rule::in(array_column($holidays->countries(), 'value'))],
            'holidays' => ['array'],
            'holidays.*' => [
                'string',
                Rule::in(is_string($country) ? $holidays->forCountry($country)->pluck('key')->all() : []),
            ],
        ];
    }
}
