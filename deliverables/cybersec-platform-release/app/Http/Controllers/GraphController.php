<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Project;
use App\Services\GraphBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Knowledge-graph visualisation + impact-analysis controller.
 *
 * The graph is rendered client-side with Cytoscape.js; the controller
 * serves the raw `{nodes, edges}` JSON payload the library expects, plus
 * a per-asset impact-analysis endpoint that performs a server-side BFS
 * over the asset_relations table to compute the blast radius of a given
 * vulnerable asset.
 *
 * The route param name is `{project}` for project routes and `{asset}`
 * for the impact-analysis route — both match the controller's method
 * param names so Laravel's implicit route-model binding works correctly.
 */
class GraphController extends Controller
{
    public function show(Request $request, Project $project)
    {
        $this->authorizeProject($request->user(), $project);

        $assets = $project->assets()->orderByDesc('risk_score')->get();
        $topRisky = $assets->take(10);

        return view('graph.show', [
            'project' => $project,
            'assets' => $assets,
            'topRisky' => $topRisky,
            'graphDataUrl' => route('projects.graph.data', $project),
        ]);
    }

    public function data(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProject($request->user(), $project);

        $payload = app(GraphBuilder::class)->toCytoscape($project->id);

        return response()->json($payload);
    }

    public function impactAnalysis(Request $request, Asset $asset)
    {
        $this->authorizeProject($request->user(), $asset->project);

        $analysis = app(GraphBuilder::class)->impactPropagation($asset);

        return view('graph.impact', [
            'asset' => $asset,
            'affected' => $analysis['affected'],
            'seed' => $analysis['seed'],
            'project' => $asset->project,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function authorizeProject($user, Project $project): void
    {
        if ($user->isAdmin() || $user->isAuditor()) {
            return;
        }

        abort_unless((int) $project->user_id === (int) $user->id, 403, 'You do not have access to this project.');
    }
}
