<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\SecurityAlert;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class SecurityController extends Controller
{
    public function alerts(Request $request)
    {
        $user = $request->user();

        $alerts = SecurityAlert::query()
            ->with(['project', 'scan'])
            ->when($request->input('severity'), fn (Builder $q, $s) => $q->where('severity', $s))
            ->when($request->filled('acknowledged'), fn (Builder $q) => $q->where('acknowledged', (bool) $request->input('acknowledged')))
            ->when($request->input('project'), fn (Builder $q, $p) => $q->where('project_id', $p))
            ->when(! $user->isAdmin() && ! $user->isAuditor(),
                fn (Builder $q) => $q->whereHas('project', fn (Builder $sq) => $sq->where('user_id', $user->id)),
            )
            ->latest()
            ->paginate(20);

        $projects = $this->visibleProjects($user);

        return view('security.alerts', compact('alerts', 'projects'));
    }

    public function acknowledge(SecurityAlert $alert)
    {
        $user = request()->user();
        if (! $user->isAdmin() && ! $user->isAuditor()) {
            abort_unless($alert->project && $alert->project->user_id === $user->id, 403);
        }

        $alert->update([
            'acknowledged'    => true,
            'acknowledged_by' => $user->id,
            'acknowledged_at' => now(),
        ]);

        return back()->with('success', 'Alert acknowledged.');
    }

    public function monitoring(Request $request)
    {
        $events = collect();
        $stats = [
            'total'         => 0,
            'alerts_24h'    => 0,
            'unack'         => 0,
            'per_minute'    => 0,
            'by_type'       => [],
            'by_severity'   => [],
        ];

        // Best-effort fetch from the security microservice.
        try {
            $gateway = config('services.api_gateway.url', 'http://api-gateway:8080');
            $res = Http::timeout(4)->get($gateway.'/api/security/monitoring/events', ['limit' => 50]);

            if ($res->ok()) {
                $data = $res->json();
                $events = collect($data['events'] ?? $data ?? []);
                $stats['total']       = $events->count();
                $stats['by_type']     = $events->groupBy('type')->map->count()->all();
                $stats['by_severity'] = $events->groupBy('severity')->map->count()->all();
                $stats['per_minute']  = (int) round($events->filter(fn ($e) => isset($e['timestamp'])
                    && Carbon::parse($e['timestamp'])->gt(now()->subMinute())
                )->count());
            }
        } catch (\Throwable $e) {
            // Fall back to local DB alerts.
        }

        // Augment stats from local DB so the page always shows real numbers.
        $localAlerts = SecurityAlert::query();
        $stats['alerts_24h'] = (clone $localAlerts)->where('created_at', '>=', now()->subDay())->count();
        $stats['unack']      = (clone $localAlerts)->where('acknowledged', false)->count();

        $recentActivity = $events->isNotEmpty() && $events->contains(fn ($e) => isset($e['timestamp'])
            && Carbon::parse($e['timestamp'])->gt(now()->subMinutes(5))
        );

        return view('security.monitoring', [
            'events'         => $events->take(50),
            'stats'          => $stats,
            'recentActivity' => $recentActivity,
        ]);
    }

    public function sandbox()
    {
        // Containers are managed by the security microservice (Docker). We display
        // a best-effort snapshot from the microservice, falling back to local
        // Python-subprocess sandboxes when Docker is unavailable.
        $containers = collect();
        try {
            $gateway = config('services.api_gateway.url', 'http://api-gateway:8080');
            $res = Http::timeout(4)->get($gateway.'/api/security/sandbox/containers');
            if ($res->ok()) {
                $containers = collect($res->json('containers', []));
            }
        } catch (\Throwable $e) {
            // graceful degradation
        }

        // Fallback: read local Python-subprocess sandboxes from /tmp/cybersec_sandboxes.json
        if ($containers->isEmpty()) {
            $localFile = '/tmp/cybersec_sandboxes.json';
            if (file_exists($localFile)) {
                $data = json_decode((string) file_get_contents($localFile), true);
                if (is_array($data)) {
                    $containers = collect($data);
                }
            }
        }

        return view('security.sandbox', compact('containers'));
    }

    public function launchSandbox(Request $request)
    {
        $validated = $request->validate([
            'app'   => ['required', 'string', 'max:64'],
            'image' => ['required', 'string', 'max:255'],
        ]);

        // Try the Docker-based microservice first.
        try {
            $gateway = config('services.api_gateway.url', 'http://api-gateway:8080');
            $res = Http::timeout(20)->post($gateway.'/api/security/sandbox/launch', $validated);
            if ($res->ok()) {
                return back()->with('success', "Sandbox {$validated['app']} launched.");
            }
        } catch (\Throwable $e) {
            // Fall through to local sandbox launcher.
        }

        // Fallback: launch a local Python subprocess simulating the vulnerable app.
        try {
            $container = $this->launchLocalSandbox($validated['app'], $validated['image']);
            return back()->with('success', "Sandbox {$validated['app']} launched on port {$container['port']} (local simulation).");
        } catch (\Throwable $e) {
            return back()->with('error', 'Sandbox launch failed: '.$e->getMessage());
        }
    }

    /**
     * Launch a local Python subprocess that simulates a vulnerable web app
     * (DVWA, SQLi-Labs, etc.). Used as a fallback when Docker is unavailable.
     */
    private function launchLocalSandbox(string $app, string $image): array
    {
        $localFile = '/tmp/cybersec_sandboxes.json';
        $containers = file_exists($localFile)
            ? (json_decode((string) file_get_contents($localFile), true) ?: [])
            : [];

        // Pick an available port starting from 8181
        $port = 8181;
        $usedPorts = array_column($containers, 'port');
        while (in_array($port, $usedPorts) || ! $this->portAvailable($port)) {
            $port++;
            if ($port > 8199) {
                throw new \RuntimeException('No available sandbox ports (8181-8199).');
            }
        }

        $id = substr(md5($app.'-'.$port.'-'.time()), 0, 12);
        $name = "sandbox-{$app}-{$id}";

        // Build a Python script that serves a fake vulnerable app
        $script = $this->buildVulnerableAppScript($app);
        $scriptFile = "/tmp/sandbox_{$id}.py";
        file_put_contents($scriptFile, $script);

        // Launch the subprocess detached
        $pythonBin = config('services.python.binary', trim((string) shell_exec('which python3 2>/dev/null') ?: 'python3'));
        $cmd = sprintf(
            '%s %s %d > /tmp/sandbox_%s.log 2>&1 &',
            escapeshellarg($pythonBin),
            escapeshellarg($scriptFile),
            $port,
            $id
        );
        exec($cmd);

        // Wait briefly and verify it's running
        usleep(800000);
        if (! $this->portAvailable($port)) {
            $container = [
                'id'      => $id,
                'name'    => $name,
                'image'   => $image,
                'app'     => $app,
                'status'  => 'running',
                'ports'   => ["0.0.0.0:{$port} → 8000"],
                'port'    => $port,
                'url'     => "http://localhost:{$port}",
                'pid_file' => "/tmp/sandbox_{$id}.pid",
                'started_at' => now()->toIso8601String(),
            ];
            $containers[] = $container;
            file_put_contents($localFile, json_encode($containers, JSON_PRETTY_PRINT));

            return $container;
        }

        throw new \RuntimeException("Sandbox failed to start. Check /tmp/sandbox_{$id}.log");
    }

    private function portAvailable(int $port): bool
    {
        $sock = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (! $sock) {
            return false;
        }
        $available = @socket_bind($sock, '127.0.0.1', $port);
        @socket_close($sock);
        return $available;
    }

    /**
     * Build a Python HTTP server that simulates a vulnerable web app.
     * Each app type (DVWA, SQLi-Labs, etc.) has its own template.
     */
    private function buildVulnerableAppScript(string $app): string
    {
        $apps = [
            'DVWA'       => 'Damn Vulnerable Web Application — login page with SQLi in username field',
            'SQLi-Labs'  => 'SQL injection labs — basic login form with UNION-based SQLi',
            'WebGoat'    => 'OWASP WebGoat — intentionally vulnerable Java app',
            'bWAPP'      => 'Buggy Web Application — multiple vuln scenarios',
        ];

        $description = $apps[$app] ?? 'Custom vulnerable application';

        return <<<PYTHON
#!/usr/bin/env python3
"""Simulated vulnerable app: {$app}

{$description}

This is a SAFE simulation — it responds to HTTP requests with HTML that
mimics the real vulnerable app's behavior, but does NOT execute actual
exploits. Used for sandbox testing when Docker is unavailable.
"""
import sys
import socketserver
import http.server
import json
import os

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8000

class VulnerableAppHandler(http.server.SimpleHTTPRequestHandler):
    def do_GET(self):
        if self.path == '/':
            self.send_response(200)
            self.send_header('Content-Type', 'text/html')
            self.end_headers()
            html = '''<!DOCTYPE html>
<html><head><title>{$app}</title>
<style>body{{font-family:monospace;background:#1a1a1a;color:#0f0;padding:2rem;}}
h1{{color:#e74c3c;}}form{{background:#222;padding:1rem;border-radius:5px;}}
input{{display:block;margin:0.5rem 0;padding:0.5rem;background:#333;color:#fff;border:1px solid #444;}}
button{{background:#e74c3c;color:#fff;padding:0.5rem 1rem;border:none;cursor:pointer;}}</style>
</head><body>
<h1>⚠️ {$app}</h1>
<p>{$description}</p>
<p><strong>Status:</strong> Running (sandbox simulation)</p>
<p><strong>Port:</strong> ''' + str(PORT) + '''</p>
<hr>
<h2>Login</h2>
<form method="POST" action="/login">
<input name="username" placeholder="admin' OR '1'='1" />
<input name="password" type="password" placeholder="password" />
<button>LOGIN</button>
</form>
<h2>Vulnerable Endpoints</h2>
<ul>
<li><a href="/?id=1">/?id=1</a> — SQLi test</li>
<li><a href="/search?q=test">/search?q=test</a> — XSS test</li>
<li><a href="/cgi-bin/ping?host=127.0.0.1;id">/cgi-bin/ping?host=...</a> — Command injection</li>
<li><a href="/.env">/.env</a> — Sensitive file</li>
</ul>
</body></html>'''
            self.wfile.write(html.encode())
        elif self.path == '/.env':
            self.send_response(200)
            self.send_header('Content-Type', 'text/plain')
            self.end_headers()
            self.wfile.write(b"DB_PASSWORD=s3cr3t\\nAPI_KEY=sk-1234567890\\nJWT_SECRET=supersecret\\n")
        elif self.path.startswith('/?id='):
            self.send_response(200)
            self.send_header('Content-Type', 'text/html')
            self.end_headers()
            self.wfile.write(b"<html><body><h1>User ID: " + self.path[5:].encode() + b"</h1></body></html>")
        else:
            self.send_response(404)
            self.send_header('Content-Type', 'text/plain')
            self.end_headers()
            self.wfile.write(b"Not found")

    def do_POST(self):
        if self.path == '/login':
            self.send_response(200)
            self.send_header('Content-Type', 'text/html')
            self.end_headers()
            self.wfile.write(b"<html><body><h1>Login received</h1><p>SQLi payload detected: ' OR '1'='1</p></body></html>")
        else:
            self.send_response(404)
            self.end_headers()

class ThreadedHTTPServer(socketserver.ThreadingMixIn, http.server.HTTPServer):
    daemon_threads = True

if __name__ == '__main__':
    server = ThreadedHTTPServer(('127.0.0.1', PORT), VulnerableAppHandler)
    print(f"{$app} sandbox running on port {PORT}")
    server.serve_forever()
PYTHON;
    }

    public function stopSandbox(string $id)
    {
        // Try Docker microservice first
        try {
            $gateway = config('services.api_gateway.url', 'http://api-gateway:8080');
            Http::timeout(10)->delete($gateway.'/api/security/sandbox/containers/'.$id);
        } catch (\Throwable $e) {
            // best-effort
        }

        // Local sandbox stop
        $localFile = '/tmp/cybersec_sandboxes.json';
        if (file_exists($localFile)) {
            $containers = json_decode((string) file_get_contents($localFile), true) ?: [];
            $filtered = [];
            foreach ($containers as $c) {
                if (($c['id'] ?? null) === $id) {
                    // Kill the Python subprocess
                    if (! empty($c['port'])) {
                        $pidFile = "/tmp/sandbox_{$id}.pid";
                        if (file_exists($pidFile)) {
                            $pid = (int) file_get_contents($pidFile);
                            if ($pid > 0) {
                                posix_kill($pid, SIGTERM);
                            }
                            @unlink($pidFile);
                        }
                        // Also try to kill by port via fuser
                        exec("fuser -k {$c['port']}/tcp 2>/dev/null");
                    }
                    @unlink("/tmp/sandbox_{$id}.py");
                } else {
                    $filtered[] = $c;
                }
            }
            file_put_contents($localFile, json_encode($filtered, JSON_PRETTY_PRINT));
        }

        return back()->with('success', 'Container stop requested.');
    }

    private function visibleProjects($user)
    {
        return Project::query()
            ->when(! $user->isAdmin() && ! $user->isAuditor(),
                fn (Builder $q) => $q->where('user_id', $user->id),
            )
            ->orderBy('name')
            ->get();
    }
}
