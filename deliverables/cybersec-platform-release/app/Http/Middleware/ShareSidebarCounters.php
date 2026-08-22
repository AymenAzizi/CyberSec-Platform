<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Models\SecurityAlert;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares sidebar-wide counters (unacknowledged alert count + recent alerts)
 * and a default-project reference (so the "Knowledge Graph" nav link always
 * resolves to a real route) with every authenticated view.
 */
class ShareSidebarCounters
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $projectScope = fn (Builder $q) => $q->when(
                ! $user->isAdmin() && ! $user->isAuditor(),
                fn (Builder $sq) => $sq->where('user_id', $user->id),
            );

            $unacknowledgedAlerts = SecurityAlert::where('acknowledged', false)
                ->whereHas('project', $projectScope)
                ->count();

            $recentAlerts = SecurityAlert::where('acknowledged', false)
                ->whereHas('project', $projectScope)
                ->with('project')
                ->latest()
                ->limit(5)
                ->get();

            // Default project for the "Knowledge Graph" sidebar entry —
            // picks the user's latest project (or any project for admins/
            // auditors). Null when no project exists yet, in which case
            // the sidebar links to the project-create page instead.
            $defaultProject = Project::query()
                ->when(! $user->isAdmin() && ! $user->isAuditor(),
                    fn (Builder $q) => $q->where('user_id', $user->id),
                )
                ->latest()
                ->first();

            View::share('unacknowledgedAlerts', $unacknowledgedAlerts);
            View::share('recentAlerts', $recentAlerts);
            View::share('defaultProject', $defaultProject);
        }

        return $next($request);
    }
}

