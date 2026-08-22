<?php

namespace App\Http\Requests;

use App\Models\Scan;
use App\Models\Target;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the create-scan form (the dispatch entry-point).
 *
 * The validation enforces the platform's safety invariants:
 *   - The selected scan type is one of the supported catalogues.
 *   - The target must exist and belong to the selected project.
 *   - The target must be `authorized`, unless the acting user is an admin.
 *   - Aggressive profiles require an explicit confirmation flag.
 *   - The acting user must still have hourly + daily scan quota.
 */
class StoreScanRequest extends FormRequest
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
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'target_id' => ['required', 'integer', 'exists:targets,id'],
            'scan_type' => ['required', 'string', Rule::in(Scan::ALL_TYPES)],
            'profile' => ['required', 'string', Rule::in(array_keys(Scan::PROFILES))],
            'config' => ['nullable', 'array'],
            'config.ports' => ['nullable', 'string', 'max:255'],
            'config.exclusions' => ['nullable', 'array'],
            'config.exclusions.*' => ['string', 'max:255'],
            'config.custom_flags' => ['nullable', 'string', 'max:2000'],
            'aggressive_confirmed' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Configure the validator instance after creation.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            $user = $this->user();
            if (! $user) {
                return;
            }

            $target = Target::find($this->input('target_id'));
            if (! $target) {
                return; // existence already enforced by the exists rule.
            }

            // Target must belong to the selected project.
            if ((int) $target->project_id !== (int) $this->input('project_id')) {
                $validator->errors()->add('target_id', 'The selected target does not belong to this project.');
            }

            // Project ownership / admin override.
            $project = $target->project;
            if (! $project) {
                return;
            }
            if (! $user->isAdmin() && (int) $project->user_id !== (int) $user->id) {
                $validator->errors()->add('project_id', 'You do not own this project.');
            }

            // Authorization window.
            if (! $target->is_authorized && ! $user->isAdmin()) {
                $validator->errors()->add('target_id', 'Target is not authorized for scanning.');
            }

            // Aggressive profile requires explicit confirmation.
            if ($this->input('profile') === 'aggressive' && ! $this->boolean('aggressive_confirmed')) {
                $validator->errors()->add(
                    'aggressive_confirmed',
                    'Aggressive profile requires explicit confirmation.'
                );
            }

            // Daily quota (admins bypass).
            if (! $user->isAdmin() && ! $user->hasQuotaLeft()) {
                $validator->errors()->add('scan_type', 'Daily scan quota exceeded.');
            }
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function validatedScanData(): array
    {
        $config = $this->safe()->input('config', []);
        $target = Target::find($this->safe()->input('target_id'));

        return [
            'project_id' => (int) $this->safe()->input('project_id'),
            'target_id' => (int) $this->safe()->input('target_id'),
            'type' => (string) $this->safe()->input('scan_type'),
            'target_url' => $target?->domain_url ?? '',
            'profile' => (string) $this->safe()->input('profile'),
            'config' => is_array($config) ? $config : [],
            'status' => Scan::STATUS_QUEUED,
            'attempt' => 0,
            'max_attempts' => 3,
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'queued_at' => now(),
        ];
    }
}
