<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the create-project form.
 *
 * Scope config is sent as three parallel arrays (allowed_domains[],
 * allowed_ips[], excluded_paths[]) plus the standard project metadata.
 * The authorization document is an optional file upload restricted to
 * a small whitelist of extensions to mitigate malicious uploads.
 */
class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'client_name' => ['nullable', 'string', 'max:180'],
            'branding_color' => ['nullable', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'status' => ['nullable', 'string', 'in:draft,active,paused,completed,archived'],
            'scope_config.allowed_domains' => ['nullable', 'array'],
            'scope_config.allowed_domains.*' => ['string', 'max:255'],
            'scope_config.allowed_ips' => ['nullable', 'array'],
            'scope_config.allowed_ips.*' => ['string', 'max:64'],
            'scope_config.excluded_paths' => ['nullable', 'array'],
            'scope_config.excluded_paths.*' => ['string', 'max:255'],
            'authorization_document' => [
                'nullable',
                'file',
                'mimes:pdf,doc,docx,png,jpg,jpeg',
                'max:10240',
            ],
        ];
    }

    /**
     * Merge scope_config arrays into a single associative structure
     * so it can be stored directly into the project's JSONB column.
     *
     * @return array<string,mixed>
     */
    public function validatedProjectData(): array
    {
        $data = collect($this->safe()->except(['scope_config', 'authorization_document']))
            ->filter()
            ->all();

        $scope = $this->safe()->input('scope_config', []);
        if (is_array($scope) && ! empty($scope)) {
            $data['scope_config'] = [
                'allowed_domains' => array_values(array_filter((array) ($scope['allowed_domains'] ?? []))),
                'allowed_ips' => array_values(array_filter((array) ($scope['allowed_ips'] ?? []))),
                'excluded_paths' => array_values(array_filter((array) ($scope['excluded_paths'] ?? []))),
            ];
        }

        return $data;
    }
}
