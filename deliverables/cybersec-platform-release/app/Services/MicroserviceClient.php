<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin, opinionated HTTP client for the platform's Python microservices.
 *
 * Each microservice has its base URL configured in `.env` (RECON_SERVICE_URL,
 * SECURITY_SERVICE_URL, OSINT_SERVICE_URL, AI_SERVICE_URL, API_GATEWAY_URL).
 * The client centralises:
 *   - URL resolution by service alias.
 *   - Timeout enforcement (default 30s, override per call).
 *   - Retry-on-transient-failure with exponential backoff.
 *   - Structured error logging that includes the failing service + endpoint.
 *   - JSON decoding with sane defaults, throwing a {@see RuntimeException}
 *     on non-2xx responses so callers can `try/catch` uniformly.
 *
 * The client is intentionally framework-light (uses Laravel's HTTP facade)
 * so it works both inside HTTP request cycles and inside queue workers.
 */
class MicroserviceClient
{
    /**
     * Map of platform service aliases to their env-configured base URLs.
     *
     * @var array<string,string>
     */
    private const SERVICE_URL_ENV = [
        'recon' => 'RECON_SERVICE_URL',
        'security' => 'SECURITY_SERVICE_URL',
        'osint' => 'OSINT_SERVICE_URL',
        'ai' => 'AI_SERVICE_URL',
        'gateway' => 'API_GATEWAY_URL',
    ];

    /**
     * Map of scan types to the (service, endpoint) that should execute them.
     *
     * This catalogue is the single source of truth for routing a scan type
     * to its backend service; the ExecuteScan job and ScanCallbackController
     * both consult it.
     *
     * @var array<string,array{service:string,endpoint:string}>
     */
    public const SCAN_TYPE_ROUTES = [
        // Reconnaissance
        'nmap' => ['service' => 'recon', 'endpoint' => '/scan'],
        'nuclei' => ['service' => 'recon', 'endpoint' => '/scan'],
        'gobuster' => ['service' => 'recon', 'endpoint' => '/scan'],
        'subfinder' => ['service' => 'recon', 'endpoint' => '/scan'],
        'wpscan' => ['service' => 'recon', 'endpoint' => '/scan'],
        'osint' => ['service' => 'osint', 'endpoint' => '/passive'],

        // Security (active)
        'attack_detect' => ['service' => 'security', 'endpoint' => '/detect'],
        'injection_full' => ['service' => 'security', 'endpoint' => '/injection'],
        'injection_sql' => ['service' => 'security', 'endpoint' => '/injection'],
        'injection_cmd' => ['service' => 'security', 'endpoint' => '/injection'],
        'injection_xss' => ['service' => 'security', 'endpoint' => '/injection'],
        'waf_detect' => ['service' => 'security', 'endpoint' => '/waf-detect'],
        'prevention_check' => ['service' => 'security', 'endpoint' => '/prevention-check'],

        // Sandbox (executed inside isolated containers)
        'sandbox_full' => ['service' => 'security', 'endpoint' => '/sandbox/test'],
        'sandbox_sqli' => ['service' => 'security', 'endpoint' => '/sandbox/test'],
        'sandbox_cmdi' => ['service' => 'security', 'endpoint' => '/sandbox/test'],
        'sandbox_xss' => ['service' => 'security', 'endpoint' => '/sandbox/test'],
    ];

    /** Default per-request timeout (seconds). */
    public const DEFAULT_TIMEOUT = 30;

    /** Default number of retries on transient failures. */
    public const DEFAULT_RETRIES = 2;

    /**
     * Resolve the base URL for a given service alias.
     *
     * @throws RuntimeException When the alias is unknown or the env var is unset.
     */
    public function baseUrl(string $service): string
    {
        $envKey = self::SERVICE_URL_ENV[$service]
            ?? throw new RuntimeException("Unknown microservice alias: {$service}");

        $url = rtrim((string) config('app.url'), '/'); // fallback only

        // Prefer env() over config() because the env vars are read at boot.
        $envUrl = env($envKey);
        if (! $envUrl) {
            throw new RuntimeException("Microservice URL not configured: {$envKey}");
        }

        return rtrim($envUrl, '/');
    }

    /**
     * Determine if the given service alias is configured (URL is set).
     */
    public function isConfigured(string $service): bool
    {
        $envKey = self::SERVICE_URL_ENV[$service] ?? null;

        return $envKey !== null && filled(env($envKey));
    }

    /**
     * Issue an HTTP request to a microservice and return the decoded JSON body.
     *
     * @param  string  $service  Service alias (recon, security, osint, ai, gateway).
     * @param  string  $endpoint  Path beginning with "/" (e.g. "/scan").
     * @param  array<string,mixed>  $data  Payload (sent as JSON for POST/PUT/PATCH, query for GET).
     * @param  string  $method  HTTP verb (uppercase).
     * @param  int  $timeout  Per-attempt timeout in seconds.
     * @param  int  $retries  Number of retries on transient failures.
     * @return array<string,mixed> Decoded JSON response body.
     *
     * @throws RuntimeException On non-2xx response, network failure, or invalid JSON.
     */
    public function call(
        string $service,
        string $endpoint,
        array $data = [],
        string $method = 'POST',
        int $timeout = self::DEFAULT_TIMEOUT,
        int $retries = self::DEFAULT_RETRIES,
    ): array {
        $url = $this->baseUrl($service).'/'.ltrim($endpoint, '/');
        $method = strtoupper($method);

        $attempt = 0;
        $lastException = null;

        while ($attempt <= $retries) {
            $attempt++;
            try {
                $request = Http::timeout($timeout)
                    ->acceptJson()
                    ->withHeaders($this->requestHeaders());

                if ($method === 'GET') {
                    $response = $request->get($url, $data);
                } else {
                    $response = $request->{strtolower($method)}($url, $data);
                }

                if ($response->successful()) {
                    return $this->decodeBody($response, $service, $endpoint);
                }

                // Non-2xx: log once, no retry for 4xx (client errors are permanent).
                if ($response->clientError()) {
                    throw new RuntimeException(
                        "Microservice {$service} {$endpoint} returned {$response->status()}: "
                        .$response->body()
                    );
                }

                // 5xx: retry.
                $lastException = new RuntimeException(
                    "Microservice {$service} {$endpoint} returned {$response->status()}"
                );
            } catch (ConnectionException $e) {
                $lastException = $e;
            } catch (RuntimeException $e) {
                throw $e;
            }

            if ($attempt > $retries) {
                break;
            }

            // Exponential backoff: 2^attempt seconds, capped at 10s.
            $sleepSeconds = min(10, (int) pow(2, $attempt));
            usleep($sleepSeconds * 1_000_000);
        }

        Log::error('microservice.call.failed', [
            'service' => $service,
            'endpoint' => $endpoint,
            'url' => $url,
            'attempts' => $attempt,
            'error' => $lastException?->getMessage(),
        ]);

        throw $lastException ?? new RuntimeException("Microservice call failed: {$service} {$endpoint}");
    }

    /**
     * GET a /health endpoint for a service.
     *
     * Returns `['status' => 'unknown', 'reachable' => false]` when the
     * service is not configured or unreachable, so callers can render a
     * degraded health dashboard without raising exceptions.
     *
     * @return array{status:string, reachable:bool, data?:array}
     */
    public function health(string $service): array
    {
        if (! $this->isConfigured($service)) {
            return ['status' => 'unconfigured', 'reachable' => false];
        }

        try {
            $data = $this->call($service, '/health', [], 'GET', timeout: 5, retries: 0);

            return [
                'status' => (string) ($data['status'] ?? 'ok'),
                'reachable' => true,
                'data' => $data,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'unreachable',
                'reachable' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Resolve the (service, endpoint) tuple responsible for a given scan type.
     *
     * @return array{service:string, endpoint:string}
     *
     * @throws RuntimeException When the scan type is unknown.
     */
    public function routeForScanType(string $scanType): array
    {
        return self::SCAN_TYPE_ROUTES[$scanType]
            ?? throw new RuntimeException("Unknown scan type: {$scanType}");
    }

    /**
     * Headers attached to every outbound microservice call.
     *
     * @return array<string,string>
     */
    protected function requestHeaders(): array
    {
        return [
            'X-Platform' => 'cybersec-platform',
            'X-Correlation-Id' => (string) \Illuminate\Support\Str::uuid(),
        ];
    }

    /**
     * Decode the JSON body of a successful response.
     *
     * @return array<string,mixed>
     *
     * @throws RuntimeException When the body is not valid JSON.
     */
    protected function decodeBody(Response $response, string $service, string $endpoint): array
    {
        $body = $response->body();
        if ($body === '' || $body === 'null') {
            return [];
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            throw new RuntimeException(
                "Microservice {$service} {$endpoint} returned non-JSON body: "
                .mb_substr($body, 0, 200)
            );
        }

        return $decoded;
    }
}
