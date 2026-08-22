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
 * Execute a passive OSINT scan against a target.
 *
 * OSINT scans never touch the target system — they only query public
 * registries (whois, RDAP, certificate transparency logs, DNS) — so they
 * do not require the same authorization window as active scans. They are
 * still dispatched asynchronously because some modules (crt.sh, tech
 * fingerprinting) can take several seconds each.
 *
 * The job follows the same lifecycle as {@see ExecuteScan}: queued →
 * running → completed/failed, with the same retry policy.
 */
class ExecuteOsint implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use ProcessesScanResults;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public int $timeout = 300;

    public function __construct(
        public Scan $scan,
    ) {
        $this->onQueue('osint');
    }

    public function uniqueId(): string
    {
        return 'osint:'.$this->scan->correlation_id;
    }

    public function handle(MicroserviceClient $client): void
    {
        $this->scan->refresh();
        if ($this->scan->status === Scan::STATUS_CANCELLED) {
            return;
        }

        $this->scan->status = Scan::STATUS_RUNNING;
        $this->scan->started_at = now();
        $this->scan->attempt = max(1, $this->scan->attempt + 1);
        $this->scan->save();

        AuditLogger::system('osint.execution.started', [
            'scan_id' => $this->scan->id,
            'correlation_id' => $this->scan->correlation_id,
            'attempt' => $this->scan->attempt,
        ]);

        try {
            $payload = [
                'scan_id' => $this->scan->id,
                'correlation_id' => $this->scan->correlation_id,
                'target' => $this->scan->target_url,
                'modules' => ['whois', 'dns', 'ssl', 'subdomains', 'tech_stack'],
            ];

            $result = $client->call('osint', '/passive', $payload, timeout: 240, retries: 1);

            $merged = array_merge([
                'status' => Scan::STATUS_COMPLETED,
                'findings' => [],
                'raw_output' => null,
            ], $result);

            $this->processScanResults($this->scan, $merged);

            // Persist OSINT data on the target for the OSINT dashboard view.
            $this->updateTargetOsintData($result);

            AuditLogger::system('osint.execution.completed', [
                'scan_id' => $this->scan->id,
                'subdomains' => count($result['subdomains'] ?? []),
            ]);
        } catch (Throwable $e) {
            $this->handleFailure($e);
            throw $e;
        }
    }

    /**
     * Mirror the OSINT result onto the target row so the OSINT controller
     * can render the dashboard without re-querying the microservice.
     *
     * @param  array<string,mixed>  $result
     */
    protected function updateTargetOsintData(array $result): void
    {
        $target = $this->scan->target;
        if (! $target) {
            return;
        }

        $target->osint_data = array_merge(
            (array) ($target->osint_data ?? []),
            [
                'whois' => $result['whois'] ?? null,
                'dns' => $result['dns'] ?? null,
                'ssl' => $result['ssl'] ?? null,
                'updated_at' => now()->toIso8601String(),
            ],
        );

        if (isset($result['subdomains'])) {
            $target->subdomains = array_values(array_unique(array_map(
                static fn ($s) => is_array($s) ? ($s['name'] ?? '') : (string) $s,
                $result['subdomains'],
            )));
        }

        if (isset($result['tech_stack'])) {
            $target->tech_stack = $result['tech_stack'];
        }

        $target->last_seen_at = now();
        $target->saveQuietly();
    }

    protected function handleFailure(Throwable $e): void
    {
        $this->scan->refresh();

        Log::error('osint.execution.failed', [
            'scan_id' => $this->scan->id,
            'error' => $e->getMessage(),
        ]);

        if ($this->scan->attempt >= $this->scan->max_attempts) {
            $this->scan->status = Scan::STATUS_FAILED;
            $this->scan->completed_at = now();
            $this->scan->save();

            AuditLogger::system('osint.execution.failed', [
                'scan_id' => $this->scan->id,
                'error' => mb_substr($e->getMessage(), 0, 1000),
            ]);
        }
    }
}
