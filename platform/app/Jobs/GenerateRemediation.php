<?php

namespace App\Jobs;

use App\Models\Finding;
use App\Models\RemediationScript;
use App\Services\AuditLogger;
use App\Services\MicroserviceClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Asynchronously generate remediation scripts for a finding via the AI
 * service's `/remediation` endpoint.
 *
 * The AI service is expected to return one or more scripts in a normalised
 * JSON shape: `{ scripts: [{ title, language, code, explanation }] }`. Each
 * script becomes its own {@see RemediationScript} row attached to the
 * finding, in the `generated` lifecycle status — verification, signature
 * and application are subsequent manual steps.
 */
class GenerateRemediation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public array $backoff = [10, 30];

    public int $timeout = 180;

    public function __construct(
        public Finding $finding,
        public ?int $userId = null,
    ) {
        $this->onQueue('remediation');
    }

    public function uniqueId(): string
    {
        return 'remediation:'.$this->finding->id;
    }

    public function handle(MicroserviceClient $client): void
    {
        $this->finding->refresh();

        $payload = [
            'finding_id' => $this->finding->id,
            'title' => $this->finding->title,
            'severity' => $this->finding->severity,
            'cvss' => $this->finding->cvss_score,
            'cve' => $this->finding->cve_id,
            'cwe' => $this->finding->cwe_id,
            'affected_component' => $this->finding->affected_component,
            'endpoint' => $this->finding->endpoint,
            'evidence' => mb_substr($this->finding->evidence, 0, 1000),
            'languages' => ['bash', 'ansible', 'dockerfile', 'terraform'],
        ];

        $result = $this->callAiService($client, $payload);

        $scripts = $result['scripts'] ?? [];
        if (empty($scripts) && isset($result['code'])) {
            // Single-script response shape — normalise.
            $scripts = [$result];
        }

        $created = 0;
        foreach ($scripts as $script) {
            $scriptRow = $this->persistScript($script);
            if ($scriptRow) {
                $created++;
            }
        }

        // Mark the finding as in-remediation if we generated scripts.
        if ($created > 0 && $this->finding->status === Finding::STATUS_NEW) {
            $this->finding->status = Finding::STATUS_REMEDIATING;
            $this->finding->save();
        }

        AuditLogger::system('remediation.generated', [
            'finding_id' => $this->finding->id,
            'scripts_count' => $created,
            'user_id' => $this->userId,
        ]);
    }

    /**
     * @param  array<string,mixed>  $script
     */
    protected function persistScript(array $script): ?RemediationScript
    {
        $code = $script['code'] ?? null;
        if (! is_string($code) || trim($code) === '') {
            return null;
        }

        $language = (string) ($script['language'] ?? RemediationScript::LANG_BASH);
        if (! in_array($language, RemediationScript::LANGUAGES, true)) {
            $language = RemediationScript::LANG_BASH;
        }

        $title = (string) ($script['title'] ?? 'Remediation script for finding #'.$this->finding->id);
        if (Str::length($title) > 200) {
            $title = Str::limit($title, 200);
        }

        return RemediationScript::create([
            'finding_id' => $this->finding->id,
            'user_id' => $this->userId ?? $this->finding->scan?->user_id ?? 1,
            'title' => $title,
            'language' => $language,
            'code' => $code,
            'explanation' => $script['explanation'] ?? null,
            'status' => RemediationScript::STATUS_GENERATED,
            'signature' => hash('sha256', $code.'|'.config('app.key')),
        ]);
    }

    /**
     * Try the AI microservice first, then fall back to z-ai CLI (GLM-4-Plus).
     * Returns an array with at least a 'scripts' key containing one or more
     * script objects: {title, language, code, explanation}.
     */
    protected function callAiService(MicroserviceClient $client, array $payload): array
    {
        // Try the microservice first.
        try {
            if ($client->isConfigured('ai')) {
                $result = $client->call('ai', '/remediation', $payload, timeout: 120, retries: 0);
                if (! empty($result['scripts']) || ! empty($result['code'])) {
                    return $result;
                }
            }
        } catch (\Throwable $e) {
            Log::info('remediation.fallback_to_zai_cli', ['error' => $e->getMessage()]);
        }

        // Fallback: call z-ai CLI directly.
        $finding = $this->finding;
        $prompt = $this->buildZAiPrompt($finding);
        $system = "You are a cybersecurity remediation expert. Generate concrete, executable remediation scripts for the given finding. "
            ."Respond with ONLY a JSON object: {\"scripts\": [{\"title\": string, \"language\": \"bash\"|\"ansible\"|\"dockerfile\"|\"terraform\", \"code\": string, \"explanation\": string}]}. "
            ."Generate at least one bash script. The code must be production-ready and directly fix the vulnerability.";

        $zaiBin = trim((string) shell_exec('command -v z-ai 2>/dev/null')) ?: 'z-ai';
        if (! file_exists($zaiBin) && file_exists('/usr/local/bin/z-ai')) {
            $zaiBin = '/usr/local/bin/z-ai';
        }

        $promptFile = tempnam(sys_get_temp_dir(), 'rem_').'.txt';
        $systemFile = tempnam(sys_get_temp_dir(), 'rem_').'.txt';
        file_put_contents($promptFile, $prompt);
        file_put_contents($systemFile, $system);

        $cmd = sprintf(
            '%s chat --prompt %s --system %s 2>&1',
            escapeshellarg($zaiBin),
            escapeshellarg(file_get_contents($promptFile)),
            escapeshellarg(file_get_contents($systemFile))
        );

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        @unlink($promptFile);
        @unlink($systemFile);

        if ($exitCode !== 0) {
            Log::error('remediation.zai_cli_failed', ['output' => implode("\n", $output)]);
            return [];
        }

        $raw = implode("\n", $output);
        $jsonStart = strpos($raw, '{');
        if ($jsonStart === false) {
            return [];
        }

        // The z-ai CLI wraps the LLM response in its own JSON envelope:
        // { choices: [ { message: { content: "<LLM output>" } } ] }
        // The LLM output itself may be JSON (with scripts) or plain text.
        $envelope = json_decode(substr($raw, $jsonStart), true);
        $llmContent = $envelope['choices'][0]['message']['content'] ?? null;

        if (! is_string($llmContent)) {
            return [];
        }

        // The LLM may wrap its JSON in markdown code fences — strip them.
        $llmContent = preg_replace('/^```(?:json)?\s*/', '', $llmContent);
        $llmContent = preg_replace('/```\s*$/', '', $llmContent);
        // Also handle mid-string code fences (LLM sometimes adds them around the JSON).
        $llmContent = str_replace('```json', '', $llmContent);
        $llmContent = str_replace('```', '', $llmContent);
        $llmContent = trim($llmContent);

        // Try parsing the LLM content as JSON.
        $data = json_decode($llmContent, true);
        if (is_array($data) && isset($data['scripts'])) {
            return $data;
        }

        // Fallback: extract the scripts array via regex.
        if (preg_match('/"scripts"\s*:\s*(\[[\s\S]*?\])\s*}/', $llmContent, $m)) {
            $scripts = json_decode($m[1], true);
            if (is_array($scripts)) {
                return ['scripts' => $scripts];
            }
        }

        // Final fallback: if the LLM returned plain code, treat it as a single bash script.
        if (strlen($llmContent) > 20) {
            return ['scripts' => [[
                'title' => 'Remediation script for finding #'.$this->finding->id,
                'language' => 'bash',
                'code' => $llmContent,
                'explanation' => 'Generated by z-ai CLI (GLM-4-Plus).',
            ]]];
        }

        return [];
    }

    /**
     * Build a detailed prompt for the LLM describing the finding.
     */
    protected function buildZAiPrompt(Finding $finding): string
    {
        $cve = $finding->cve_id ?: 'N/A';
        $cwe = $finding->cwe_id ?: 'N/A';
        $evidence = $finding->evidence ?: '';
        $remediation = $finding->remediation ?: 'N/A';
        $lines = [
            "Generate remediation scripts for the following security finding:",
            "",
            "Title: {$finding->title}",
            "Severity: {$finding->severity}",
            "CVSS Score: {$finding->cvss_score}",
            "CVE: {$cve}",
            "CWE: {$cwe}",
            "Affected Component: {$finding->affected_component}",
            "Endpoint: {$finding->endpoint}",
            "Source Tool: {$finding->source_tool}",
            "",
            "Description:",
            $finding->description,
            "",
            "Evidence:",
            substr($evidence, 0, 800),
            "",
            "Existing remediation hint:",
            $remediation,
            "",
            "Generate 2-3 remediation scripts (bash, ansible, and/or dockerfile). "
            ."Each script must directly fix the vulnerability described above.",
        ];
        return implode("\n", $lines);
    }
}
