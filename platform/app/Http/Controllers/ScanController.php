<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Scan;
use App\Models\Target;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class ScanController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $scans = Scan::query()
            ->with(['project', 'target'])
            ->when($request->input('project'), fn (Builder $q, $p) => $q->where('project_id', $p))
            ->when($request->input('status'), fn (Builder $q, $s) => $q->where('status', $s))
            ->when($request->input('type'), fn (Builder $q, $t) => $q->where('type', $t))
            ->when($request->input('profile'), fn (Builder $q, $p) => $q->where('profile', $p))
            ->when(! $user->isAdmin() && ! $user->isAuditor(),
                fn (Builder $q) => $q->whereHas('project', fn (Builder $sq) => $sq->where('user_id', $user->id)),
            )
            ->latest()
            ->paginate(20);

        $projects = $this->visibleProjects($user);

        return view('scans.index', compact('scans', 'projects'));
    }

    public function create(Request $request)
    {
        $user = $request->user();
        $projects = $this->visibleProjects($user);

        $targetsByProject = $projects->mapWithKeys(function (Project $p) {
            $targets = $p->targets()
                ->select(['id', 'name', 'domain_url', 'ip_address', 'scope_type', 'authorization_status'])
                ->get();

            if ($targets->isEmpty()) {
                $created = $p->targets()->create([
                    'name'                 => $p->name.' Target',
                    'domain_url'           => 'scanme.nmap.org',
                    'scope_type'           => 'domain',
                    'authorization_status' => Target::AUTH_APPROVED,
                    'authorized_at'        => now(),
                ]);
                $targets = collect([$created]);
            }

            return [(string) $p->id => $targets->map(fn (Target $t) => [
                'id'         => $t->id,
                'name'       => $t->name,
                'value'      => $t->domain_url ?: $t->ip_address,
                'authorized' => $t->authorization_status === Target::AUTH_APPROVED,
            ])->all()];
        })->all();

        return view('scans.create', [
            'projects'         => $projects,
            'targetsByProject' => $targetsByProject,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_id'    => ['required', 'exists:projects,id'],
            'target_id'     => ['required', 'exists:targets,id'],
            'type'          => ['required', Rule::in(Scan::ALL_TYPES)],
            'profile'       => ['required', Rule::in(array_keys(Scan::PROFILES))],
            'aggressive_confirmed' => ['nullable', 'boolean'],
            'config.custom_ports'  => ['nullable', 'string', 'max:255'],
            'config.excluded_paths' => ['nullable', 'string', 'max:5000'],
            'config.custom_flags'  => ['nullable', 'string', 'max:500'],
        ]);

        $target = Target::with('project')->findOrFail($validated['target_id']);
        abort_unless($target->project_id == $validated['project_id'], 422, 'Target does not belong to project.');

        if ($validated['profile'] === 'aggressive' && ! $request->boolean('aggressive_confirmed')) {
            return back()->withErrors(['aggressive_confirmed' => 'Aggressive scans require written authorization.'])->withInput();
        }

        $profileFlags = [
            'silent'     => ['rate_limit_qps' => 2,  'jitter_ms' => 1200],
            'balanced'   => ['rate_limit_qps' => 8,  'jitter_ms' => 300],
            'aggressive' => ['rate_limit_qps' => 25, 'jitter_ms' => 50],
        ][$validated['profile']];

        $scan = Scan::create([
            'project_id'   => $validated['project_id'],
            'target_id'    => $validated['target_id'],
            'user_id'      => $request->user()->id,
            'type'         => $validated['type'],
            'target_url'   => $target->domain_url ?: $target->ip_address,
            'profile'      => $validated['profile'],
            'status'       => Scan::STATUS_QUEUED,
            'queued_at'    => now(),
            ...$profileFlags,
            'config'       => [
                'custom_ports'   => $request->input('config.custom_ports'),
                'excluded_paths' => $request->input('config.excluded_paths'),
                'custom_flags'   => $request->input('config.custom_flags'),
            ],
        ]);

        // Dispatch scan job to queue worker / pipeline
        \App\Jobs\ExecuteScan::dispatch($scan);

        // Also attempt to notify API Gateway if running
        try {
            $gateway = env('API_GATEWAY_URL', 'http://api-gateway:8080');
            Http::withToken(config('services.api_gateway.token', ''))
                ->timeout(5)
                ->post($gateway.'/api/scans', [
                    'scan_id'    => $scan->id,
                    'type'       => $scan->type,
                    'target_url' => $scan->target_url,
                    'profile'    => $scan->profile,
                ]);
        } catch (\Throwable $e) {
            report($e);
        }

        return redirect()->route('scans.show', $scan)
            ->with('success', 'Scan queued. It will start as soon as a worker picks it up.');
    }

    public function show(Scan $scan)
    {
        $this->authorizeScan($scan);

        $scan->load([
            'project.targets', 'target', 'findings.remediationScripts',
            'report', 'project.assets',
        ]);

        return view('scans.show', compact('scan'));
    }

    public function cancel(Scan $scan)
    {
        $this->authorizeScan($scan);

        if (in_array($scan->status, [Scan::STATUS_QUEUED, Scan::STATUS_RUNNING, Scan::STATUS_PENDING], true)) {
            $scan->update(['status' => Scan::STATUS_CANCELLED, 'completed_at' => now()]);
        }

        return redirect()->route('scans.show', $scan)->with('success', 'Scan cancelled.');
    }

    public function retry(Scan $scan)
    {
        $this->authorizeScan($scan);

        if ($scan->canRetry()) {
            $scan->update([
                'status'     => Scan::STATUS_QUEUED,
                'queued_at'  => now(),
                'attempt'    => $scan->attempt + 1,
                'completed_at' => null,
                'started_at' => null,
            ]);
        }

        return redirect()->route('scans.show', $scan)->with('success', 'Scan re-queued for retry.');
    }

    public function export(Scan $scan)
    {
        $this->authorizeScan($scan);

        $scan->load(['findings', 'project', 'target']);

        return response()->json([
            'scan'     => $scan->only(['id', 'type', 'target_url', 'profile', 'status', 'started_at', 'completed_at']),
            'project'  => $scan->project?->only(['id', 'name', 'client_name']),
            'findings' => $scan->findings->map(fn ($f) => $f->only([
                'id', 'title', 'severity', 'cvss_score', 'cve_id', 'endpoint', 'source_tool', 'evidence', 'remediation',
            ])),
        ], 200, ['Content-Disposition' => 'attachment; filename="scan-'.$scan->id.'.json"']);
    }

    private function authorizeScan(Scan $scan): void
    {
        $user = request()->user();
        if ($user->isAdmin() || $user->isAuditor()) {
            return;
        }
        abort_unless($scan->user_id === $user->id || ($scan->project && $scan->project->user_id === $user->id), 403);
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
