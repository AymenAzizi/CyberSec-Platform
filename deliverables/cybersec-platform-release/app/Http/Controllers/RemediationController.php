<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateRemediation;
use App\Models\Finding;
use App\Models\RemediationScript;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/**
 * Remediation-as-Code controller.
 *
 * For each finding, the platform can request the AI service to generate
 * one or more remediation scripts (bash, ansible, dockerfile, terraform).
 * Scripts are persisted in the `generated` status and progress through
 * the `verified` → `applied` lifecycle as the analyst reviews them.
 *
 * Verification is intentionally lightweight at this stage — a full
 * sandboxed execution would require spinning up an isolated container,
 * which is already the responsibility of the security microservice's
 * sandbox module. The controller therefore marks the script as verified
 * when the analyst confirms they have tested it manually, and as applied
 * when they confirm it has been rolled out to production.
 *
 * Route param names: `{finding}` and `{script}` — match the controller's
 * method params for implicit route-model binding.
 */
class RemediationController extends Controller
{
    public function show(Request $request, Finding $finding)
    {
        $this->authorizeFinding($request->user(), $finding);

        $finding->load(['scan', 'project', 'target', 'remediationScripts.user']);

        return view('remediation.show', compact('finding'));
    }

    public function generate(Request $request, Finding $finding)
    {
        $this->authorizeFinding($request->user(), $finding);

        // Run synchronously when queue worker isn't available (sandbox mode).
        try {
            $job = new GenerateRemediation($finding, $request->user()->id);
            $job->handle(app(\App\Services\MicroserviceClient::class));
            $status = 'Remediation scripts generated successfully.';
        } catch (\Throwable $e) {
            report($e);
            $status = 'Remediation generation failed: '.$e->getMessage();
        }

        AuditLogger::log($request->user(), 'remediation.generation_requested', $finding);

        return redirect()->route('remediation.show', $finding)
            ->with('status', __($status));
    }

    public function downloadScript(Request $request, RemediationScript $script)
    {
        $this->authorizeScript($request->user(), $script);

        $filename = $this->scriptFilename($script);
        $mimeType = $this->scriptMimeType($script->language);

        return response($script->code, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => strlen($script->code),
        ]);
    }

    public function verify(Request $request, RemediationScript $script)
    {
        $this->authorizeScript($request->user(), $script);

        if (! in_array($script->status, [RemediationScript::STATUS_GENERATED], true)) {
            return back()->with('error', __('Only freshly-generated scripts can be verified.'));
        }

        $script->update([
            'status' => RemediationScript::STATUS_VERIFIED,
            'verified_at' => now(),
            'verification_log' => $request->input('log', 'Manually verified by '.$request->user()->name),
        ]);

        AuditLogger::log($request->user(), 'remediation.verified', $script);

        return back()->with('status', __('Script marked as verified.'));
    }

    public function apply(Request $request, RemediationScript $script)
    {
        $this->authorizeScript($request->user(), $script);

        if (! in_array($script->status, [RemediationScript::STATUS_VERIFIED], true)) {
            return back()->with('error', __('Only verified scripts can be applied.'));
        }

        $script->update([
            'status' => RemediationScript::STATUS_APPLIED,
        ]);

        // If the finding still had an open status, advance it to resolved.
        $finding = $script->finding;
        if ($finding && in_array($finding->status, [Finding::STATUS_NEW, Finding::STATUS_TRIAGED, Finding::STATUS_REMEDIATING], true)) {
            $finding->update([
                'status' => Finding::STATUS_RESOLVED,
                'verified_at' => now(),
                'verified_by' => $request->user()->email,
            ]);
        }

        AuditLogger::log($request->user(), 'remediation.applied', $script);

        return back()->with('status', __('Script applied. Finding marked as resolved.'));
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function authorizeFinding($user, Finding $finding): void
    {
        if ($user->isAdmin() || $user->isAuditor()) {
            return;
        }

        $project = $finding->project;
        abort_unless($project && (int) $project->user_id === (int) $user->id, 403, 'You do not have access to this finding.');
    }

    protected function authorizeScript($user, RemediationScript $script): void
    {
        if ($user->isAdmin() || $user->isAuditor()) {
            return;
        }

        $project = $script->finding?->project;
        abort_unless($project && (int) $project->user_id === (int) $user->id, 403, 'You do not have access to this script.');
    }

    protected function scriptFilename(RemediationScript $script): string
    {
        $extension = match ($script->language) {
            RemediationScript::LANG_BASH => 'sh',
            RemediationScript::LANG_ANSIBLE => 'yml',
            RemediationScript::LANG_DOCKERFILE => 'dockerfile',
            RemediationScript::LANG_TERRAFORM => 'tf',
            RemediationScript::LANG_KUBERNETES => 'yaml',
            RemediationScript::LANG_PYTHON => 'py',
            default => 'txt',
        };

        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $script->title ?: 'remediation');
        $base = trim($base, '-');

        return $base.'-'.$script->id.'.'.$extension;
    }

    protected function scriptMimeType(string $language): string
    {
        return match ($language) {
            RemediationScript::LANG_BASH => 'text/x-shellscript',
            RemediationScript::LANG_PYTHON => 'text/x-python',
            RemediationScript::LANG_ANSIBLE, RemediationScript::LANG_KUBERNETES => 'text/yaml',
            RemediationScript::LANG_DOCKERFILE => 'text/x-dockerfile',
            RemediationScript::LANG_TERRAFORM => 'text/x-hcl',
            default => 'text/plain',
        };
    }
}
