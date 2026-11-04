<?php

namespace App\Http\Requests\Marketing;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContactSalesRequest extends FormRequest
{
    /**
     * The team-size choices offered on the pricing page.
     *
     * @var list<string>
     */
    public const TEAM_SIZES = ['1-10', '11-50', '51-200', '200+'];

    /**
     * Get the validation rules that apply to the request.
     *
     * `website` is a honeypot: the field is hidden from people, so only a bot
     * fills it. It is validated as a string here and checked in the controller,
     * which answers a filled one with the normal success so the bot learns
     * nothing.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'company' => ['required', 'string', 'max:255'],
            'team_size' => ['required', Rule::in(self::TEAM_SIZES)],
            'plan' => ['nullable', 'string', Rule::in(['team', 'business', 'enterprise'])],
            'message' => ['nullable', 'string', 'max:5000'],
            'website' => ['nullable', 'string'],
        ];
    }

    /**
     * Get the inquiry as submitted, without the honeypot field.
     *
     * @return array{name: string, email: string, company: string, team_size: string, plan: string|null, message: string|null}
     */
    public function inquiry(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'email' => $this->string('email')->toString(),
            'company' => $this->string('company')->toString(),
            'team_size' => $this->string('team_size')->toString(),
            'plan' => $this->filled('plan') ? $this->string('plan')->toString() : null,
            'message' => $this->filled('message') ? $this->string('message')->toString() : null,
        ];
    }
}
