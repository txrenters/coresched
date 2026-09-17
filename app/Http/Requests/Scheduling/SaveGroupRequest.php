<?php

namespace App\Http\Requests\Scheduling;

use App\Models\Group;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveGroupRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $group = $this->route('group');
        $teamId = $group instanceof Group ? $group->team_id : $this->user()->current_team_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            // Only hours the organization shares: a person's own are theirs.
            'availability_schedule_id' => [
                'nullable',
                'integer',
                Rule::exists('availability_schedules', 'id')->where('team_id', $teamId),
            ],
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => [
                'integer',
                Rule::exists('team_members', 'user_id')->where('team_id', $teamId),
            ],
            'page_event_type_ids' => ['nullable', 'array'],
            'page_event_type_ids.*' => [
                'integer',
                Rule::exists('event_types', 'id')->where('team_id', $teamId)->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * Get the custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'member_ids.required' => 'Pick at least one member for this group.',
            'member_ids.min' => 'Pick at least one member for this group.',
        ];
    }
}
