<?php

namespace App\Http\Requests\Admin;

use App\Enums\TeamPlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeamPlanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('accessAdminConsole');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'plan' => ['nullable', Rule::enum(TeamPlan::class)],
            'plan_note' => ['nullable', 'string', 'max:2000'],
            // Null puts the organization on the platform address.
            'mail_from_address' => ['nullable', 'string', Rule::in((array) config('mail.org_senders'))],
        ];
    }
}
