<?php

namespace App\Jobs;

use App\Models\Scan;
use App\Services\AuditLogger;
use App\Services\MicroserviceClient;
use App\Traits\ProcessesScanResults;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Execute a single scan by dispatching it to the appropriate microservice.
 *
 * This job is the *only* entry point for executing a scan: controllers must
 * never call the microservices synchronously. The job is responsible for:
 *   1. Marking the scan as `running` before the HTTP call.
 *   2. Calling the correct microservice via {@see MicroserviceClient}.
 *   3. Persisting the result payload (findings, alerts, scripts, graph).
 *   4. Optionally calling the AI service for post-analysis.
 *   5. On exception: re-dispatching if attempts remain, otherwise marking
 *      the scan as `failed` with the error stored for triage.
 *
 * Retries follow the platform-wide backoff schedule [10, 30, 60] seconds,
 * with a hard timeout of 600s per attempt.
 */
class ExecuteScan implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use ProcessesScanResults;

    /** Maximum number of attempts (including the first). */
    public int $tries = 3;

    /** Backoff schedule in seconds, applied between attempts. */
    public array $backoff = [10, 30, 60];

    /** Per-attempt PHP execution timeout in seconds. */
    public int $timeout = 600;

    public function __construct(
        public Scan $scan,
    ) {
        // Send the job to the `scans` queue so workers can specialise.
        $this->onQueue('scans');
    }

    /**
     * Unique-id hint so the same scan is not double-dispatched by requeue.
     */
    public function uniqueId(): string
    {
        return (string) $this->scan->correlation_id;
    }

    public function handle(MicroserviceClient $client): void
    {
        // Refresh the model in case the scan was cancelled while queued.
        $this->scan->refresh();
        if (in_array($this->scan->status, [Scan::STATUS_CANCELLED], true)) {
            Log::info('scan.execution.skipped_cancelled', ['scan_id' => $this->scan->id]);

            return;
        }

        $this->scan->status = Scan::STATUS_RUNNING;
        $this->scan->started_at = now();
        $this->scan->worker_id = gethostname().'#'.getmypid();
        $this->scan->attempt = max(1, $this->scan->attempt + 1);
        $this->scan->save();

        AuditLogger::system('scan.execution.started', [
            'scan_id' => $this->scan->id,
            'correlation_id' => $this->scan->correlation_id,
            'type' => $this->scan->type,
            'attempt' => $this->scan->attempt,
        ]);

        try {
            $payload = $this->buildPayload();
            $route = $client->routeForScanType($this->scan->type);

            $result = $client->call(
                service: $route['service'],
                endpoint: $route['endpoint'],
                data: $payload,
                method: 'POST',
                timeout: 480, // leaves a 2-minute buffer under the 600s hard limit
                retries: 1,
            );

            // Merge server-side data with what we already have on the scan.
            $merged = array_merge([
                'status' => Scan::STATUS_COMPLETED,
                'findings' => [],
                'tools_status' => null,
                'severity_counts' => null,
                'raw_output' => null,
                'remediation_scripts' => [],
            ], $result);

            $this->processScanResults($this->scan, $merged);

            // Async AI analysis (non-blocking: failure here must NOT fail the scan).
            $this->requestAiAnalysis($client, $merged);

            AuditLogger::system('scan.execution.completed', [
                'scan_id' => $this->scan->id,
                'findings' => count($merged['findings'] ?? []),
            ]);
        } catch (Throwable $e) {
            $this->handleFailure($e);

            throw $e;
        }
    }

    /**
     * Build the outbound payload sent to the microservice.
     *
     * @return array<string,mixed>
     */
    protected function buildPayload(): array
    {
        return [
            'scan_id' => $this->scan->id,
            'correlation_id' => $this->scan->correlation_id,
            'scan_type' => $this->scan->type,
            'target' => $this->scan->target_url,
            'target_id' => $this->scan->target_id,
            'project_id' => $this->scan->project_id,
            'profile' => $this->scan->profile,
            'config' => $this->scan->config ?? [],
            'jitter_ms' => $this->scan->jitter_ms,
            'rate_limit_qps' => $this->scan->rate_limit_qps,
            'options' => [
                'return_raw' => true,
                'normalize_findings' => true,
                'generate_citations' => true,
            ],
        ];
    }

    /**
     * Request executive-summary + remediation scripts from the AI service.
     *
     * Failures are logged but never propagated — the scan is still
     * considered complete if findings were collected.
     *
     * @param  array<string,mixed>  $scanResult
     */
    protected function requestAiAnalysis(MicroserviceClient $client, array $scanResult): void
    {
        if (! $client->isConfigured('ai')) {
            return;
        }

        try {
            $findings = $scanResult['findings'] ?? [];
            if (empty($findings)) {
                return;
            }

            $analysis = $client->call('ai', '/analyze', [
                'scan_id' => $this->scan->id,
                'scan_type' => $this->scan->type,
                'target' => $this->scan->target_url,
                'findings' => array_slice($findings, 0, 50),
            ], timeout: 120, retries: 0);

            if (! empty($analysis)) {
                $this->scan->refresh();
                $existingConfig = (array) ($this->scan->config ?? []);
                $existingConfig['ai_analysis'] = $analysis;
                $this->scan->config = $existingConfig;
                $this->scan->save();
            }
        } catch (Throwable $e) {
            Log::warning('scan.ai_analysis_failed', [
                'scan_id' => $this->scan->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mark the scan as failed when retries are exhausted, otherwise let
     * the queue worker re-dispatch automatically.
     */
    protected function handleFailure(Throwable $e): void
    {
        $this->scan->refresh();

        Log::error('scan.execution.failed', [
            'scan_id' => $this->scan->id,
            'attempt' => $this->scan->attempt,
            'max_attempts' => $this->scan->max_attempts,
            'error' => $e->getMessage(),
        ]);

        // When we've exhausted attempts, transition the scan to failed
        // and raise a security alert so it shows up on the dashboard.
        if ($this->scan->attempt >= $this->scan->max_attempts) {
            $this->scan->status = Scan::STATUS_FAILED;
            $this->scan->completed_at = now();
            $this->scan->save();

            AuditLogger::system('scan.execution.failed', [
                'scan_id' => $this->scan->id,
                'correlation_id' => $this->scan->correlation_id,
                'error' => mb_substr($e->getMessage(), 0, 1000),
            ]);
        }
    }
}
