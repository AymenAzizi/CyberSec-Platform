<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\Scan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $reports = Report::query()
            ->with(['project', 'scan'])
            ->when($request->input('severity'), function (Builder $q, $sev) {
                $q->whereHas('scan.findings', fn (Builder $fq) => $fq->where('severity', $sev));
            })
            ->when(! $user->isAdmin() && ! $user->isAuditor(),
                fn (Builder $q) => $q->whereHas('project', fn (Builder $sq) => $sq->where('user_id', $user->id)),
            )
            ->latest('generated_at')
            ->paginate(15);

        return view('reports.index', compact('reports'));
    }

    public function show(Report $report)
    {
        $this->authorizeReport($report);

        $report->load(['project', 'scan.findings.remediationScripts', 'scan.project']);

        return view('reports.show', compact('report'));
    }

    public function pdf(Report $report)
    {
        $this->authorizeReport($report);

        $report->load(['project', 'scan.findings.remediationScripts']);

        return view('reports.pdf', compact('report'));
    }

    public function export(Report $report, string $format)
    {
        $this->authorizeReport($report);

        $report->load(['project', 'scan.findings.remediationScripts']);
        $payload = [
            'title'         => $report->title,
            'project'       => $report->project?->name,
            'generated_at'  => $report->generated_at?->toIso8601String(),
            'summary'       => $report->executive_summary,
            'findings'      => $report->scan?->findings?->map(fn ($f) => [
                'id'          => $f->id,
                'title'       => $f->title,
                'severity'    => $f->severity,
                'cvss_score'  => $f->cvss_score,
                'cve_id'      => $f->cve_id,
                'endpoint'    => $f->endpoint,
                'source_tool' => $f->source_tool,
                'evidence'    => $f->evidence,
                'remediation' => $f->remediation,
                'remediation_scripts' => $f->remediationScripts->map->only(['title', 'language', 'code', 'explanation', 'status']),
            ]),
            'recommendations' => $report->recommendations,
            'ai_analysis'   => $report->ai_analysis ?? $report->scan?->config['ai_analysis'] ?? null,
            'remediation_scripts' => $report->scan?->findings?->flatMap->remediationScripts->map->only(['id', 'title', 'language', 'code', 'explanation', 'status']),
        ];

        if ($format === 'json') {
            return response()->json($payload, 200, [
                'Content-Disposition' => 'attachment; filename="report-'.$report->id.'.json"',
            ]);
        }

        if ($format === 'html') {
            $html = view('reports.pdf', compact('report'))->render();
            return response($html, 200, [
                'Content-Type'        => 'text/html',
                'Content-Disposition' => 'attachment; filename="report-'.$report->id.'.html"',
            ]);
        }

        // pdf — render the print view and let the browser/user's print-to-pdf
        return view('reports.pdf', compact('report'));
    }

    public function download(Report $report)
    {
        $this->authorizeReport($report);

        abort_unless($report->file_path, 404, 'No file attached to this report.');

        $disk = Storage::disk(config('filesystems.default', 'public'));
        abort_unless($disk->exists($report->file_path), 404, 'Report file is missing.');

        return $disk->download($report->file_path);
    }

    public function generate(Scan $scan)
    {
        $this->authorizeScan($scan);
        abort_unless($scan->status === Scan::STATUS_COMPLETED, 422, 'Scan must be completed first.');

        if ($scan->report) {
            return redirect()->route('reports.show', $scan->report);
        }

        $severityCounts = $scan->findings->groupBy('severity')->map->count()->all();

        $report = Report::create([
            'project_id'   => $scan->project_id,
            'scan_id'      => $scan->id,
            'title'        => "{$scan->type} — {$scan->target_url} ({$scan->started_at?->format('Y-m-d')})",
            'executive_summary' => "Scan {$scan->type} on {$scan->target_url} produced {$scan->findings->count()} findings.\n\nBreakdown: "
                .collect($severityCounts)->map(fn ($c, $s) => "{$c} {$s}")->implode(', ').'.',
            'technical_details' => [
                'scan'   => $scan->only(['type', 'target_url', 'profile', 'started_at', 'completed_at', 'duration']),
                'counts' => $severityCounts,
            ],
            'ai_analysis'  => $scan->config['ai_analysis'] ?? null,
            'recommendations' => $scan->findings->map(fn ($f) => [
                'priority' => $f->severity,
                'action'   => $f->remediation ?: "Remediate {$f->title}.",
            ])->all(),
            'format'       => Report::FORMAT_MD,
            'generated_at' => now(),
        ]);

        return redirect()->route('reports.show', $report)->with('success', 'Report generated.');
    }

    private function authorizeReport(Report $report): void
    {
        $user = request()->user();
        if ($user->isAdmin() || $user->isAuditor()) {
            return;
        }
        abort_unless($report->project && $report->project->user_id === $user->id, 403);
    }

    private function authorizeScan(Scan $scan): void
    {
        $user = request()->user();
        if ($user->isAdmin() || $user->isAuditor()) {
            return;
        }
        abort_unless($scan->user_id === $user->id || ($scan->project && $scan->project->user_id === $user->id), 403);
    }
}
