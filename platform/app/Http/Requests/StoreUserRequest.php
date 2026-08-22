<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the admin create-user form.
 *
 * Passwords enforce a minimum length of 12 characters with the platform's
 * standard complexity rules (upper, lower, digit, symbol) — this matches
 * the registration form so admins cannot create accounts weaker than
 * self-registered ones.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:180'],
            'email' => ['required', 'email', 'max:180', 'unique:users,email'],
            'password' => [
                'required',
                'string',
                'min:12',
                'max:128',
                'confirmed',
                'regex:/[A-Z]/',       // uppercase
                'regex:/[a-z]/',       // lowercase
                'regex:/[0-9]/',       // digit
                'regex:/[^A-Za-z0-9]/', // symbol
            ],
            'role' => ['required', 'string', Rule::in(['admin', 'analyst', 'client', 'auditor'])],
            'quota_scans_per_day' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function validatedUserData(): array
    {
        $data = $this->safe()->only(['name', 'email', 'password', 'quota_scans_per_day', 'is_active']);
        $data['password'] = $this->safe()->input('password');
        $data['is_active'] = (bool) $this->safe()->input('is_active', true);
        $data['quota_scans_per_day'] = (int) $this->safe()->input(
            'quota_scans_per_day',
            \App\Models\User::DEFAULT_QUOTA_SCANS_PER_DAY,
        );

        return $data;
    }
}
