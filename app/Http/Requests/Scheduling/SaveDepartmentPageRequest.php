<?php

namespace App\Http\Requests\Scheduling;

use App\Models\DepartmentPage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveDepartmentPageRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $page = $this->route('department_page');
        $teamId = $page instanceof DepartmentPage ? $page->team_id : $this->user()->current_team_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'group_ids' => ['required', 'array', 'min:1'],
            'group_ids.*' => [
                'integer',
                Rule::exists('groups', 'id')->where('team_id', $teamId),
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
            'group_ids.required' => 'Pick at least one team for this page.',
            'group_ids.min' => 'Pick at least one team for this page.',
        ];
    }
}
