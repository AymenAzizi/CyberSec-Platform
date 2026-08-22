<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the update-project form.
 *
 * The name uniqueness is scoped to the current user, so two different
 * owners may legitimately have projects with the same name. Updates
 * ignore the authorization document file when not re-uploaded.
 */
class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project !== null
            && $this->user() !== null
            && ($this->user()->isAdmin() || (int) $project->user_id === (int) $this->user()->id);
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:3', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'client_name' => ['nullable', 'string', 'max:180'],
            'branding_color' => ['nullable', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'expires_at' => ['nullable', 'date'],
            'status' => ['sometimes', 'string', Rule::in(['draft', 'active', 'paused', 'completed', 'archived'])],
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
