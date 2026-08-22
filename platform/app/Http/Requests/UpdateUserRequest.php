<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the admin edit-user form.
 *
 * The password is optional on update; when provided it must still meet
 * the platform's complexity rules. The email uniqueness is scoped to
 * ignore the user being edited.
 */
class UpdateUserRequest extends FormRequest
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
        $userId = $this->route('user')?->id ?? null;

        return [
            'name' => ['sometimes', 'required', 'string', 'min:3', 'max:180'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:180',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'password' => [
                'nullable',
                'string',
                'min:12',
                'max:128',
                'confirmed',
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
                'regex:/[^A-Za-z0-9]/',
            ],
            'role' => ['sometimes', 'required', 'string', Rule::in(['admin', 'analyst', 'client', 'auditor'])],
            'quota_scans_per_day' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function validatedUserData(): array
    {
        $data = $this->safe()->except(['password', 'password_confirmation', 'role']);
        if ($this->safe()->has('password') && filled($this->safe()->input('password'))) {
            $data['password'] = $this->safe()->input('password');
        }

        return $data;
    }
}
