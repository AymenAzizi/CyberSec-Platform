<?php

namespace App\Http\Controllers;

use App\Models\Finding;
use App\Models\RemediationScript;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class FindingController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $findings = Finding::query()
            ->with(['project', 'scan', 'target', 'remediationScripts'])
            ->when($request->filled('severity') && $request->input('severity') !== 'all', function ($q) use ($request) {
                $q->where('severity', $request->input('severity'));
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->input('search').'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('title', 'like', $term)
                        ->orWhere('description', 'like', $term)
                        ->orWhere('endpoint', 'like', $term)
                        ->orWhere('cve_id', 'like', $term)
                        ->orWhere('source_tool', 'like', $term);
                });
            })
            ->when(! $user->isAdmin() && ! $user->isAuditor(), function ($q) use ($user) {
                $q->whereHas('project', fn ($sq) => $sq->where('user_id', $user->id));
            })
            ->latest('discovered_at')
            ->paginate(15)
            ->withQueryString();

        $counts = [
            'all'      => Finding::count(),
            'critical' => Finding::where('severity', 'critical')->count(),
            'high'     => Finding::where('severity', 'high')->count(),
            'medium'   => Finding::where('severity', 'medium')->count(),
            'low'      => Finding::where('severity', 'low')->count(),
            'info'     => Finding::where('severity', 'info')->count(),
        ];

        return view('findings.index', compact('findings', 'counts'));
    }

    public function show(Finding $finding)
    {
        $this->authorizeFinding($finding);

        $finding->load(['scan.project', 'project', 'target', 'remediationScripts']);

        return view('findings.show', compact('finding'));
    }

    public function generateRemediation(Finding $finding)
    {
        $this->authorizeFinding($finding);
        abort_unless(in_array($finding->severity, [Finding::SEVERITY_HIGH, Finding::SEVERITY_CRITICAL]),
            422, 'Remediation scripts can only be generated for high or critical findings.');

        try {
            $gateway = config('services.api_gateway.url', 'http://api-gateway:8080');
            $res = Http::timeout(60)->post($gateway.'/api/ai/remediation', [
                'finding_id' => $finding->id,
                'title'      => $finding->title,
                'severity'   => $finding->severity,
                'description'=> $finding->description,
                'evidence'   => $finding->evidence,
                'endpoint'   => $finding->endpoint,
                'source_tool'=> $finding->source_tool,
            ]);

            if ($res->ok()) {
                $data = $res->json();
                foreach (($data['scripts'] ?? []) as $script) {
                    RemediationScript::create([
                        'finding_id'  => $finding->id,
                        'user_id'     => request()->user()->id,
                        'title'       => $script['title'] ?? 'Remediation script',
                        'language'    => $script['language'] ?? 'bash',
                        'code'        => $script['code'] ?? '',
                        'explanation' => $script['explanation'] ?? null,
                        'status'      => RemediationScript::STATUS_GENERATED,
                    ]);
                }
                return back()->with('success', 'Remediation scripts generated.');
            }

            return back()->with('error', 'AI service returned: '.$res->status());
        } catch (\Throwable $e) {
            return back()->with('error', 'AI service unreachable: '.$e->getMessage());
        }
    }

    public function downloadScript(RemediationScript $script)
    {
        $this->authorizeScript($script);

        $extensions = [
            'bash' => 'sh', 'ansible' => 'yml', 'dockerfile' => 'Dockerfile',
            'terraform' => 'tf', 'kubernetes' => 'yaml', 'python' => 'py',
        ];
        $ext = $extensions[$script->language] ?? 'txt';
        $filename = Str::slug($script->title).'-'.$script->id.'.'.$ext;

        return response()->streamDownload(function () use ($script) {
            echo $script->code;
        }, $filename, ['Content-Type' => 'text/plain']);
    }

    public function verifyScript(RemediationScript $script)
    {
        $this->authorizeScript($script);
        $script->update([
            'status'          => RemediationScript::STATUS_VERIFIED,
            'verified_at'     => now(),
            'verification_log'=> 'Marked as verified by '.request()->user()->name,
        ]);
        return back()->with('success', 'Script marked as verified.');
    }

    public function applyScript(RemediationScript $script)
    {
        $this->authorizeScript($script);
        $script->update(['status' => RemediationScript::STATUS_APPLIED]);
        return back()->with('success', 'Script marked as applied.');
    }

    private function authorizeFinding(Finding $finding): void
    {
        $user = request()->user();
        if ($user->isAdmin() || $user->isAuditor()) {
            return;
        }
        abort_unless($finding->project && $finding->project->user_id === $user->id, 403);
    }

    private function authorizeScript(RemediationScript $script): void
    {
        $user = request()->user();
        if ($user->isAdmin() || $user->isAuditor()) {
            return;
        }
        abort_unless($script->finding && $script->finding->project && $script->finding->project->user_id === $user->id, 403);
    }
}
