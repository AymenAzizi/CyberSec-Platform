<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Finding;
use App\Models\Project;
use App\Models\Scan;
use App\Models\SecurityAlert;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $projectScope = fn (Builder $q) => $q->when(
            ! $user->isAdmin() && ! $user->isAuditor(),
            fn (Builder $sq) => $sq->where('user_id', $user->id),
        );

        $projectsQuery = Project::query()->tap($projectScope);
        $scansQuery    = Scan::query()->whereHas('project', $projectScope);
        $findingsQuery = Finding::query()->whereHas('project', $projectScope);
        $alertsQuery   = SecurityAlert::query()->whereHas('project', $projectScope);

        // KPI counts (real, no hardcoded values)
        $kpis = [
            'projects'         => (clone $projectsQuery)->count(),
            'active_scans'     => (clone $scansQuery)->whereIn('status', [Scan::STATUS_QUEUED, Scan::STATUS_RUNNING])->count(),
            'completed_today'  => (clone $scansQuery)->whereDate('completed_at', Carbon::today())->count(),
            'critical'         => (clone $findingsQuery)->where('severity', Finding::SEVERITY_CRITICAL)->count(),
            'high'             => (clone $findingsQuery)->where('severity', Finding::SEVERITY_HIGH)->count(),
            'unack_alerts'     => (clone $alertsQuery)->where('acknowledged', false)->count(),
            'total_findings'   => (clone $findingsQuery)->count(),
        ];

        // Findings by severity (real percentages computed client-side)
        $severityChart = collect(['critical', 'high', 'medium', 'low', 'info'])
            ->map(fn (string $sev) => [
                'name'      => ucfirst($sev),
                'value'     => (clone $findingsQuery)->where('severity', $sev)->count(),
                'itemStyle' => ['color' => [
                    'critical' => '#ef4444', 'high' => '#f97316', 'medium' => '#f59e0b',
                    'low' => '#06b6d4', 'info' => '#6b7280',
                ][$sev]],
            ])
            ->values()
            ->all();

        // Scans by type — group real rows
        $typeRows = (clone $scansQuery)
            ->select('type', DB::raw('count(*) as cnt'))
            ->groupBy('type')
            ->orderByDesc('cnt')
            ->limit(10)
            ->get();
        $typeChart = $typeRows->map(fn ($r) => ['name' => $r->type, 'value' => (int) $r->cnt])->all();

        $recentScans = (clone $scansQuery)->with(['project', 'target'])
            ->latest()->limit(10)->get();

        $recentAlerts = (clone $alertsQuery)->where('acknowledged', false)
            ->with('project')->latest()->limit(5)->get();

        $topAssets = Asset::query()->whereHas('project', $projectScope)
            ->with('project')
            ->orderByDesc('risk_score')
            ->limit(5)
            ->get();

        return view('dashboard.index', [
            'kpis'           => $kpis,
            'severityChart'  => $severityChart,
            'typeChart'      => $typeChart,
            'recentScans'    => $recentScans,
            'recentAlerts'   => $recentAlerts,
            'topAssets'      => $topAssets,
        ]);
    }
}
