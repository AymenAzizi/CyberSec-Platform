<?php

namespace App\Http\Controllers;

use App\Models\Target;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class OsintController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $targets = Target::query()
            ->with('project')
            ->when(! $user->isAdmin() && ! $user->isAuditor(),
                fn (Builder $q) => $q->whereHas('project', fn (Builder $sq) => $sq->where('user_id', $user->id)),
            )
            ->latest()
            ->paginate(25);

        return view('osint.index', compact('targets'));
    }

    public function run(Target $target)
    {
        $this->authorizeTarget($target);

        // Try the external OSINT microservice first.
        try {
            $gateway = config('services.api_gateway.url', 'http://api-gateway:8080');
            $res = Http::timeout(60)->post($gateway.'/api/osint/passive', [
                'target_id' => $target->id,
                'domain'    => $target->domain_url,
                'ip'        => $target->ip_address,
            ]);

            if ($res->ok()) {
                $data = $res->json('data', []);
                $target->update([
                    'osint_data'   => $data,
                    'tech_stack'   => $data['tech_stack'] ?? null,
                    'subdomains'   => $data['subdomains'] ?? null,
                    'last_seen_at' => now(),
                ]);
                return back()->with('success', 'OSINT data refreshed.');
            }
        } catch (\Throwable $e) {
            // Fall through to inline OSINT collection.
        }

        // Fallback: collect OSINT inline via Python scan_worker's osint module.
        try {
            $data = $this->collectOsintInline($target);
            $target->update([
                'osint_data'   => $data,
                'tech_stack'   => $data['tech_stack'] ?? null,
                'subdomains'   => $data['subdomains'] ?? null,
                'last_seen_at' => now(),
            ]);
            return back()->with('success', 'OSINT data refreshed ('.count($data['subdomains'] ?? []).' subdomains, '.count($data['tech_stack'] ?? []).' technologies).');
        } catch (\Throwable $e) {
            return back()->with('error', 'OSINT collection failed: '.$e->getMessage());
        }
    }

    /**
     * Inline OSINT collection: calls the Python scan_worker's osint module
     * via subprocess so we get real data without the microservice.
     */
    private function collectOsintInline(Target $target): array
    {
        $script = <<<'PYTHON'
import sys, json, os, glob
# Locate the scan_worker module dynamically so this code works on any host.
_candidates = [
    os.environ.get('SCAN_WORKER_PATH', ''),
    '/var/www/html/workers',
    os.path.join(os.getcwd(), 'workers'),
    os.path.expanduser('~/platform/workers'),
]
for _p in _candidates:
    if _p and os.path.isdir(_p):
        sys.path.insert(0, _p)
        break
try:
    from scan_worker import scan_osint, normalize_target
except ImportError as e:
    print(json.dumps({'error': f'scan_worker module not found: {e}'}))
    sys.exit(1)
url = sys.argv[1]
result = scan_osint(url, 'balanced', {})
print(json.dumps(result.get('osint_data', {})))
PYTHON;

        $tempScript = tempnam(sys_get_temp_dir(), 'osint_').'.py';
        file_put_contents($tempScript, $script);

        $pythonBin = config('services.python.binary', trim((string) shell_exec('which python3 2>/dev/null') ?: 'python3'));
        $cmd = sprintf(
            '%s %s %s 2>&1',
            escapeshellarg($pythonBin),
            escapeshellarg($tempScript),
            escapeshellarg($target->domain_url ?: $target->ip_address)
        );
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        @unlink($tempScript);

        if ($exitCode !== 0) {
            throw new \RuntimeException('Python OSINT script failed: '.implode("\n", $output));
        }

        $raw = implode("\n", $output);
        $jsonStart = strpos($raw, '{');
        if ($jsonStart === false) {
            throw new \RuntimeException('No JSON in OSINT output: '.$raw);
        }

        return json_decode(substr($raw, $jsonStart), true) ?? [];
    }

    public function results(Target $target)
    {
        $this->authorizeTarget($target);
        $target->load('project');

        return view('osint.results', compact('target'));
    }

    private function authorizeTarget(Target $target): void
    {
        $user = request()->user();
        if ($user->isAdmin() || $user->isAuditor()) {
            return;
        }
        abort_unless($target->project && $target->project->user_id === $user->id, 403);
    }
}
